<?php

namespace App\Services;

use App\Models\Expense;
use App\Models\FiscalYear;
use App\Models\Furniture;
use App\Models\Income;
use App\Models\Property;
use App\Models\PropertyComponent;
use App\Models\PropertyWork;
use App\Models\User;

/**
 * Service de calcul du résultat fiscal LMNP.
 *
 * Tous les montants sont en centimes.
 * Règle de plafonnement : l'amortissement ne peut pas créer de déficit.
 *   amortissement_déduit = min(amortissement_total, recettes - charges)
 *   excédent reporté indéfiniment
 */
class FiscalYearService
{
    public function __construct(
        private DepreciationService $depreciationService,
        private AccountingEntryService $accountingEntryService,
    ) {}

    /**
     * Calcule et met à jour un exercice fiscal.
     *
     * @param  bool  $force  Forcer le recalcul même si l'exercice est clôturé (utilisé pour le recalcul en cascade)
     *
     * @throws \RuntimeException si l'exercice est clôturé et $force est false
     */
    public function calculate(FiscalYear $fiscalYear, bool $force = false): FiscalYear
    {
        if ($fiscalYear->status === FiscalYear::STATUS_CLOSED && ! $force) {
            throw new \RuntimeException(
                'L\'exercice ' . $fiscalYear->year . ' est clôturé. Rouvrez-le avant de le recalculer.'
            );
        }

        $user = $fiscalYear->user;
        $year = $fiscalYear->year;
        $properties = Property::withoutGlobalScopes()->where('user_id', $user->id)->get();

        // 1. Total des recettes (loyers nets = montant - commission plateforme)
        // Pour les biens TVA-liable, on utilise amount_ht (la TVA collectée est un pass-through)
        $totalIncome = '0';
        $totalTvaCollected = '0';
        foreach ($properties as $property) {
            $amountField = $property->isTvaLiable() ? 'amount_ht' : 'amount';
            $propertyIncome = $property->incomes()
                ->whereYear('income_date', $year)
                ->selectRaw("SUM({$amountField}) - SUM(platform_fee) as net_income")
                ->value('net_income');
            $totalIncome = bcadd($totalIncome, (string) ($propertyIncome ?? 0), 0);

            // TVA collectée
            if ($property->isTvaLiable()) {
                $tvaCollected = $property->incomes()
                    ->whereYear('income_date', $year)
                    ->sum('tva_collected');
                $totalTvaCollected = bcadd($totalTvaCollected, (string) $tvaCollected, 0);
            }
        }

        // 2. Total des charges (avec application quote-part)
        // Pour les biens TVA-liable, on utilise amount_ht (la TVA est récupérée séparément)
        $totalExpenses = '0';
        $totalTvaDeductible = '0';
        foreach ($properties as $property) {
            $amountField = $property->isTvaLiable() ? 'amount_ht' : 'amount';

            // Charges 100% dédiées
            $dedicated = $property->expenses()
                ->whereYear('expense_date', $year)
                ->where('is_dedicated', true)
                ->sum($amountField);
            $totalExpenses = bcadd($totalExpenses, (string) $dedicated, 0);

            // Charges au prorata
            $shared = $property->expenses()
                ->whereYear('expense_date', $year)
                ->where('is_dedicated', false)
                ->sum($amountField);
            $sharedProrata = bcmul((string) $shared, $property->quota_share, 0);
            $totalExpenses = bcadd($totalExpenses, $sharedProrata, 0);

            // TVA déductible sur charges
            if ($property->isTvaLiable()) {
                $dedicatedTva = $property->expenses()
                    ->whereYear('expense_date', $year)
                    ->where('is_dedicated', true)
                    ->sum('amount_tva');
                $totalTvaDeductible = bcadd($totalTvaDeductible, (string) $dedicatedTva, 0);

                $sharedTva = $property->expenses()
                    ->whereYear('expense_date', $year)
                    ->where('is_dedicated', false)
                    ->sum('amount_tva');
                $sharedTvaProrata = bcmul((string) $sharedTva, $property->quota_share, 0);
                $totalTvaDeductible = bcadd($totalTvaDeductible, $sharedTvaProrata, 0);

                // TVA déductible sur travaux
                $worksTva = $property->works()
                    ->sum('amount_tva');
                $totalTvaDeductible = bcadd($totalTvaDeductible, (string) $worksTva, 0);

                // TVA déductible sur mobilier
                $furnitureTva = $property->furniture()
                    ->sum('amount_tva');
                $totalTvaDeductible = bcadd($totalTvaDeductible, (string) $furnitureTva, 0);
            }

            // Intérêts d'emprunt (quote-part) — pas de TVA sur les intérêts
            foreach ($property->loans as $loan) {
                $yearlyInterest = $loan->payments()
                    ->whereYear('payment_date', $year)
                    ->sum('interest_amount');
                $interestProrata = bcmul((string) $yearlyInterest, $property->quota_share, 0);
                $totalExpenses = bcadd($totalExpenses, $interestProrata, 0);

                // Assurance emprunteur (quote-part)
                $yearlyInsurance = $loan->payments()
                    ->whereYear('payment_date', $year)
                    ->sum('insurance_amount');
                $insuranceProrata = bcmul((string) $yearlyInsurance, $property->quota_share, 0);
                $totalExpenses = bcadd($totalExpenses, $insuranceProrata, 0);
            }
        }

        // 3. Total des amortissements
        $totalDepreciation = '0';
        foreach ($properties as $property) {
            $depreciation = $this->depreciationService->calculateAnnualDepreciation($property, $year);
            $totalDepreciation = bcadd($totalDepreciation, $depreciation['total'], 0);
        }

        // 4. Report d'amortissements différés N-1
        $previousYear = FiscalYear::withoutGlobalScopes()
            ->where('user_id', $user->id)
            ->where('year', $year - 1)
            ->first();
        $carriedForward = (string) ($previousYear?->deferred_depreciation ?? 0);

        // 5. Plafonnement de l'amortissement
        // L'amortissement ne peut pas créer de déficit
        // Limite = recettes - charges (hors amortissements)
        $resultBeforeDepreciation = bcsub($totalIncome, $totalExpenses, 0);
        $totalAvailableDepreciation = bcadd($totalDepreciation, $carriedForward, 0);

        if (bccomp($resultBeforeDepreciation, '0', 0) <= 0) {
            // Résultat déjà négatif → aucun amortissement déduit, tout est différé
            $cappedDepreciation = '0';
            $deferredDepreciation = $totalAvailableDepreciation;
        } elseif (bccomp($totalAvailableDepreciation, $resultBeforeDepreciation, 0) <= 0) {
            // Amortissements < résultat → tout est déduit
            $cappedDepreciation = $totalAvailableDepreciation;
            $deferredDepreciation = '0';
        } else {
            // Plafonnement : on déduit seulement jusqu'à ramener le résultat à 0
            $cappedDepreciation = $resultBeforeDepreciation;
            $deferredDepreciation = bcsub($totalAvailableDepreciation, $cappedDepreciation, 0);
        }

        // 6. Résultat fiscal
        $fiscalResult = bcsub($resultBeforeDepreciation, $cappedDepreciation, 0);

        // 7. Calcul TVA
        $tvaBalance = bcsub($totalTvaCollected, $totalTvaDeductible, 0);

        // Mise à jour
        $fiscalYear->update([
            'total_income'                  => (int) $totalIncome,
            'total_expenses'                => (int) $totalExpenses,
            'total_depreciation'            => (int) $totalDepreciation,
            'capped_depreciation'           => (int) $cappedDepreciation,
            'deferred_depreciation'         => (int) $deferredDepreciation,
            'previous_deferred'             => (int) $carriedForward,
            'fiscal_result'                 => (int) $fiscalResult,
            'total_tva_collected'           => (int) $totalTvaCollected,
            'total_tva_deductible'          => (int) $totalTvaDeductible,
            'tva_balance'                   => (int) $tvaBalance,
        ]);

        // 7. Générer les écritures comptables (pour le FEC)
        $this->accountingEntryService->generateForFiscalYear($fiscalYear);

        // 8. Recalcul en cascade : si N+1 existe et que son previous_deferred est désynchronisé
        $this->cascadeRecalculate($fiscalYear);

        return $fiscalYear->refresh();
    }

