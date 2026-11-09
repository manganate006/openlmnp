<?php

namespace App\Services;

use App\Models\AccountingEntry;
use App\Models\FiscalYear;
use App\Models\Property;

/**
 * Génère les écritures comptables à partir des données saisies
 * (recettes, charges, amortissements, intérêts d'emprunt).
 *
 * Chaque écriture est équilibrée (débit = crédit).
 * Les écritures partagent le même piece_ref pour un même fait comptable.
 *
 * Les écritures sont des données DÉRIVÉES, stockées dans `accounting_entries` : elles ne sont
 * régénérées que par `FiscalYearService::calculate()` (donc jamais pour un exercice clôturé,
 * sauf recalcul forcé). `build()` les produit sans rien écrire, pour que
 * `openlmnp:repair-accounting-entries` compare ce qui est stocké à ce qui serait produit.
 */
class AccountingEntryService
{
    /**
     * Mapping catégorie de charge → compte PCG.
     */
    private const EXPENSE_ACCOUNT_MAP = [
        'property_tax'  => '6351',
        'insurance'     => '616',
        'energy'        => '6061',
        'maintenance'   => '615',
        'supplies'      => '606',
        'platform_fees' => '622',
        'accounting'    => '6226',
        'telecom'       => '626',
        'travel'        => '625',
        'cleaning'      => '615',
        'other'         => '65',
    ];

    /**
     * Mapping composant d'amortissement → compte d'amortissement cumulé.
     */
    private const DEPRECIATION_ACCOUNT_MAP = [
        'Gros œuvre'                => '2813',
        'Toiture'                   => '2813',
        'Installations électriques' => '2815',
        'Étanchéité'                => '28181',
        'Agencements intérieurs'    => '28181',
        'Plomberie / sanitaire'     => '2815',
    ];

    /** Écritures en cours de construction (voir `build()`). */
    private array $buffer = [];

    public function __construct(
        private DepreciationService $depreciationService,
    ) {}

    /**
     * Génère toutes les écritures comptables pour un exercice fiscal.
     * Supprime les écritures existantes avant de les régénérer.
     */
    public function generateForFiscalYear(FiscalYear $fiscalYear): int
    {
        [$entries, $count] = $this->buildWithCount($fiscalYear);

        // Supprimer les écritures existantes
        AccountingEntry::where('fiscal_year_id', $fiscalYear->id)->delete();

        foreach ($entries as $entry) {
            AccountingEntry::create($entry);
        }

        return $count;
    }

    /**
     * Les écritures de l'exercice, SANS rien écrire en base.
     *
     * @return list<array<string, mixed>> attributs d'`AccountingEntry`, dans l'ordre de création
     */
    public function build(FiscalYear $fiscalYear): array
    {
        return $this->buildWithCount($fiscalYear)[0];
    }

    /** @return array{0: list<array<string, mixed>>, 1: int} écritures et nombre de pièces */
    private function buildWithCount(FiscalYear $fiscalYear): array
    {
        $this->buffer = [];

        $user = $fiscalYear->user;
        $year = $fiscalYear->year;
        $properties = Property::withoutGlobalScopes()->where('user_id', $user->id)->get();
        $pieceNum = 1;

        foreach ($properties as $property) {
            // 1. Écritures de recettes
            $pieceNum = $this->generateIncomeEntries($fiscalYear, $property, $pieceNum);

            // 2. Écritures de charges
            $pieceNum = $this->generateExpenseEntries($fiscalYear, $property, $pieceNum);

            // 3. Écritures d'amortissement
            $pieceNum = $this->generateDepreciationEntries($fiscalYear, $property, $year, $pieceNum);

            // 4. Écritures d'intérêts d'emprunt
            $pieceNum = $this->generateInterestEntries($fiscalYear, $property, $year, $pieceNum);
        }

        $entries = $this->buffer;
        $this->buffer = [];

        return [$entries, $pieceNum - 1]; // nombre d'écritures générées
    }

    /** Ajoute une ligne à l'écriture en cours de construction. */
    private function push(array $attributes): void
    {
        $this->buffer[] = $attributes;
    }

