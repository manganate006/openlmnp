<?php

namespace App\Console\Commands;

use App\Models\AiUsageLog;
use App\Models\User;
use App\Services\Ai\AiClient;
use App\Services\Ai\Analysis\DocumentFile;
use App\Services\Ai\Analysis\PdfTextFallback;
use App\Services\Ai\Exceptions\AiException;
use App\Services\Ai\Providers\AiProviders;
use App\Services\Ai\Providers\Capability;
use App\Support\AiAccess;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Auth;
use Throwable;

/**
 * Éprouve un fournisseur d'IA par de VRAIS appels.
 *
 * Le projet a une règle, payée une fois : « le catalogue OpenRouter ment sur
 * `structured_outputs` — valider tout nouveau modèle par un appel réel, un mock
 * ne prouve rien ». `anthropic/claude-sonnet-5`, annoncé compatible, encadrait
 * son JSON d'un bloc Markdown quand Bedrock servait la requête, et la cascade
 * d'extraction ne fonctionnait pas du tout.
 *
 * Cette commande est l'outil de cette règle. Elle rejoue, en petit, ce que
 * l'application fait en grand : vérifier la clé, tenir une conversation en
 * flux, obtenir un JSON contraint, et lire une pièce jointe. Un fournisseur qui
 * passe les quatre peut servir ; un qui échoue à la troisième ne peut pas
 * analyser de justificatifs, quoi qu'en dise son catalogue.
 *
 * ⚠️ MANUELLE, jamais en intégration continue : elle émet de vrais appels,
 * facturés. Compter quelques centimes par fournisseur.
 *
 * Ce qu'elle ne sait PAS voir : `AiClient::unwrapJson()` retire l'enveloppe
 * Markdown avant décodage, donc un modèle qui encadre sa réponse passera la
 * sonde — c'est d'ailleurs le comportement voulu en production. Ce qui reste
 * observable, et qui est rapporté, c'est le repli du schéma strict vers
 * `json_object`, lu dans le journal d'usage.
 */
class AiCheckProviderCommand extends Command
{
    protected $signature = 'ai:check-provider
        {--provider=* : Fournisseur(s) à éprouver ; « all » les prend tous (défaut : celui configuré)}
        {--user= : Email du compte dont la clé sert à la vérification}
        {--free-only : Ne faire que les contrôles gratuits, sans aucun appel facturé}
        {--force : Ne pas demander confirmation avant les appels facturés (scripts)}';

    protected $description = 'Éprouve un fournisseur d\'IA par de vrais appels (manuel, facturé, clé requise)';

    /** Nombre d'appels facturés par fournisseur : conversation, schéma, pièce jointe. */
    private const BILLED_PROBES = 3;

    /** Ce que la sonde de pièce jointe doit retrouver dans le PDF fabriqué. */
    private const PROBE_AMOUNT = '143,20';

    public function handle(): int
    {
        if (! config('ai.enabled')) {
            $this->error('AI_ENABLED est faux : activez les fonctions d\'IA avant de les éprouver.');

            return self::FAILURE;
        }

        $user = $this->resolveUser();

        if ($user === null) {
            return self::FAILURE;
        }

        // Les scopes d'isolation se désactivent hors session : on authentifie
        // explicitement, comme le fait le transport stdio du serveur MCP.
        Auth::setUser($user);

        $providers = $this->resolveProviders();

        if ($providers === []) {
            return self::FAILURE;
        }

        if (! $this->option('free-only') && ! $this->option('force')) {
            $this->info(sprintf(
                '%d fournisseur(s) × %d appels facturés = %d appels, quelques centimes en tout.',
                count($providers),
                self::BILLED_PROBES,
                count($providers) * self::BILLED_PROBES,
            ));

            // Refuser n'est pas un échec : on n'a simplement rien fait.
            if (! $this->confirm('Lancer ?', false)) {
                return self::SUCCESS;
            }
        }

        $echecs = 0;

        foreach ($providers as $cle) {
            $echecs += $this->checkProvider($user, $cle) ? 0 : 1;
        }

        return $this->renderVerdict(count($providers), $echecs);
    }