    /**
     * Recalcule en cascade les exercices N+1, N+2... si leur report est désynchronisé.
     */
    private function cascadeRecalculate(FiscalYear $fiscalYear): void
    {
        $nextYear = FiscalYear::withoutGlobalScopes()
            ->where('user_id', $fiscalYear->user_id)
            ->where('year', $fiscalYear->year + 1)
            ->first();

        if (! $nextYear) {
            return;
        }

        // Vérifier si le report N+1 est désynchronisé
        if ((int) $nextYear->previous_deferred !== (int) $fiscalYear->deferred_depreciation) {
            // Recalcul de N+1 avec force=true (qui déclenchera récursivement N+2, etc.)
            $this->calculate($nextYear, force: true);
        }
    }

    /**
     * Crée ou récupère l'exercice fiscal pour une année et le calcule.
     */
    public function getOrCreate(User $user, int $year): FiscalYear
    {
        $fiscalYear = FiscalYear::withoutGlobalScopes()
            ->firstOrCreate(
                ['user_id' => $user->id, 'year' => $year],
                ['status' => FiscalYear::STATUS_DRAFT]
            );

        return $this->calculate($fiscalYear);
    }

    /**
     * Recalcule tous les exercices d'un utilisateur dans l'ordre chronologique.
     *
     * @return int Nombre d'exercices recalculés
     */
    public function recalculateChain(User $user): int
    {
        $fiscalYears = FiscalYear::withoutGlobalScopes()
            ->where('user_id', $user->id)
            ->orderBy('year')
            ->get();

        $count = 0;
        foreach ($fiscalYears as $fy) {
            $this->calculate($fy, force: true);
            $count++;
        }

        return $count;
    }