    /**
     * Écritures de recettes : UNE écriture par recette.
     * TVA-exempt : 512 débit net encaissé + 622 débit commission / 706 crédit brut
     * TVA-liable : 512 débit net encaissé (TTC − commission) + 622 débit commission
     *              / 706 crédit brut HT / 44571 crédit TVA
     *
     * Jusqu'au 30/10/2026, 706 était crédité du loyer NET puis une écriture séparée débitait
     * encore 622 par 512 : la commission sortait deux fois, et le résultat comptable comme le
     * solde de banque étaient minorés d'autant. La commission n'a pas de TVA déductible : elle
     * est retenue pour son montant, comme dans le résultat fiscal (2033-B, ligne 242).
     */
    private function generateIncomeEntries(FiscalYear $fiscalYear, Property $property, int $pieceNum): int
    {
        $year = $fiscalYear->year;
        $incomes = $property->incomes()->whereYear('income_date', $year)->get();
        $isTvaLiable = $property->isTvaLiable();

        foreach ($incomes as $income) {
            $ref = "REC-{$pieceNum}";
            $label = 'Loyer ' . ($income->guest_name ?? 'Airbnb') . ' - ' . $income->income_date->format('d/m/Y');
            $fee = (int) $income->platform_fee;
            $withTva = $isTvaLiable && $income->tva_collected > 0;
            $line = fn (string $account, int $debit, int $credit, string $text) => $this->push([
                'fiscal_year_id' => $fiscalYear->id,
                'property_id'    => $property->id,
                'entry_date'     => $income->income_date,
                'account_code'   => $account,
                'label'          => $text,
                'debit'          => $debit,
                'credit'         => $credit,
                'piece_ref'      => $ref,
                'journal'        => 'VE',
            ]);

            // Débit banque : ce qui a réellement été encaissé (brut TTC − commission)
            $line('512', (int) $income->amount - $fee, 0, $label);

            // Débit commission de la plateforme
            if ($fee !== 0) {
                $line('622', $fee, 0, 'Commission plateforme - ' . $income->income_date->format('d/m/Y'));
            }

            // Crédit loyers : le BRUT (HT pour un bien assujetti)
            $line('706', 0, $withTva ? (int) $income->amount_ht : (int) $income->amount, $label);

            // Crédit TVA collectée
            if ($withTva) {
                $line('44571', 0, (int) $income->tva_collected, 'TVA collectée - ' . $label);
            }

            $pieceNum++;
        }

        return $pieceNum;
    }

    /**
     * Écritures de charges.
     * TVA-exempt : 6xx débit TTC / 512 crédit TTC
     * TVA-liable : 6xx débit HT / 44566 débit TVA / 512 crédit TTC
     */
    private function generateExpenseEntries(FiscalYear $fiscalYear, Property $property, int $pieceNum): int
    {
        $year = $fiscalYear->year;
        $expenses = $property->expenses()->whereYear('expense_date', $year)->get();
        $isTvaLiable = $property->isTvaLiable();

        foreach ($expenses as $expense) {
            $accountCode = self::EXPENSE_ACCOUNT_MAP[$expense->category] ?? '65';
            $ref = "CHG-{$pieceNum}";

            if ($isTvaLiable && $expense->amount_tva > 0) {
                // Part d'usage (#17) puis quote-part surface, comme FiscalYearService.
                $effectiveHt = $expense->is_dedicated
                    ? $expense->usageAdjusted('amount_ht')
                    : (int) bcmul((string) $expense->usageAdjusted('amount_ht'), $property->quota_share, 0);
                $effectiveTva = $expense->is_dedicated
                    ? $expense->usageAdjusted('amount_tva')
                    : (int) bcmul((string) $expense->usageAdjusted('amount_tva'), $property->quota_share, 0);
                $effectiveTtc = $effectiveHt + $effectiveTva;

                // Débit charge (HT)
                $this->push([
                    'fiscal_year_id' => $fiscalYear->id,
                    'property_id'    => $property->id,
                    'entry_date'     => $expense->expense_date,
                    'account_code'   => $accountCode,
                    'label'          => $expense->description,
                    'debit'          => $effectiveHt,
                    'credit'         => 0,
                    'piece_ref'      => $ref,
                    'journal'        => 'HA',
                ]);

                // Débit TVA déductible
                $this->push([
                    'fiscal_year_id' => $fiscalYear->id,
                    'property_id'    => $property->id,
                    'entry_date'     => $expense->expense_date,
                    'account_code'   => '44566',
                    'label'          => 'TVA déductible - ' . $expense->description,
                    'debit'          => $effectiveTva,
                    'credit'         => 0,
                    'piece_ref'      => $ref,
                    'journal'        => 'HA',
                ]);

                // Crédit banque (TTC)
                $this->push([
                    'fiscal_year_id' => $fiscalYear->id,
                    'property_id'    => $property->id,
                    'entry_date'     => $expense->expense_date,
                    'account_code'   => '512',
                    'label'          => $expense->description,
                    'debit'          => 0,
                    'credit'         => $effectiveTtc,
                    'piece_ref'      => $ref,
                    'journal'        => 'HA',
                ]);
            } else {
                $effectiveAmount = $expense->is_dedicated
                    ? $expense->usageAdjusted('amount')
                    : (int) bcmul((string) $expense->usageAdjusted('amount'), $property->quota_share, 0);

                // Débit charge
                $this->push([
                    'fiscal_year_id' => $fiscalYear->id,
                    'property_id'    => $property->id,
                    'entry_date'     => $expense->expense_date,
                    'account_code'   => $accountCode,
                    'label'          => $expense->description,
                    'debit'          => $effectiveAmount,
                    'credit'         => 0,
                    'piece_ref'      => $ref,
                    'journal'        => 'HA',
                ]);

                // Crédit banque
                $this->push([
                    'fiscal_year_id' => $fiscalYear->id,
                    'property_id'    => $property->id,
                    'entry_date'     => $expense->expense_date,
                    'account_code'   => '512',
                    'label'          => $expense->description,
                    'debit'          => 0,
                    'credit'         => $effectiveAmount,
                    'piece_ref'      => $ref,
                    'journal'        => 'HA',
                ]);
            }

            $pieceNum++;
        }

        return $pieceNum;
    }