    /**
     * Éprouve un fournisseur, configuration restaurée quoi qu'il arrive.
     *
     * @return bool Toutes les étapes ont réussi.
     */
    private function checkProvider(User $user, string $cle): bool
    {
        $avant = [
            'ai.provider' => config('ai.provider'),
            'ai.base_url' => config('ai.base_url'),
            'ai.api_key' => config('ai.api_key'),
        ];

        // Éprouve-t-on le fournisseur RÉELLEMENT configuré, ou en essaie-t-on
        // un autre ? La distinction commande tout le reste.
        $configure = $cle === AiProviders::resolveKey();

        try {
            config(['ai.provider' => $cle]);

            // On ne vide l'adresse que pour un AUTRE fournisseur : sur celui
            // qui est configuré, une AI_BASE_URL explicite est un choix de
            // l'administrateur — passerelle, proxy, serveur local — et c'est
            // précisément elle qu'il veut éprouver.
            if (! $configure) {
                config(['ai.base_url' => '']);
            }

            return $this->runProbes($user, $cle, $configure);
        } finally {
            config($avant);
        }
    }

    private function runProbes(User $user, string $cle, bool $configure): bool
    {
        $this->newLine();

        try {
            $provider = AiProviders::resolve();
        } catch (Throwable $e) {
            $this->line("<fg=red>✗</> {$cle} — {$e->getMessage()}");

            return false;
        }

        $this->line("<fg=cyan>{$provider->label()}</> — " . $provider->baseUrl());
        $this->line('Capacités : ' . $this->describeCapabilities($provider));

        if (! $this->ensureKey($user, $configure)) {
            return false;
        }

        $client = AiClient::for($user->fresh());
        $etapes = [$this->probeKey($client)];

        if (! $this->option('free-only')) {
            $etapes[] = $this->probeChat($client);
            $etapes[] = $this->probeSchema($client, $user);
            $etapes[] = $this->probeAttachment($client, $provider);
        }

        $this->renderSteps($etapes);

        $cout = $this->totalCost($etapes);

        if (bccomp($cout, '0', 6) === 1) {
            $this->line("Coût de la vérification : {$cout} \$");
        }

        return ! collect($etapes)->contains(fn (array $e) => $e['ok'] === false);
    }

    /**
     * S'assure qu'une clé est résolue pour ce fournisseur.
     *
     * Sur le fournisseur CONFIGURÉ, l'absence de clé est un défaut de
     * configuration : on le dit, on ne le contourne pas par une question. Sur
     * un AUTRE fournisseur — qu'on éprouve justement parce qu'il n'est pas
     * encore en place — la demander est le seul moyen d'aller plus loin.
     *
     * ⚠️ Par `secret()`, jamais par une option : une clé passée en ligne de
     * commande atterrit dans l'historique du shell et dans `ps`.
     */
    private function ensureKey(User $user, bool $configure): bool
    {
        $access = AiAccess::for($user->fresh());

        if ($access->available()) {
            return true;
        }

        if ($configure || ! $this->input->isInteractive()) {
            $this->line('<fg=red>✗</> ' . ($access->unavailableMessage() ?? 'Aucune clé résolue pour ce fournisseur.'));

            return false;
        }

        // `secret()` masque la frappe ; la valeur ne vit que le temps du
        // processus — elle n'est jamais écrite en base ni dans le .env.
        $saisie = $this->secret('Clé d\'API pour ce fournisseur (saisie masquée, non enregistrée)');

        if (blank($saisie)) {
            $this->line('<fg=yellow>—</> Aucune clé fournie : fournisseur ignoré.');

            return false;
        }

        config(['ai.api_key' => trim($saisie)]);

        return true;
    }