    /**
     * Première année de données de l'utilisateur : minimum entre la date
     * d'acquisition / de mise en location des biens et la première
     * recette ou charge saisie. Année courante à défaut de données.
     */
    public function firstDataYear(User $user): int
    {
        $propertyIds = Property::withoutGlobalScopes()
            ->where('user_id', $user->id)
            ->pluck('id');

        $dates = array_filter([
            Property::withoutGlobalScopes()->where('user_id', $user->id)->min('acquisition_date'),
            Property::withoutGlobalScopes()->where('user_id', $user->id)->min('rental_start_date'),
            Income::withoutGlobalScopes()->whereIn('property_id', $propertyIds)->min('income_date'),
            Expense::withoutGlobalScopes()->whereIn('property_id', $propertyIds)->min('expense_date'),
        ]);

        if ($dates === []) {
            return (int) date('Y');
        }

        return (int) substr(min($dates), 0, 4);
    }

    /**
     * Première année d'activité LMNP : minimum entre la mise en location
     * et la première recette ou charge saisie. C'est l'ancre de la chaîne
     * d'exercices (les amortissements ne démarrent qu'à l'activité,
     * contrairement à firstDataYear qui inclut l'année d'acquisition).
     */
    public function firstActivityYear(User $user): int
    {
        $propertyIds = Property::withoutGlobalScopes()
            ->where('user_id', $user->id)
            ->pluck('id');

        $dates = array_filter([
            Property::withoutGlobalScopes()->where('user_id', $user->id)->min('rental_start_date'),
            Income::withoutGlobalScopes()->whereIn('property_id', $propertyIds)->min('income_date'),
            Expense::withoutGlobalScopes()->whereIn('property_id', $propertyIds)->min('expense_date'),
        ]);

        if ($dates === []) {
            return $this->firstDataYear($user);
        }

        return (int) substr(min($dates), 0, 4);
    }