    /**
     * Écritures d'amortissement : 681 débit / 28xx crédit
     */
    private function generateDepreciationEntries(FiscalYear $fiscalYear, Property $property, int $year, int $pieceNum): int
    {
        $depreciation = $this->depreciationService->calculateAnnualDepreciation($property, $year);
        $yearEnd = "{$year}-12-31";

        foreach ($depreciation['details'] as $detail) {
            $amount = (int) $detail['amount'];
            if ($amount <= 0) {
                continue;
            }

            $creditAccount = self::DEPRECIATION_ACCOUNT_MAP[$detail['name']] ?? '28188';
            $ref = "AMO-{$pieceNum}";

            // Débit dotations
            $this->push([
                'fiscal_year_id' => $fiscalYear->id,
                'property_id'    => $property->id,
                'entry_date'     => $yearEnd,
                'account_code'   => '68112',
                'label'          => 'Dotation amortissement - ' . $detail['name'],
                'debit'          => $amount,
                'credit'         => 0,
                'piece_ref'      => $ref,
                'journal'        => 'OD',
            ]);

            // Crédit amortissements cumulés
            $this->push([
                'fiscal_year_id' => $fiscalYear->id,
                'property_id'    => $property->id,
                'entry_date'     => $yearEnd,
                'account_code'   => $creditAccount,
                'label'          => 'Dotation amortissement - ' . $detail['name'],
                'debit'          => 0,
                'credit'         => $amount,
                'piece_ref'      => $ref,
                'journal'        => 'OD',
            ]);

            $pieceNum++;
        }

        return $pieceNum;
    }

    /**
     * Écritures d'emprunt : intérêts 6611 débit / 512 crédit, assurance emprunteur 616 débit /
     * 512 crédit.
     */
    private function generateInterestEntries(FiscalYear $fiscalYear, Property $property, int $year, int $pieceNum): int
    {
        foreach ($property->loans as $loan) {
            $payments = $loan->payments()->whereYear('payment_date', $year)->get();

            foreach ($payments as $payment) {
                $interestProrata = (int) $loan->prorate((string) $payment->interest_amount, $property->quota_share);

                if ($interestProrata <= 0) {
                    continue;
                }

                $ref = "INT-{$pieceNum}";

                // Débit intérêts
                $this->push([
                    'fiscal_year_id' => $fiscalYear->id,
                    'property_id'    => $property->id,
                    'entry_date'     => $payment->payment_date,
                    'account_code'   => '6611',
                    'label'          => 'Intérêts emprunt ' . ($loan->bank_name ?? '') . ' - ' . $payment->payment_date->format('m/Y'),
                    'debit'          => $interestProrata,
                    'credit'         => 0,
                    'piece_ref'      => $ref,
                    'journal'        => 'OD',
                ]);

                // Crédit banque
                $this->push([
                    'fiscal_year_id' => $fiscalYear->id,
                    'property_id'    => $property->id,
                    'entry_date'     => $payment->payment_date,
                    'account_code'   => '512',
                    'label'          => 'Intérêts emprunt ' . ($loan->bank_name ?? '') . ' - ' . $payment->payment_date->format('m/Y'),
                    'debit'          => 0,
                    'credit'         => $interestProrata,
                    'piece_ref'      => $ref,
                    'journal'        => 'OD',
                ]);

                $pieceNum++;
            }

            // Assurance emprunteur : une prime d'assurance, 616 débit / 512 crédit (repère 242
            // du 2033-B), et non une charge financière. Sans ces écritures, le FEC omettait une
            // charge que le résultat fiscal déduit.
            foreach ($payments as $payment) {
                $insuranceProrata = (int) $loan->prorate((string) $payment->insurance_amount, $property->quota_share);

                if ($insuranceProrata <= 0) {
                    continue;
                }

                $ref = "ASS-{$pieceNum}";
                $label = 'Assurance emprunteur ' . ($loan->bank_name ?? '') . ' - ' . $payment->payment_date->format('m/Y');

                $this->push([
                    'fiscal_year_id' => $fiscalYear->id,
                    'property_id'    => $property->id,
                    'entry_date'     => $payment->payment_date,
                    'account_code'   => '616',
                    'label'          => $label,
                    'debit'          => $insuranceProrata,
                    'credit'         => 0,
                    'piece_ref'      => $ref,
                    'journal'        => 'OD',
                ]);

                $this->push([
                    'fiscal_year_id' => $fiscalYear->id,
                    'property_id'    => $property->id,
                    'entry_date'     => $payment->payment_date,
                    'account_code'   => '512',
                    'label'          => $label,
                    'debit'          => 0,
                    'credit'         => $insuranceProrata,
                    'piece_ref'      => $ref,
                    'journal'        => 'OD',
                ]);

                $pieceNum++;
            }
        }

        return $pieceNum;
    }
}