    // =========================================================================
    // Sondes
    // =========================================================================

    /** @return array<string, mixed> */
    private function probeKey(AiClient $client): array
    {
        $status = $client->checkKey();

        return $this->step(
            'Clé acceptée',
            $status->valid,
            $status->summary(),
            $status->valid ? null : 'aucune des sondes suivantes ne peut aboutir',
        );
    }

    /** @return array<string, mixed> */
    private function probeChat(AiClient $client): array
    {
        try {
            // En flux, parce que c'est ainsi que l'assistant sert réellement :
            // une complétion classique ne prouverait pas que le SSE se décode.
            $result = $client->stream(
                'chat',
                [['role' => 'user', 'content' => 'Réponds exactement : OK']],
                onDelta: fn () => null,
                options: ['max_tokens' => 32],
            );
        } catch (AiException $e) {
            return $this->step('Assistant', false, $e->getMessage(), 'l\'assistant ne peut pas répondre');
        }

        $texte = trim((string) $result->content);

        return $this->step(
            'Assistant',
            $texte !== '',
            $texte === '' ? 'réponse vide' : "répond en {$result->durationMs} ms",
            $texte === '' ? 'l\'assistant ne peut pas répondre' : null,
            $result->costUsd,
        );
    }

    /**
     * LA sonde qui justifie cette commande : le schéma est-il honoré ?
     *
     * @return array<string, mixed>
     */
    private function probeSchema(AiClient $client, User $user): array
    {
        $schema = ['name' => 'sonde', 'schema' => [
            'type' => 'object',
            'properties' => [
                'montant' => ['type' => 'string'],
                'date' => ['type' => 'string'],
            ],
            'required' => ['montant', 'date'],
        ]];

        try {
            $result = $client->completeJson(
                'documents',
                [
                    ['role' => 'system', 'content' => 'Tu extrais des données de justificatifs.'],
                    ['role' => 'user', 'content' => 'Facture EDF du 4 février 2026, total TTC ' . self::PROBE_AMOUNT . ' €.'],
                ],
                $schema,
                options: ['max_tokens' => 200],
            );
        } catch (AiException $e) {
            return $this->step(
                'Schéma honoré',
                false,
                $e->getMessage(),
                'ce fournisseur ne peut pas servir l\'extraction de justificatifs',
            );
        }

        $contenu = is_array($result->content) ? $result->content : [];
        $complet = isset($contenu['montant'], $contenu['date']);

        // Le repli vers `json_object` se lit dans le journal : c'est le signe
        // que le schéma STRICT a été refusé, même si la réponse est finalement
        // exploitable. Un avertissement, pas un échec.
        $repli = $this->lastMetaFlag($user, 'response_format') === 'json_object';

        return $this->step(
            'Schéma honoré',
            $complet,
            match (true) {
                ! $complet => 'champs attendus absents de la réponse',
                $repli => 'JSON conforme, mais après repli sur json_object (schéma strict refusé)',
                default => 'JSON conforme du premier coup',
            },
            $complet ? null : 'ce fournisseur ne peut pas servir l\'extraction de justificatifs',
            $result->costUsd,
            avertissement: $complet && $repli,
        );
    }

