<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Expense;
use App\Models\FiscalYear;
use App\Models\Income;
use App\Models\Loan;
use App\Models\McpAuditLog;
use App\Models\Property;
use App\Models\PropertyComponent;
use App\Models\User;
use App\Models\UserBadge;
use App\Services\OnboardingChecklistService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Signaux d'usage agrégés, en lecture seule, protégés par ProvisioningGuard
 * (donc 404 tant que PROVISION_TOKEN n'est pas configuré).
 *
 * Cette application est auto-hébergeable et ne doit dépendre d'aucun service
 * externe : elle EXPOSE des signaux, elle n'appelle personne. C'est l'appelant
 * qui vient les chercher. Sur une instance auto-hébergée, la route n'existe
 * tout simplement pas.
 *
 * Ne renvoie QUE des agrégats : jamais un montant, jamais une donnée fiscale,
 * jamais le détail d'un bien. De quoi savoir « où en est cet utilisateur »,
 * rien de plus.
 *
 * ⚠️ BelongsToUserScope ne filtre que si Auth::check() est vrai. Sans session
 * authentifiée — le cas ici — il ne filtre RIEN : chaque requête doit donc
 * porter son propre `where user_id`, explicitement.
 */
class LifecycleSignalsController extends Controller
{
    public function __construct(private readonly OnboardingChecklistService $onboarding) {}

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'emails' => ['required', 'array', 'max:200'],
            'emails.*' => ['required', 'email', 'max:255'],
            'year' => ['nullable', 'integer', 'min:2000', 'max:2100'],
        ]);

        $year = $data['year'] ?? (int) now()->subYear()->format('Y');

        $emails = array_map(static fn (string $e) => mb_strtolower(trim($e)), $data['emails']);

        $users = User::query()
            ->whereIn('email', $emails)
            // Les comptes de démonstration ne sont jamais des destinataires.
            ->where('is_demo', false)
            ->where('email', '!=', (string) config('demo.email'))
            ->get();

        return response()->json([
            'year' => $year,
            'signals' => $users->map(fn (User $user) => $this->signalsFor($user, $year))->values(),
        ]);
    }

    /** @return array<string, mixed> */
    private function signalsFor(User $user, int $year): array
    {
        $propertyIds = Property::query()->where('user_id', $user->id)->pluck('id');

        $fiscalYears = FiscalYear::query()->where('user_id', $user->id)->get();
        $currentYear = $fiscalYears->firstWhere('year', $year);

        return [
            'email' => $user->email,
            'created_at' => $user->created_at?->toIso8601String(),
            'suspended' => $user->suspended_at !== null,

            'onboarding_step' => $this->stepReached($user, $year),
            'onboarding_pct' => $this->onboarding->getProgress($user, $year),

            'properties_count' => $propertyIds->count(),
            'rental_types' => Property::query()->where('user_id', $user->id)
                ->pluck('rental_type')->unique()->values()->all(),

            'closed_fiscal_years' => $fiscalYears
                ->where('status', FiscalYear::STATUS_CLOSED)->count(),
            'has_tax_return' => $fiscalYears->contains(fn ($fy) => $fy->pdf_path !== null),
            'has_fec' => $fiscalYears->contains(fn ($fy) => $fy->fec_path !== null),
            'current_year_status' => $currentYear?->status,

            // Pour un logiciel de comptabilité, le bon signal d'activité n'est
            // pas la connexion mais la SAISIE. (users.last_login_at n'existe pas,
            // et SESSION_DRIVER=file vide le proxy sessions.last_activity.)
            'last_entry_at' => $this->lastEntryAt($propertyIds),

            'import_used' => $propertyIds->isNotEmpty() && Income::query()
                ->whereIn('property_id', $propertyIds)
                ->whereNotNull('reservation_ref')->exists(),
            'platforms' => $propertyIds->isEmpty() ? [] : Income::query()
                ->whereIn('property_id', $propertyIds)
                ->pluck('source')->unique()->filter()->values()->all(),

            'mcp_used' => McpAuditLog::query()->where('user_id', $user->id)->exists(),

            // Un emprunt enregistre : les interets sont deductibles, et leur
            // absence est l'oubli le plus couteux du regime reel.
            'has_loan' => $propertyIds->isNotEmpty() && Loan::query()
                ->whereIn('property_id', $propertyIds)->exists(),

            // Date de mise en location la plus ancienne : elle conditionne le
            // prorata de la premiere annee, et revele une anteriorite non
            // saisie (bien loue avant l'exercice le plus ancien du compte).
            'first_rental_start' => Property::query()->where('user_id', $user->id)
                ->whereNotNull('rental_start_date')
                ->min('rental_start_date'),

            // Categories de charges SANS aucune ligne sur l'exercice. Ce sont
            // des libelles, jamais des montants : de quoi dire « il manque la
            // taxe fonciere » sans rien reveler du dossier.
            'expense_categories_missing' => $this->missingExpenseCategories($propertyIds, $year),

            // user_badges est le seul journal de jalons DATÉ du produit : il rend
            // faisables des déclencheurs qu'on croirait exiger une migration.
            'badges' => UserBadge::query()
                ->where('user_badges.user_id', $user->id)
                ->join('badge_definitions', 'badge_definitions.id', '=', 'user_badges.badge_definition_id')
                ->whereNotNull('user_badges.unlocked_at')
                ->get(['badge_definitions.code', 'user_badges.unlocked_at', 'user_badges.fiscal_year'])
                ->map(fn ($badge) => [
                    'code' => $badge->code,
                    'unlocked_at' => $badge->unlocked_at?->toIso8601String(),
                    'fiscal_year' => $badge->fiscal_year,
                ])->values()->all(),
        ];
    }

    /**
     * Catégories de charges restées vides sur l'exercice.
     *
     * Renvoie des LIBELLÉS, pas des montants : l'appelant doit pouvoir écrire
     * « il manque la taxe foncière » sans jamais connaître un chiffre du
     * dossier. C'est la limite que s'impose tout cet endpoint.
     *
     * @return list<string>
     */
    private function missingExpenseCategories(mixed $propertyIds, int $year): array
    {
        if ($propertyIds->isEmpty()) {
            return [];
        }

        // ⚠️ `expense_date`, pas `date`. SQLite ne lève AUCUNE erreur sur un nom de colonne
        // inconnu entre guillemets : il le traite comme un littéral texte. `whereYear('date')`
        // compilait donc en `strftime('%Y', "date")`, qui rend NULL, et la requête rendait
        // ZÉRO ligne — sans exception, sans journal, sans test rouge.
        //
        // Mesuré sur la base de production : 8 catégories avec `expense_date`, 0 avec `date`.
        // Conséquence en chaîne : toutes les catégories étaient déclarées absentes, pour tout
        // le monde, et le scénario `SequenceCatalog.php:155` de la vitrine — qui se déclenche
        // quand la liste contient `property_tax` et compte au moins deux virgules — se serait
        // déclenché TOUJOURS. Un e-mail serait parti reprocher à chacun des charges qu'il a
        // pourtant saisies. Même famille que le `has_loan ?? false` corrigé le même jour :
        // un e-mail qui AFFIRME sur un signal faux.
        $presentes = Expense::query()
            ->whereIn('property_id', $propertyIds)
            ->whereYear('expense_date', $year)
            ->pluck('category')
            ->unique()
            ->filter()
            ->all();

        return array_values(array_diff(array_keys(Expense::categoryLabels()), $presentes));
    }

    /**
     * Rang de la dernière étape franchie (0 à 6), dérivé de la checklist
     * produit. La première étape non faite arrête le compte : on ne saute pas
     * une marche.
     */
    private function stepReached(User $user, int $year): int
    {
        $step = 0;

        foreach ($this->onboarding->getChecklist($user, $year) as $item) {
            if (empty($item['done'])) {
                break;
            }
            $step++;
        }

        return $step;
    }

    private function lastEntryAt(mixed $propertyIds): ?string
    {
        if ($propertyIds->isEmpty()) {
            return null;
        }

        $dates = [
            Income::query()->whereIn('property_id', $propertyIds)->max('created_at'),
            Expense::query()->whereIn('property_id', $propertyIds)->max('created_at'),
            PropertyComponent::query()->whereIn('property_id', $propertyIds)->max('created_at'),
        ];

        $latest = collect($dates)->filter()->max();

        return $latest ? Carbon::parse($latest)->toIso8601String() : null;
    }
}
