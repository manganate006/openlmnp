<?php

namespace App\Filament\Pages;

use App\Filament\Pages\Concerns\RequiresAdmin;
use App\Models\AiUsageLog;
use App\Models\User;
use App\Services\Ai\Providers\Capability;
use App\Services\Ai\Providers\ModelCatalog;
use App\Support\AiAccess;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use UnitEnum;

/**
 * Supervision des fonctions d'IA — miroir d'AdminMcp.
 *
 * ⚠️ `AiUsageLog` porte un global scope par utilisateur : toute agrégation ici
 * passe par `withoutGlobalScopes()`, sans quoi un administrateur ne verrait que
 * sa propre consommation et croirait l'instance inactive.
 *
 * Les coûts sont cumulés en bcmath : `cost_usd` est une chaîne (`decimal:6`),
 * et additionner des centaines de lignes en float dériverait.
 */
class AdminAi extends Page
{
    use RequiresAdmin;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSparkles;
    protected static string|UnitEnum|null $navigationGroup = 'Administration';
    protected static ?string $navigationLabel = 'Intelligence artificielle';
    protected static ?string $title = 'Supervision de l\'intelligence artificielle';
    protected static ?int $navigationSort = 6;
    protected string $view = 'filament.pages.admin-ai';

    /** Fonctions rattachées à l'analyse de justificatifs. */
    private const DOCUMENT_FEATURES = ['documents', 'documents_auto'];

    public static function canAccess(): bool
    {
        return Auth::check()
            && Auth::user()->isAdmin()
            && config('ai.enabled', false);
    }

