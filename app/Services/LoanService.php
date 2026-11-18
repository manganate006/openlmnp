<?php

namespace App\Services;

use App\Models\Loan;
use App\Models\LoanPayment;
use App\Models\Property;

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

    /**
     * Fraction des frais d'emprunt déductible au titre d'une année (art. 39-1-1° quater
     * CGI, BOI-BIC-CHG-20-30-40).
     *
     * - **Déduction immédiate** (option d'étalement absente, ou frais payés avant l'année
     *   où elle prend effet) : tout est déduit l'année du paiement, rien avant ni après.
     * - **Étalement linéaire** (§ 70 : sans prorata temporis) : fraction annuelle =
     *   frais × 12 / `duration_months`, la PREMIÈRE année pleine comme les suivantes — ce
     *   n'est pas un amortissement au sens du 39-1-2° (§ 60). Les années se comptent à
     *   partir de celle du paiement ; la DERNIÈRE fraction prend le reliquat, pour que la
     *   somme des fractions retombe exactement sur `fees_amount` malgré la troncature
     *   bcmath de chaque fraction.
     *
     * Avant quote-part : l'appelant applique `Loan::prorate()`, comme pour les intérêts
     * et l'assurance emprunteur — jamais appliquée deux fois par ses propres appelants.
     *
     * @return array{deducted: string, remaining_end: string, mode: string} centimes (bcmath, non proratés)
     */
    public function feesForYear(Loan $loan, int $year): array
    {
        $fees = (string) $loan->fees_amount;

        if (bccomp($fees, '0', 0) <= 0) {
            return ['deducted' => '0', 'remaining_end' => '0', 'mode' => 'immediate'];
        }

        $paidOn = $loan->feesEffectiveDate();
        $paymentYear = (int) $paidOn->year;

        $spreadFrom = $loan->property?->user?->loan_fees_spread_from;
        $isSpread = $spreadFrom !== null && $paymentYear >= (int) $spreadFrom;

        if (! $isSpread) {
            return [
                'deducted' => $year === $paymentYear ? $fees : '0',
                'remaining_end' => '0',
                'mode' => 'immediate',
            ];
        }

        $months = max(1, (int) $loan->duration_months);
        $totalYears = (int) ceil($months / 12);
        $yearIndex = $year - $paymentYear; // 0 = année de paiement

        if ($yearIndex < 0 || $yearIndex >= $totalYears) {
            return ['deducted' => '0', 'remaining_end' => '0', 'mode' => 'spread'];
        }

        // Fraction annuelle tronquée au centime : le reliquat de troncature (et celui d'une
        // durée non multiple de 12 mois) est repris par la DERNIÈRE fraction, jamais perdu.
        $annual = bcdiv(bcmul($fees, '12', 0), (string) $months, 0);
        $cumulativeBefore = bcmul($annual, (string) $yearIndex, 0);
        if (bccomp($cumulativeBefore, $fees, 0) >= 0) {
            $cumulativeBefore = $fees;
        }

        $deducted = $yearIndex === $totalYears - 1
            ? bcsub($fees, $cumulativeBefore, 0)
            : $annual;

        $remainingEnd = bcsub($fees, bcadd($cumulativeBefore, $deducted, 0), 0);

        return ['deducted' => $deducted, 'remaining_end' => $remainingEnd, 'mode' => 'spread'];
    }

    /**
     * Charges d'emprunt d'un bien pour une projection pluriannuelle : intérêts, assurance et
     * frais déduits, après quote-part (`Loan::prorate()`).
     *
     * Partagée par `Projection` (page) et `GetProjection` (MCP), qui en dupliquaient chacun
     * leur version avant le 2026-11-16 — et qui, en réalité, ne calculaient ni l'une ni
     * l'autre : ni les intérêts, ni l'assurance d'un emprunt n'entraient dans leurs charges
     * projetées, contrairement à `FiscalYearService::computeTotals()`. Les échéances d'un
     * emprunt sont déterministes sur toute sa durée (`LoanService::generateSchedule()` les
     * a déjà posées), donc interrogeables directement pour une année future — à la
     * différence des recettes et charges courantes, qui n'ont pas d'échéancier et que ces
     * deux appelants estiment par ailleurs sur la moyenne des années passées.
     *
     * @return int centimes, après quote-part
     */
    public function projectedLoanChargesForYear(Property $property, int $year): int
    {
        $total = 0;

        foreach ($property->loans as $loan) {
            $interest = $loan->getInterestsForYear($year);
            $insurance = $loan->getInsuranceForYear($year);
            $fees = (int) $this->feesForYear($loan, $year)['deducted'];

            $total += (int) $loan->prorate((string) ($interest + $insurance + $fees), $property->quota_share);
        }

        return $total;
    }
}
