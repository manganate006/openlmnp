<?php

namespace App\Services;

use App\Models\Loan;
use App\Models\LoanPayment;

class LoanService
{
    /**
     * (Re)calcule le tableau d'amortissement théorique.
     *
     * ⚠️ Un emprunt dont les échéances ont été IMPORTÉES depuis le tableau de la
     * banque (`schedule_source = imported`) porte le réel : différé, assurance
     * dégressive, modulation. Le recalculer effacerait des intérêts déductibles
     * déjà déclarés — on ne le fait donc que sur demande explicite ($force),
     * via l'action « Recalculer le tableau théorique » de la fiche emprunt.
     *
     * @return bool Faux si le tableau importé a été préservé.
     */
    public function generateSchedule(Loan $loan, bool $force = false): bool
    {
        if ($loan->hasImportedSchedule() && ! $force) {
            return false;
        }

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
        // Pourcentage annuel, comme annual_rate ci-dessus : la division par 100
        // manquait ici, ce qui rendait l'assurance variable 100 fois trop chère.
        $insuranceRate = (float) ($loan->insurance_rate ?? 0) / 100;

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

        // Un recalcul forcé remet l'emprunt sur le tableau théorique : sans
        // cela, la garde ci-dessus continuerait de protéger des lignes qui ne
        // viennent plus de la banque.
        if ($loan->hasImportedSchedule()) {
            $loan->forceFill(['schedule_source' => Loan::SCHEDULE_COMPUTED])->save();
        }

        return true;
    }

    public function getDeductibleInterest(Loan $loan, int $year): int
    {
        $totalInterest = $loan->payments()->whereYear('payment_date', $year)->sum('interest_amount');
        $totalInsurance = $loan->payments()->whereYear('payment_date', $year)->sum('insurance_amount');
        $total = $totalInterest + $totalInsurance;
        $quotaShare = $loan->property->quota_share;

        return (int) $loan->prorate((string) $total, $quotaShare);
    }
}
