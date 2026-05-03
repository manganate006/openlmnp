<?php

namespace App\Services;

use App\Models\FiscalYear;
use App\Models\Property;
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
     */
    public function calculate(FiscalYear $fiscalYear): FiscalYear
    {
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

        return $fiscalYear->refresh();
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
