<?php

namespace App\Services;

use App\Models\Expense;
use App\Models\FiscalYear;
use App\Models\Property;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Matérialisation des échéances d'une charge récurrente.
 *
 * `expenses.recurring_type` a longtemps été une simple métadonnée de saisie : aucun
 * calcul ne le lisait, et une charge « Mensuel » n'était donc décomptée qu'une fois
 * (issue #9). Plutôt que de faire lire la récurrence aux quinze agrégateurs — chacun
 * avec sa propre quote-part, son propre champ HT/TTC et ses propres arrondis — on crée
 * de VRAIES lignes `expenses`. Elles sont alors captées partout, sans toucher à un seul
 * calcul, et chacune peut porter son justificatif et son montant réel.
 *
 * Deux bornes non négociables :
 *   - l'exercice est l'année civile (`fiscal_years` ne porte qu'un `year`), donc la
 *     génération ne sort jamais de l'année de la charge d'origine ;
 *   - un exercice clôturé porte une déclaration déposée : on refuse d'y écrire, comme
 *     le fait `FiscalYearService::calculate()`.
 *
 * `yearly` ne génère rien : une charge annuelle n'a qu'une échéance dans l'exercice.
 */
class RecurringExpenseService
{
    /** Pas de génération, en mois, pour les seules récurrences qui en produisent. */
    private const STEP_MONTHS = [
        'monthly'   => 1,
        'quarterly' => 3,
    ];

    /** Garde-fou de boucle : l'année civile en borne déjà 11, mais on ne boucle jamais sans butée. */
    private const MAX_OCCURRENCES = 60;

    /**
     * Colonnes recopiées sur chaque échéance.
     *
     * `amount_ht` et `amount_tva` en sont volontairement absents : le hook
     * `Expense::booted()` les recalcule par `TvaHelper::fromTtc()`, seule source de
     * vérité TVA. Les recopier à la main la contournerait.
     */
    private const COPIED_COLUMNS = [
        'property_id',
        'category',
        'description',
        'amount',
        'tva_rate',
        'is_dedicated',
        'recurring_type',
        'notes',
    ];

    public static function isGeneratable(?string $recurringType): bool
    {
        return array_key_exists((string) $recurringType, self::STEP_MONTHS);
    }

    /**
     * Ramène une borne à sa journée, quelle que soit son heure.
     *
     * ⚠️ Ce n'est pas une précaution de style. Un `DatePicker` non natif de Filament
     * renvoie un état HORODATÉ (`2026-12-31 12:25:59`, l'heure du clic) même quand il
     * n'affiche qu'une date : sans cette normalisation, la borne du 31/12 tombait
     * au-delà d'un `maxDate` fixé à minuit et l'action refusait sa propre valeur par
     * défaut. Un test qui pose une date propre ne voit rien de ce comportement.
     */
    private function asDay(CarbonImmutable $date): CarbonImmutable
    {
        return $date->startOfDay();
    }

    /**
     * Dates des échéances qui SUIVENT $start, jusqu'à $until inclus.
     *
     * ⚠️ Chaque date est calculée depuis $start, jamais depuis la précédente : un pas
     * à pas dériverait sur les mois courts (31/01 → 28/02 → 28/03 au lieu du 31/03).
     * Et `addMonths()` déborde (31/01 + 1 mois = 03/03), d'où `addMonthsNoOverflow()`.
     *
     * @return list<CarbonImmutable>
     */
    public function occurrenceDates(CarbonImmutable $start, string $recurringType, CarbonImmutable $until): array
    {
        if (! self::isGeneratable($recurringType)) {
            return [];
        }

        $step = self::STEP_MONTHS[$recurringType];
        $dates = [];

        $limit = $this->asDay($until);

        for ($i = 1; $i <= self::MAX_OCCURRENCES; $i++) {
            $date = $this->asDay($start)->addMonthsNoOverflow($step * $i);

            if ($date->greaterThan($limit)) {
                break;
            }

            $dates[] = $date;
        }

        return $dates;
    }

    /**
     * Dernier jour de l'année civile de la charge : la borne par défaut de la génération.
     */
    public function defaultUntil(Expense $expense): CarbonImmutable
    {
        return CarbonImmutable::parse($expense->expense_date)->endOfYear()->startOfDay();
    }

    /**
     * Date proposée pour une COPIE de la charge.
     *
     * Avance d'une période selon la récurrence déclarée — c'est le cas d'usage de la
     * duplication : la taxe foncière de l'an prochain, la même charge au mois suivant.
     * Une charge « Ponctuel » n'a pas de période : sa date est reprise telle quelle,
     * à l'utilisateur de la corriger.
     *
     * ⚠️ Contrairement à la génération, la copie a le DROIT de sortir de l'année
     * civile : c'est même son intérêt principal pour une charge annuelle.
     */
    public function defaultCopyDate(Expense $expense): CarbonImmutable
    {
        $start = $this->asDay(CarbonImmutable::parse($expense->expense_date));
        $step = self::STEP_MONTHS[$expense->recurring_type] ?? ($expense->recurring_type === 'yearly' ? 12 : 0);

        return $step === 0 ? $start : $start->addMonthsNoOverflow($step);
    }

    /**
     * Première échéance à générer, ou null si cette récurrence n'en produit aucune.
     * Sert de borne basse au sélecteur de date de la modale.
     */
    public function firstOccurrence(Expense $expense): ?CarbonImmutable
    {
        $dates = $this->occurrenceDates(
            CarbonImmutable::parse($expense->expense_date),
            (string) $expense->recurring_type,
            $this->defaultUntil($expense),
        );

        return $dates[0] ?? null;
    }

    /**
     * Ce que la génération ferait : une entrée par échéance, marquée « déjà présente »
     * ou « à créer ». Sert l'aperçu de la modale autant que `generate()`.
     *
     * La clé d'unicité est (bien, catégorie, date) — SANS le montant : une échéance
     * dont l'utilisateur a corrigé le montant après coup ne doit pas être recréée.
     *
     * @return array{
     *     dates: list<array{date: CarbonImmutable, exists: bool}>,
     *     to_create: int,
     *     existing: int,
     *     total_cents: int
     * }
     */
    public function plan(Expense $expense, CarbonImmutable $until): array
    {
        $dates = $this->occurrenceDates(
            CarbonImmutable::parse($expense->expense_date),
            (string) $expense->recurring_type,
            $this->asDay($until),
        );

        if ($dates === []) {
            return ['dates' => [], 'to_create' => 0, 'existing' => 0, 'total_cents' => 0];
        }

        $taken = $this->existingDates($expense, $dates);

        $entries = [];
        $toCreate = 0;
        $existing = 0;

        foreach ($dates as $date) {
            $exists = in_array($date->toDateString(), $taken, true);
            $entries[] = ['date' => $date, 'exists' => $exists];
            $exists ? $existing++ : $toCreate++;
        }

        return [
            'dates'       => $entries,
            'to_create'   => $toCreate,
            'existing'    => $existing,
            'total_cents' => (int) bcmul((string) $expense->amount, (string) $toCreate, 0),
        ];
    }

    /**
     * Crée les échéances manquantes.
     *
     * @return array{created: int, skipped: int}
     *
     * @throws \RuntimeException si la récurrence n'en produit aucune, si la borne sort
     *                          de l'année civile de la charge, ou si l'exercice est clôturé
     */
    public function generate(Expense $expense, CarbonImmutable $until): array
    {
        $until = $this->asDay($until);
        $this->assertGeneratable($expense, $until);

        $plan = $this->plan($expense, $until);
        $created = 0;

        DB::transaction(function () use ($plan, $expense, &$created) {
            foreach ($plan['dates'] as $entry) {
                if ($entry['exists']) {
                    continue;
                }

                Expense::create(
                    collect(self::COPIED_COLUMNS)
                        ->mapWithKeys(fn (string $column) => [$column => $expense->{$column}])
                        ->put('expense_date', $entry['date']->toDateString())
                        ->all()
                );

                $created++;
            }
        });

        return ['created' => $created, 'skipped' => $plan['existing']];
    }

    /**
     * @throws \RuntimeException
     */
    public function assertGeneratable(Expense $expense, CarbonImmutable $until): void
    {
        $until = $this->asDay($until);

        if (! self::isGeneratable($expense->recurring_type)) {
            throw new \RuntimeException(
                'La récurrence « ' . (Expense::recurringLabels()[$expense->recurring_type] ?? $expense->recurring_type)
                . ' » ne produit pas d\'échéance à générer : saisissez chaque ligne avec son justificatif.'
            );
        }

        $start = CarbonImmutable::parse($expense->expense_date);

        if ($until->year !== $start->year || $until->lessThan($start)) {
            throw new \RuntimeException(
                'Les échéances ne peuvent être générées que jusqu\'au 31/12/' . $start->year
                . ' : un exercice est une année civile.'
            );
        }

        if ($this->fiscalYearIsClosed($expense, $start->year)) {
            throw new \RuntimeException(
                'L\'exercice ' . $start->year . ' est clôturé : il porte une déclaration déposée. '
                . 'Rouvrez-le avant de générer des échéances.'
            );
        }
    }

    /**
     * Dates déjà occupées par une charge du même bien et de la même catégorie.
     *
     * ⚠️ `expense_date` porte le cast `date`, donc Laravel la PERSISTE en `Y-m-d H:i:s`
     * (`2026-02-15 00:00:00`) : un `whereIn` sur des dates nues ne remonterait jamais rien
     * et la génération créerait des doublons en silence. On ramène la fenêtre puis on
     * compare en PHP — portable, là où un `date(expense_date)` serait propre à SQLite.
     *
     * `withoutGlobalScopes()` + filtre explicite : `BelongsToUserThroughPropertyScope` ne
     * filtre que si une session existe, le service doit valoir aussi en console.
     *
     * @param  list<CarbonImmutable>  $dates
     * @return list<string>  dates au format Y-m-d
     */
    private function existingDates(Expense $expense, array $dates): array
    {
        $first = $dates[0]->startOfDay();
        $last = $dates[count($dates) - 1]->endOfDay();

        return Expense::withoutGlobalScopes()
            ->where('property_id', $expense->property_id)
            ->where('category', $expense->category)
            ->whereBetween('expense_date', [$first->toDateTimeString(), $last->toDateTimeString()])
            ->pluck('expense_date')
            ->map(fn ($date) => CarbonImmutable::parse($date)->toDateString())
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Propriétaire de la charge, via son bien.
     *
     * Passe par le bien plutôt que par la session : c'est lui qui porte `user_id`, et
     * l'appelant peut être une console sans utilisateur authentifié.
     */
    public function ownerId(Expense $expense): ?int
    {
        $userId = Property::withoutGlobalScopes()
            ->whereKey($expense->property_id)
            ->value('user_id');

        return $userId === null ? null : (int) $userId;
    }

    private function fiscalYearIsClosed(Expense $expense, int $year): bool
    {
        $userId = $this->ownerId($expense);

        if ($userId === null) {
            return false;
        }

        return FiscalYear::withoutGlobalScopes()
            ->where('user_id', $userId)
            ->where('year', $year)
            ->where('status', FiscalYear::STATUS_CLOSED)
            ->exists();
    }
}
