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

    public function __construct(
        private DepreciationService $depreciationService,
    ) {}

    /**
     * Génère toutes les écritures comptables pour un exercice fiscal.
     * Supprime les écritures existantes avant de les régénérer.
     */
    public function generateForFiscalYear(FiscalYear $fiscalYear): int
    {
        // Supprimer les écritures existantes
        AccountingEntry::where('fiscal_year_id', $fiscalYear->id)->delete();

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

        return $pieceNum - 1; // nombre d'écritures générées
    }

    /**
     * Écritures de recettes.
     * TVA-exempt : 512 débit / 706 crédit (montant net)
     * TVA-liable : 512 débit TTC / 706 crédit HT / 44571 crédit TVA
     */
    private function generateIncomeEntries(FiscalYear $fiscalYear, Property $property, int $pieceNum): int
    {
        $year = $fiscalYear->year;
        $incomes = $property->incomes()->whereYear('income_date', $year)->get();
        $isTvaLiable = $property->isTvaLiable();

        foreach ($incomes as $income) {
            $ref = "REC-{$pieceNum}";
            $label = 'Loyer ' . ($income->guest_name ?? 'Airbnb') . ' - ' . $income->income_date->format('d/m/Y');

            if ($isTvaLiable && $income->tva_collected > 0) {
                $netTtc = $income->amount - $income->platform_fee;
                $netHt = $income->amount_ht - $income->platform_fee;

                // Débit banque (TTC net reçu)
                AccountingEntry::create([
                    'fiscal_year_id' => $fiscalYear->id,
                    'property_id'    => $property->id,
                    'entry_date'     => $income->income_date,
                    'account_code'   => '512',
                    'label'          => $label,
                    'debit'          => $netTtc,
                    'credit'         => 0,
                    'piece_ref'      => $ref,
                    'journal'        => 'VE',
                ]);

                // Crédit loyers (HT net)
                AccountingEntry::create([
                    'fiscal_year_id' => $fiscalYear->id,
                    'property_id'    => $property->id,
                    'entry_date'     => $income->income_date,
                    'account_code'   => '706',
                    'label'          => $label,
                    'debit'          => 0,
                    'credit'         => $netHt,
                    'piece_ref'      => $ref,
                    'journal'        => 'VE',
                ]);

                // Crédit TVA collectée
                AccountingEntry::create([
                    'fiscal_year_id' => $fiscalYear->id,
                    'property_id'    => $property->id,
                    'entry_date'     => $income->income_date,
                    'account_code'   => '44571',
                    'label'          => 'TVA collectée - ' . $label,
                    'debit'          => 0,
                    'credit'         => $income->tva_collected,
                    'piece_ref'      => $ref,
                    'journal'        => 'VE',
                ]);
            } else {
                $netAmount = $income->amount - $income->platform_fee;

                // Débit banque
                AccountingEntry::create([
                    'fiscal_year_id' => $fiscalYear->id,
                    'property_id'    => $property->id,
                    'entry_date'     => $income->income_date,
                    'account_code'   => '512',
                    'label'          => $label,
                    'debit'          => $netAmount,
                    'credit'         => 0,
                    'piece_ref'      => $ref,
                    'journal'        => 'VE',
                ]);

                // Crédit loyers
                AccountingEntry::create([
                    'fiscal_year_id' => $fiscalYear->id,
                    'property_id'    => $property->id,
                    'entry_date'     => $income->income_date,
                    'account_code'   => '706',
                    'label'          => $label,
                    'debit'          => 0,
                    'credit'         => $netAmount,
                    'piece_ref'      => $ref,
                    'journal'        => 'VE',
                ]);
            }

            // Si commission plateforme > 0, écriture séparée
            if ($income->platform_fee > 0) {
                $refFee = "COM-{$pieceNum}";
                AccountingEntry::create([
                    'fiscal_year_id' => $fiscalYear->id,
                    'property_id'    => $property->id,
                    'entry_date'     => $income->income_date,
                    'account_code'   => '622',
                    'label'          => 'Commission plateforme - ' . $income->income_date->format('d/m/Y'),
                    'debit'          => $income->platform_fee,
                    'credit'         => 0,
                    'piece_ref'      => $refFee,
                    'journal'        => 'HA',
                ]);
                AccountingEntry::create([
                    'fiscal_year_id' => $fiscalYear->id,
                    'property_id'    => $property->id,
                    'entry_date'     => $income->income_date,
                    'account_code'   => '512',
                    'label'          => 'Commission plateforme - ' . $income->income_date->format('d/m/Y'),
                    'debit'          => 0,
                    'credit'         => $income->platform_fee,
                    'piece_ref'      => $refFee,
                    'journal'        => 'HA',
                ]);
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
                $effectiveHt = $expense->is_dedicated
                    ? $expense->amount_ht
                    : (int) bcmul((string) $expense->amount_ht, $property->quota_share, 0);
                $effectiveTva = $expense->is_dedicated
                    ? $expense->amount_tva
                    : (int) bcmul((string) $expense->amount_tva, $property->quota_share, 0);
                $effectiveTtc = $effectiveHt + $effectiveTva;

                // Débit charge (HT)
                AccountingEntry::create([
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
                AccountingEntry::create([
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
                AccountingEntry::create([
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
                    ? $expense->amount
                    : (int) bcmul((string) $expense->amount, $property->quota_share, 0);

                // Débit charge
                AccountingEntry::create([
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
                AccountingEntry::create([
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
            AccountingEntry::create([
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
            AccountingEntry::create([
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
     * Écritures d'intérêts d'emprunt : 661 débit / 512 crédit
     */
    private function generateInterestEntries(FiscalYear $fiscalYear, Property $property, int $year, int $pieceNum): int
    {
        foreach ($property->loans as $loan) {
            $payments = $loan->payments()->whereYear('payment_date', $year)->get();

            foreach ($payments as $payment) {
                $interestProrata = (int) bcmul((string) $payment->interest_amount, $property->quota_share, 0);

                if ($interestProrata <= 0) {
                    continue;
                }

                $ref = "INT-{$pieceNum}";

                // Débit intérêts
                AccountingEntry::create([
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
                AccountingEntry::create([
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
        }

        return $pieceNum;
    }
}