    public function getSubheading(): ?string
    {
        return 'Volumes, coûts réels et erreurs de toutes les fonctions d\'IA. '
            . 'Aucun contenu de document n\'est jamais journalisé : uniquement des compteurs.';
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('purgeOldLogs')
                ->label('Purger les anciens journaux')
                ->icon('heroicon-o-trash')
                ->color('danger')
                ->requiresConfirmation()
                ->modalDescription('Supprimer les appels d\'IA de plus de ' . config('ai.usage_retention_days', 90) . ' jours ?')
                ->action(function () {
                    $days = (int) config('ai.usage_retention_days', 90);

                    $deleted = $this->journal()
                        ->where('created_at', '<', now()->subDays($days))
                        ->delete();

                    Notification::make()
                        ->title("{$deleted} ligne(s) supprimée(s)")
                        ->success()
                        ->send();

                    $this->redirect(static::getUrl());
                }),
        ];
    }

    /** Requête de base : hors scope utilisateur, sinon l'admin ne voit que lui. */
    private function journal(): Builder
    {
        return AiUsageLog::withoutGlobalScopes();
    }

    public function getStats(): array
    {
        $moisCourant = $this->journal()->where('created_at', '>=', now()->startOfMonth());

        return [
            'documents_month' => (clone $moisCourant)
                ->where('feature', 'documents')->where('status', 'ok')->count(),
            'chat_month' => (clone $moisCourant)
                ->where('feature', 'chat')->where('status', 'ok')->count(),
            'calls_today' => $this->journal()->whereDate('created_at', today())->count(),
            'calls_month' => (clone $moisCourant)->count(),
            'errors_month' => (clone $moisCourant)->where('status', 'error')->count(),
            // Refus applicatifs : quota atteint, ou garde-fou de l'assistant.
            'quota_month' => (clone $moisCourant)->where('status', 'quota')->count(),
            'refused_month' => (clone $moisCourant)->where('status', 'refused')->count(),
            'cost_month' => $this->sumCost((clone $moisCourant)),
            'cost_total' => $this->sumCost($this->journal()),
            'cost_per_document' => $this->costPerDocument(),
            'avg_duration_ms' => $this->roundedAverage((clone $moisCourant)),
            'active_users' => (clone $moisCourant)->distinct('user_id')->count('user_id'),
        ];
    }

    /**
     * Usage de l'assistant conversationnel sur le mois en cours : questions, refus
     * (garde-fous), quotas, erreurs, outils consultés, pouces, coût cumulé en bcmath.
     *
     * Les lignes `chat_tool` mélangent exécutions d'outils (`meta.tool`) et tours
     * supplémentaires du modèle (`meta.round`) : seules les premières comptent ici.
     */
    public function getAssistantStats(): array
    {
        $mois = $this->journal()->where('created_at', '>=', now()->startOfMonth());

        $parStatut = (clone $mois)
            ->where('feature', 'chat')
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status')
            ->map(fn ($total) => (int) $total)
            ->toArray();

        $questions = $parStatut['ok'] ?? 0;
        $refus = $parStatut['refused'] ?? 0;

        $pouces = (clone $mois)
            ->where('feature', 'chat')
            ->whereNotNull('meta')
            ->pluck('meta')
            ->map(fn ($meta) => is_array($meta) ? ($meta['feedback'] ?? null) : null)
            ->filter()
            ->countBy()
            ->toArray();

        return [
            'questions' => $questions,
            'refused' => $refus,
            'refused_share' => $questions > 0 ? (int) round($refus / $questions * 100) : 0,
            'quota_hits' => $parStatut['quota'] ?? 0,
            'errors' => $parStatut['error'] ?? 0,
            'tool_calls' => (clone $mois)
                ->where('feature', 'chat_tool')
                ->where('meta', 'like', '%"tool"%')
                ->count(),
            'thumbs_up' => (int) ($pouces['up'] ?? 0),
            'thumbs_down' => (int) ($pouces['down'] ?? 0),
            'cost_usd' => $this->sumCost((clone $mois)->whereIn('feature', ['chat', 'chat_tool'])),
        ];
    }

    /** Coût moyen d'un document analysé, cascade comprise. */
    private function costPerDocument(): ?string
    {
        $mois = $this->journal()->where('created_at', '>=', now()->startOfMonth());

        $documents = (clone $mois)->where('feature', 'documents')->where('status', 'ok')->count();

        if ($documents === 0) {
            return null;
        }

        // Les appels de suivi automatiques comptent dans le coût, jamais dans
        // le quota : c'est bien le coût COMPLET d'un document qu'on veut ici.
        $cout = $this->sumCost((clone $mois)->whereIn('feature', self::DOCUMENT_FEATURES));

        return bcdiv($cout, (string) $documents, 4);
    }

    /** Somme en bcmath : cost_usd est une chaîne décimale, jamais un float. */
    private function sumCost(Builder $query): string
    {
        $total = '0';

        foreach ($query->whereNotNull('cost_usd')->pluck('cost_usd') as $cost) {
            $total = bcadd($total, (string) $cost, 6);
        }

        return $total;
    }

    private function roundedAverage(Builder $query): ?int
    {
        $average = $query->where('status', 'ok')->avg('duration_ms');

        return $average === null ? null : (int) round($average);
    }

    /** Volume et coût par jour sur 30 jours. */
    public function getDailyVolumes(): array
    {
        return $this->journal()
            ->where('created_at', '>=', now()->subDays(30)->startOfDay())
            ->selectRaw("strftime('%Y-%m-%d', created_at) as jour, count(*) as total")
            ->groupBy('jour')
            ->orderBy('jour')
            ->get()
            ->map(fn ($row) => ['jour' => $row->jour, 'total' => (int) $row->total])
            ->toArray();
    }

    /** Répartition par fonction et par modèle. */
    public function getByModel(): array
    {
        // `upstream_provider` (méta posée par AiClient) = le fournisseur AMONT qui a
        // réellement servi l'appel chez OpenRouter : le routage devient observable.
        return $this->journal()
            ->selectRaw("feature, model, json_extract(meta, '$.upstream_provider') as upstream, count(*) as total, avg(duration_ms) as avg_ms")
            ->groupBy('feature', 'model', 'upstream')
            ->orderByDesc('total')
            ->get()
            ->map(fn ($row) => [
                'feature' => $row->feature,
                'model' => $row->model ?? '—',
                'upstream' => $row->upstream ?? '—',
                'total' => (int) $row->total,
                'avg_ms' => $row->avg_ms ? (int) round($row->avg_ms) : null,
            ])
            ->toArray();
    }

    /** Erreurs du mois, par code — jamais le message brut du fournisseur. */
    public function getErrors(): array
    {
        return $this->journal()
            ->where('created_at', '>=', now()->startOfMonth())
            ->whereNotNull('error_code')
            ->selectRaw('error_code, count(*) as total, max(created_at) as derniere')
            ->groupBy('error_code')
            ->orderByDesc('total')
            ->get()
            ->map(fn ($row) => [
                'error_code' => $row->error_code,
                'total' => (int) $row->total,
                'derniere' => \Carbon\Carbon::parse($row->derniere)->diffForHumans(),
            ])
            ->toArray();
    }

    /** Utilisateurs les plus consommateurs du mois. */
    public function getTopUsers(): array
    {
        $lignes = $this->journal()
            ->where('created_at', '>=', now()->startOfMonth())
            ->selectRaw('user_id, count(*) as total')
            ->groupBy('user_id')
            ->orderByDesc('total')
            ->limit(20)
            ->get();

        $utilisateurs = User::whereIn('id', $lignes->pluck('user_id'))->get()->keyBy('id');

        return $lignes->map(fn ($row) => [
            'name' => $utilisateurs[$row->user_id]->name ?? '—',
            'email' => $utilisateurs[$row->user_id]->email ?? '—',
            'total' => (int) $row->total,
            'documents' => $this->journal()
                ->where('user_id', $row->user_id)
                ->where('feature', 'documents')
                ->where('status', 'ok')
                ->where('created_at', '>=', now()->startOfMonth())
                ->count(),
            'cost' => $this->sumCost(
                $this->journal()
                    ->where('user_id', $row->user_id)
                    ->where('created_at', '>=', now()->startOfMonth())
            ),
        ])->toArray();
    }

    /**
     * Conversations transmises sur un pouce vers le bas, déjà masquées à l'écriture.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getChatReports(): array
    {
        return \App\Models\AiChatReport::latest('created_at')->limit(30)->get()
            ->map(fn (\App\Models\AiChatReport $report) => [
                'date' => $report->created_at?->timezone(Auth::user()->timezone ?: config('app.timezone'))->format('d/m/Y H:i'),
                'reason' => $report->reason ?? '—',
                'route' => $report->route ?? '—',
                'model' => $report->model ?? '—',
                'transcript' => $report->transcript,
            ])->toArray();
    }

    /** Identifiant de modèle précédé du drapeau de son éditeur. */
    private function withFlag(string $model, \App\Services\Ai\Providers\AiProvider $provider): string
    {
        return $model === '' ? '' : ModelCatalog::flagForModel($model, $provider) . ' ' . $model;
    }

    /**
     * Réglages EFFECTIFS, pour diagnostiquer sans ouvrir le .env.
     *
     * Effectifs, donc résolus : le fournisseur, son adresse et les slugs de
     * modèles peuvent venir des valeurs par défaut du fournisseur plutôt que
     * d'une variable d'environnement. Afficher la configuration brute
     * montrerait des cases vides là où l'application appelle bel et bien
     * quelque chose.
     */
    public function aiConfiguration(): array
    {
        /** @var User $user */
        $user = Auth::user();
        $access = AiAccess::for($user);
        $provider = $access->provider();

        return [
            'provider' => trim(ModelCatalog::flag(ModelCatalog::countryForProvider($provider))
                . ' ' . $provider->label()),
            'base_url' => $provider->baseUrl(),
            'key_source' => filled(config('ai.api_key'))
                ? 'Clé d\'instance'
                : (config('ai.allow_user_keys') ? 'Clé par utilisateur' : 'Aucune'),
            // Drapeau de l'ÉDITEUR du modèle — celui du service qui route la
            // requête est sur la ligne « Fournisseur », et les deux diffèrent
            // dès qu'on passe par un intermédiaire.
            'model_documents' => $this->withFlag($access->model('documents'), $provider),
            'model_fallback' => $this->withFlag($access->model('documents_fallback'), $provider) ?: '— (pas de cascade)',
            'model_chat' => $this->withFlag($access->model('chat'), $provider),
            // Le moteur PDF appartient au plugin d'OpenRouter : ailleurs, il
            // n'a simplement pas d'objet, et le dire évite de chercher pourquoi
            // il « ne fait rien ».
            'pdf_engine' => $provider->supports(Capability::FileParserPlugin)
                ? config('ai.pdf_engine')
                : '— (sans objet pour ce fournisseur)',
            'fallback_confidence' => (float) config('ai.fallback_confidence'),
            'reanalysis_threshold' => (float) config('ai.reanalysis_threshold'),
            'docs_quota' => (int) config('ai.docs_monthly_quota') ?: 'illimité',
            'chat_quota' => (int) config('ai.chat_daily_quota') ?: 'illimité',
            'retention_days' => (int) config('ai.usage_retention_days'),
        ];
    }
}