    /**
     * Pièce jointe : soit le fournisseur lit un PDF, soit c'est notre repli
     * texte qui doit s'être déclenché. Les deux chemins sont légitimes, et
     * c'est la capacité déclarée qui dit lequel devait s'appliquer.
     *
     * @return array<string, mixed>
     */
    private function probeAttachment(AiClient $client, \App\Services\Ai\Providers\AiProvider $provider): array
    {
        $document = DocumentFile::fromContents($this->probePdf(), 'sonde.pdf');
        $natif = $provider->supports(Capability::PdfUpload);

        try {
            $part = $natif
                ? $document->messagePart()
                : PdfTextFallback::messagePart(PdfTextFallback::extract($document));
        } catch (Throwable $e) {
            return $this->step('Pièce jointe', false, $e->getMessage(), 'les PDF ne seront pas lisibles');
        }

        try {
            $result = $client->completeJson(
                'documents',
                [
                    ['role' => 'system', 'content' => 'Tu extrais des données de justificatifs.'],
                    ['role' => 'user', 'content' => [$part]],
                    ['role' => 'user', 'content' => 'Quel est le total TTC ? Réponds au format demandé.'],
                ],
                ['name' => 'sonde', 'schema' => [
                    'type' => 'object',
                    'properties' => ['montant' => ['type' => 'string']],
                    'required' => ['montant'],
                ]],
                options: ['max_tokens' => 200],
            );
        } catch (AiException $e) {
            return $this->step('Pièce jointe', false, $e->getMessage(), 'les PDF ne seront pas lisibles');
        }

        $lu = (string) (is_array($result->content) ? ($result->content['montant'] ?? '') : '');
        $trouve = str_contains(str_replace(['.', ' '], [',', ''], $lu), self::PROBE_AMOUNT);
        $voie = $natif ? 'PDF lu nativement' : 'couche texte extraite (repli)';

        return $this->step(
            'Pièce jointe',
            $trouve,
            $trouve ? "{$voie} — montant retrouvé" : "{$voie} — montant non retrouvé (lu : « {$lu} »)",
            $trouve ? null : 'les justificatifs PDF risquent d\'être mal lus',
            $result->costUsd,
        );
    }

    // =========================================================================
    // Utilitaires
    // =========================================================================

    /**
     * PDF minimal PORTEUR D'UNE COUCHE TEXTE, non compressé.
     *
     * Fabriqué ici plutôt que stocké : la sonde doit rester exécutable sur une
     * installation nue, sans fixture ni corpus.
     */
    private function probePdf(): string
    {
        $flux = 'BT /F1 12 Tf 72 720 Td (Facture EDF - Total TTC ' . self::PROBE_AMOUNT . ' EUR) Tj ET';

        $objets = [
            "1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n",
            "2 0 obj\n<< /Type /Pages /Kids [3 0 R] /Count 1 >>\nendobj\n",
            "3 0 obj\n<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Contents 4 0 R "
                . "/Resources << /Font << /F1 5 0 R >> >> >>\nendobj\n",
            "4 0 obj\n<< /Length " . strlen($flux) . " >>\nstream\n{$flux}\nendstream\nendobj\n",
            "5 0 obj\n<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>\nendobj\n",
        ];

        $pdf = "%PDF-1.4\n";
        $offsets = [];

        foreach ($objets as $objet) {
            $offsets[] = strlen($pdf);
            $pdf .= $objet;
        }

        $debutXref = strlen($pdf);
        $pdf .= 'xref' . "\n0 " . (count($objets) + 1) . "\n0000000000 65535 f \n";

        foreach ($offsets as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }

        return $pdf . 'trailer' . "\n<< /Size " . (count($objets) + 1) . " /Root 1 0 R >>\n"
            . "startxref\n{$debutXref}\n%%EOF";
    }

    /** Dernier marqueur `meta` écrit par le client pour ce compte. */
    private function lastMetaFlag(User $user, string $cle): mixed
    {
        $ligne = AiUsageLog::withoutGlobalScopes()
            ->where('user_id', $user->id)
            ->latest('id')
            ->first();

        return is_array($ligne?->meta) ? ($ligne->meta[$cle] ?? null) : null;
    }

    /** @return array<string, mixed> */
    private function step(
        string $libelle,
        bool $ok,
        string $detail,
        ?string $consequence = null,
        ?string $cout = null,
        bool $avertissement = false,
    ): array {
        return compact('libelle', 'ok', 'detail', 'consequence', 'cout', 'avertissement');
    }

