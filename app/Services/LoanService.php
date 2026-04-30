<?php

namespace App\Services;

use App\Models\Loan;
use App\Models\LoanPayment;

/**
 * Service de calcul du tableau d'amortissement d'emprunt.
 *
 * Tous les montants en centimes. Calculs bcmath.
 */
class LoanService
{
    /**
     * Génère le tableau d'amortissement complet pour un emprunt.
     */
    public function generateSchedule(Loan $loan): void
    {
        // Supprimer les échéances existantes
        $loan->payments()->delete();

        $capitalCents = $loan->amount;
        $annualRate = (float) $loan->annual_rate / 100;
        $monthlyRate = $annualRate / 12;
        $months = $loan->duration_months;
        $remaining = $capitalCents;

        // Calcul mensualité (formule d'annuité constante) — en float pour la formule
        if ($monthlyRate == 0) {
            $monthlyPaymentCents = (int) round($capitalCents / $months);
        } else {
            $monthlyPaymentFloat = ($capitalCents * $monthlyRate) / (1 - pow(1 + $monthlyRate, -$months));
            $monthlyPaymentCents = (int) round($monthlyPaymentFloat);
        }

        $loan->update(['monthly_payment' => $monthlyPaymentCents]);

        $startDate = $loan->start_date->copy();

        for ($i = 1; $i <= $months; $i++) {
            $paymentDate = $startDate->copy()->addMonths($i);

            if ($i === $months) {
                // Dernier mois : solder exactement
                $interest = (int) round($remaining * $monthlyRate);
                $capitalPaid = $remaining;
                $remaining = 0;
            } else {
                $interest = (int) round($remaining * $monthlyRate);
                $capitalPaid = $monthlyPaymentCents - $interest;
                $remaining -= $capitalPaid;
            }

            LoanPayment::create([
                'loan_id'          => $loan->id,
                'payment_date'     => $paymentDate->format('Y-m-d'),
                'month_number'     => $i,
                'capital_amount'   => $capitalPaid,
                'interest_amount'  => $interest,
                'insurance_amount' => $loan->insurance_monthly,
                'remaining_capital' => max(0, $remaining),
            ]);
        }
    }

    /**
     * Calcule les intérêts déductibles pour une année donnée.
     * Applique la quote-part du bien.
     */
    public function getDeductibleInterest(Loan $loan, int $year): int
    {
        $totalInterest = $loan->payments()
            ->whereYear('payment_date', $year)
            ->sum('interest_amount');

        $totalInsurance = $loan->payments()
            ->whereYear('payment_date', $year)
            ->sum('insurance_amount');

        $total = bcadd((string) $totalInterest, (string) $totalInsurance, 0);

        // Appliquer la quote-part du bien
        $quotaShare = $loan->property->quota_share;

        return (int) bcmul($total, $quotaShare, 0);
    }
}
