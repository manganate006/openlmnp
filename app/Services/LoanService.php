<?php

namespace App\Services;

use App\Models\Loan;
use App\Models\LoanPayment;

class LoanService
{
    public function generateSchedule(Loan $loan): void
    {
        $loan->payments()->delete();

        $capitalCents = $loan->amount;
        $annualRate = (float) $loan->annual_rate / 100;
        $monthlyRate = $annualRate / 12;
        $months = $loan->duration_months;
        $remaining = $capitalCents;

        // Respecter la mensualité si l'utilisateur en a saisi une > 0
        if ($loan->monthly_payment > 0) {
            $monthlyPaymentCents = $loan->monthly_payment;
        } else {
            if ($monthlyRate == 0) {
                $monthlyPaymentCents = (int) round($capitalCents / $months);
            } else {
                $monthlyPaymentFloat = ($capitalCents * $monthlyRate) / (1 - pow(1 + $monthlyRate, -$months));
                $monthlyPaymentCents = (int) round($monthlyPaymentFloat);
            }
            $loan->update(['monthly_payment' => $monthlyPaymentCents]);
        }

        $startDate = $loan->start_date->copy();
        $insuranceRate = (float) ($loan->insurance_rate ?? 0);

        for ($i = 1; $i <= $months; $i++) {
            $paymentDate = $startDate->copy()->addMonths($i);

            // Assurance : fixe ou variable
            if ($loan->insurance_type === Loan::INSURANCE_VARIABLE && $insuranceRate > 0) {
                $monthlyInsurance = (int) round($remaining * ($insuranceRate / 12));
            } else {
                $monthlyInsurance = $loan->insurance_monthly;
            }

            if ($i === $months) {
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
                'insurance_amount' => $monthlyInsurance,
                'remaining_capital' => max(0, $remaining),
            ]);
        }
    }

    public function getDeductibleInterest(Loan $loan, int $year): int
    {
        $totalInterest = $loan->payments()->whereYear('payment_date', $year)->sum('interest_amount');
        $totalInsurance = $loan->payments()->whereYear('payment_date', $year)->sum('insurance_amount');
        $total = $totalInterest + $totalInsurance;
        $quotaShare = $loan->property->quota_share;
        return (int) bcmul((string) $total, $quotaShare, 0);
    }
}