    /**
     * Rend les étapes sous forme de tableau.
     *
     * ⚠️ Pas d'alignement à la main : la sortie console passe par Termwind, qui
     * **réduit les espaces consécutifs** comme le ferait du HTML. Un `str_pad()`
     * ou une indentation par espaces y disparaît, et un `str_pad()` sur du
     * texte accentué compterait de toute façon des octets, pas des caractères.
     *
     * @param  array<int, array<string, mixed>>  $etapes
     */
    private function renderSteps(array $etapes): void
    {
        $lignes = [];

        foreach ($etapes as $etape) {
            [$signe, $couleur] = match (true) {
                ! $etape['ok'] => ['✗', 'red'],
                $etape['avertissement'] => ['!', 'yellow'],
                default => ['✓', 'green'],
            };

            $lignes[] = ["<fg={$couleur}>{$signe}</>", $etape['libelle'], $etape['detail']];
        }

        $this->table(['', 'Étape', 'Résultat'], $lignes);

        // La conséquence sort du tableau : c'est elle qui dit si le fournisseur
        // peut servir, et une cellule la replierait sur deux lignes au milieu
        // du reste.
        foreach ($etapes as $etape) {
            if (filled($etape['consequence'])) {
                $this->line("<fg=red>{$etape['libelle']} : {$etape['consequence']}.</>");
            }
        }
    }

    /** @param array<int, array<string, mixed>> $etapes */
    private function totalCost(array $etapes): string
    {
        $total = '0';

        foreach ($etapes as $etape) {
            if (filled($etape['cout'])) {
                $total = bcadd($total, (string) $etape['cout'], 6);
            }
        }

        return $total;
    }

    private function describeCapabilities(\App\Services\Ai\Providers\AiProvider $provider): string
    {
        $libelles = [
            'PDF' => Capability::PdfUpload,
            'annotations' => Capability::FileAnnotations,
            'coût réel' => Capability::ReportedCost,
            'introspection de clé' => Capability::KeyIntrospection,
        ];

        return collect($libelles)
            ->map(fn (Capability $c, string $nom) => $nom . ' ' . ($provider->supports($c) ? 'oui' : 'non'))
            ->implode(' · ');
    }

    /** @return array<int, string> */
    private function resolveProviders(): array
    {
        $demandes = (array) $this->option('provider');

        if ($demandes === []) {
            return [AiProviders::resolveKey()];
        }

        $connus = array_keys((array) config('ai.providers', []));

        if (in_array('all', $demandes, strict: true)) {
            return $connus;
        }

        $inconnus = array_diff($demandes, $connus);

        if ($inconnus !== []) {
            $this->error('Fournisseur inconnu : ' . implode(', ', $inconnus));
            $this->line('Connus : ' . implode(', ', $connus));

            return [];
        }

        return array_values($demandes);
    }

    private function resolveUser(): ?User
    {
        $email = $this->option('user');

        if (filled($email)) {
            $user = User::where('email', $email)->first();

            if ($user === null) {
                $this->error("Aucun compte pour {$email}.");
            }

            return $user;
        }

        $user = User::where('is_demo', false)
            ->where('email', '!=', config('demo.email'))
            ->first();

        if ($user === null) {
            $this->error('Aucun compte réel sur cette instance : précisez --user.');
        }

        return $user;
    }

    private function renderVerdict(int $total, int $echecs): int
    {
        $this->newLine();

        if ($echecs === 0) {
            $this->info($total === 1
                ? 'Ce fournisseur peut servir.'
                : "Les {$total} fournisseurs peuvent servir.");

            return self::SUCCESS;
        }

        $this->error("{$echecs} fournisseur(s) sur {$total} ne peuvent pas servir en l'état.");
        $this->line('Retirer son entrée de config/ai.php est une modification de configuration,');
        $this->line('pas de code : la couche fournisseur reste intacte.');

        return self::FAILURE;
    }
}