    /**
     * Message d'erreur si l'exercice $year ne peut pas être créé faute de
     * prédécesseur, null si la création est autorisée.
     *
     * Tout exercice antérieur ou égal à la première année d'activité peut
     * être créé sans N-1 : le report d'amortissements vaut alors 0.
     * Sans amortissement actif, la chaîne n'est pas indispensable non plus.
     */
    public function missingPreviousYearError(User $user, int $year): ?string
    {
        $previousExists = FiscalYear::withoutGlobalScopes()
            ->where('user_id', $user->id)
            ->where('year', $year - 1)
            ->exists();

        if ($previousExists || $year <= $this->firstActivityYear($user)) {
            return null;
        }

        $propertyIds = Property::withoutGlobalScopes()
            ->where('user_id', $user->id)
            ->pluck('id');

        $hasDepreciation = PropertyComponent::withoutGlobalScopes()->whereIn('property_id', $propertyIds)->exists()
            || PropertyWork::withoutGlobalScopes()->whereIn('property_id', $propertyIds)->exists()
            || Furniture::withoutGlobalScopes()->whereIn('property_id', $propertyIds)->exists();

        if (! $hasDepreciation) {
            return null;
        }

        return 'L\'exercice ' . ($year - 1) . ' n\'existe pas. Créez-le d\'abord pour reporter correctement les amortissements différés.';
    }

    /**
     * Année proposée par défaut dans l'assistant de clôture : premier
     * exercice manquant de la chaîne, entre le début de la chaîne
     * existante (à défaut la première année d'activité) et N-1.
     */
    public function nextYearToCreate(User $user): int
    {
        $currentYear = (int) date('Y');

        $chainStart = FiscalYear::withoutGlobalScopes()
            ->where('user_id', $user->id)
            ->min('year') ?? $this->firstActivityYear($user);

        for ($y = (int) $chainStart; $y < $currentYear; $y++) {
            $exists = FiscalYear::withoutGlobalScopes()
                ->where('user_id', $user->id)
                ->where('year', $y)
                ->exists();

            if (! $exists) {
                return $y;
            }
        }

        return $currentYear - 1;
    }

    /**
     * Comparaison micro-BIC vs régime réel pour une année.
     *
     * @return array{
     *     micro_bic_result: string,
     *     real_result: string,
     *     advantage: string,
     *     recommended: string
     * }
     */
    public function compareMicroBicVsReal(User $user, int $year, string $abatement = '50'): array
    {
        $properties = Property::withoutGlobalScopes()->where('user_id', $user->id)->get();

        // CA brut (montants loyers sans déduire les commissions)
        $grossIncome = '0';
        foreach ($properties as $property) {
            $income = $property->incomes()
                ->whereYear('income_date', $year)
                ->sum('amount');
            $grossIncome = bcadd($grossIncome, (string) $income, 0);
        }

        // Micro-BIC : CA × (1 - abattement/100)
        $microBicResult = bcmul(
            $grossIncome,
            bcsub('1', bcdiv($abatement, '100', 10), 10),
            0
        );

        // Régime réel : résultat fiscal calculé
        $fiscalYear = $this->getOrCreate($user, $year);
        $realResult = (string) $fiscalYear->fiscal_result;

        // Avantage du réel (positif = le réel est mieux)
        $advantage = bcsub($microBicResult, $realResult, 0);

        return [
            'gross_income'     => $grossIncome,
            'micro_bic_result' => $microBicResult,
            'real_result'      => $realResult,
            'advantage'        => $advantage,
            'recommended'      => bccomp($realResult, $microBicResult, 0) < 0 ? 'real' : 'micro_bic',
        ];
    }
}
