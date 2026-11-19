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
     *   où elle prend effet) : tout est déduit l'année du paiement, rien avant ni après. La
     *   méthode d'étalement et le remboursement anticipé sont alors sans objet : tout a déjà
     *   été déduit avant qu'ils ne puissent jouer un rôle.
     * - **Étalement** (§ 60 : méthode choisie PAR EMPRUNT, `Loan::fees_spread_method`) :
     *   - `linear` (défaut, § 70, sans prorata temporis) : fraction annuelle =
     *     frais × 12 / `duration_months`, la PREMIÈRE année pleine comme les suivantes — ce
     *     n'est pas un amortissement au sens du 39-1-2° (§ 60).
     *   - `interest` (§ 100-110) : fraction de l'année k = frais × intérêts courus de k /
     *     intérêts totaux du prêt — nécessite un tableau d'amortissement ; à défaut, repli
     *     silencieux sur `linear` (signalé par `GetLoan` via `effectiveFeesSpreadMethod()`).
     *   Dans les deux cas, les années se comptent à partir de celle du paiement et la
     *   DERNIÈRE fraction prend le reliquat, pour que la somme retombe exactement sur
     *   `fees_amount` malgré la troncature bcmath de chaque fraction.
     * - **Remboursement anticipé** (§ 120-130, seulement en étalement) : le solde non
     *   encore déduit à la date du remboursement devient déductible au prorata du capital
     *   remboursé par anticipation (solde × capital remboursé / capital restant dû avant le
     *   remboursement) ; le reste continue d'être étalé sur la durée restante, selon la même
     *   méthode. Un remboursement total (capital remboursé ≥ capital restant dû) rend donc
     *   tout le solde déductible l'année du remboursement.
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
        $finalYear = $paymentYear + $totalYears - 1;
        $yearIndex = $year - $paymentYear; // 0 = année de paiement

        if ($yearIndex < 0 || $yearIndex >= $totalYears) {
            return ['deducted' => '0', 'remaining_end' => '0', 'mode' => 'spread'];
        }

        $schedule = $this->interestScheduleByYear($loan);
        $method = $loan->fees_spread_method === Loan::FEES_METHOD_INTEREST && $schedule !== null
            ? Loan::FEES_METHOD_INTEREST
            : Loan::FEES_METHOD_LINEAR;

        // Répartition « au repos », sans remboursement anticipé : c'est elle qu'applique un
        // emprunt qui va à son terme, et le point de départ du calcul avec remboursement.
        $rawFractions = $this->spreadFractions($fees, $paymentYear, $totalYears, $months, $method, $schedule);

        $earlyDate = $loan->early_repayment_date;
        $earlyAmount = (int) ($loan->early_repayment_amount ?? 0);

        if ($earlyDate === null || $earlyAmount <= 0) {
            return $this->fractionResult($rawFractions, $paymentYear, $year, $fees);
        }

        $repayYear = (int) $earlyDate->year;

        // Remboursement anticipé hors de la période d'étalement (avant le premier
        // paiement des frais, ou déjà après son terme naturel) : sans effet.
        if ($repayYear < $paymentYear || $repayYear > $finalYear || $year < $repayYear) {
            return $this->fractionResult($rawFractions, $paymentYear, $year, $fees);
        }

        // --- § 120-130, à partir de l'année du remboursement anticipé ---
        $cumulativeBeforeRepayYear = '0';
        for ($y = $paymentYear; $y < $repayYear; $y++) {
            $cumulativeBeforeRepayYear = bcadd($cumulativeBeforeRepayYear, $rawFractions[$y], 0);
        }
        $balanceBeforeRepay = bcsub($fees, $cumulativeBeforeRepayYear, 0);

        if (bccomp($balanceBeforeRepay, '0', 0) <= 0) {
            // Plus rien à déduire avant même le remboursement (cas limite) : il n'a aucun effet.
            return $this->fractionResult($rawFractions, $paymentYear, $year, $fees);
        }

        $capitalOutstanding = (string) $loan->capitalOutstandingBefore($earlyDate);

        if (bccomp($capitalOutstanding, '0', 0) <= 0) {
            // Capital déjà nul avant même le remboursement (cas limite) : rien à proratiser,
            // le solde restant devient intégralement déductible.
            $accelerated = $balanceBeforeRepay;
        } else {
            // On ne peut pas rembourser plus que le capital restant dû : un montant saisi
            // trop grand est ramené à celui-ci (remboursement total).
            $capitalRepaid = (string) min($earlyAmount, (int) $capitalOutstanding);
            $accelerated = bcdiv(bcmul($balanceBeforeRepay, $capitalRepaid, 0), $capitalOutstanding, 0);
        }

        if (bccomp($accelerated, $balanceBeforeRepay, 0) > 0) {
            $accelerated = $balanceBeforeRepay;
        }

        $remainingAfterAccelerated = bcsub($balanceBeforeRepay, $accelerated, 0);
        $remainingDurationYears = $finalYear - $repayYear;

        if ($remainingDurationYears <= 0 || bccomp($remainingAfterAccelerated, '0', 0) <= 0) {
            // Rien à ré-étaler sur des années restantes : tout est absorbé l'année du
            // remboursement (c'est systématiquement le cas d'un remboursement total, et
            // aussi d'un remboursement partiel survenant la dernière année prévue).
            if ($year === $repayYear) {
                return ['deducted' => $balanceBeforeRepay, 'remaining_end' => '0', 'mode' => 'spread'];
            }

            return ['deducted' => '0', 'remaining_end' => '0', 'mode' => 'spread'];
        }

        if ($year === $repayYear) {
            return ['deducted' => $accelerated, 'remaining_end' => $remainingAfterAccelerated, 'mode' => 'spread'];
        }

        // Ré-étalement du solde sur les années restantes du prêt, avec la même méthode —
        // à défaut de tableau d'amortissement, `spreadFractions()` retombe déjà sur linear.
        $postFractions = $this->spreadFractions(
            $remainingAfterAccelerated,
            $repayYear + 1,
            $remainingDurationYears,
            $remainingDurationYears * 12,
            $method,
            $schedule,
        );

        $deducted = $postFractions[$year] ?? '0';
        $cumulativePost = '0';
        for ($y = $repayYear + 1; $y <= $year; $y++) {
            $cumulativePost = bcadd($cumulativePost, $postFractions[$y] ?? '0', 0);
        }
        $remainingEnd = bcsub($remainingAfterAccelerated, $cumulativePost, 0);

        return ['deducted' => $deducted, 'remaining_end' => $remainingEnd, 'mode' => 'spread'];
    }

    /**
     * Méthode d'étalement RÉELLEMENT appliquée à un emprunt, après repli éventuel.
     *
     * `interest` (§ 100-110) suppose un tableau d'amortissement : sans lui, impossible de
     * connaître les intérêts courus année par année, et `feesForYear()` retombe en silence
     * sur `linear`. Ce repli doit être visible quelque part — `GetLoan` s'en sert pour le dire.
     *
     * @return array{requested: string, applied: string, fallback: bool}
     */
    public function effectiveFeesSpreadMethod(Loan $loan): array
    {
        $requested = $loan->fees_spread_method ?? Loan::FEES_METHOD_LINEAR;

        if ($requested !== Loan::FEES_METHOD_INTEREST) {
            return ['requested' => $requested, 'applied' => Loan::FEES_METHOD_LINEAR, 'fallback' => false];
        }

        $hasSchedule = $this->interestScheduleByYear($loan) !== null;

        return [
            'requested' => $requested,
            'applied' => $hasSchedule ? Loan::FEES_METHOD_INTEREST : Loan::FEES_METHOD_LINEAR,
            'fallback' => ! $hasSchedule,
        ];
    }

    /**
     * Résultat de `feesForYear()` pour une répartition déjà calculée (sans remboursement
     * anticipé, ou hors de son champ), à partir du tableau année => fraction de
     * `spreadFractions()`.
     */
    private function fractionResult(array $fractions, int $paymentYear, int $year, string $fees): array
    {
        $deducted = $fractions[$year] ?? '0';
        $cumulative = '0';
        foreach ($fractions as $y => $amount) {
            if ($y <= $year) {
                $cumulative = bcadd($cumulative, $amount, 0);
            }
        }

        return [
            'deducted' => $deducted,
            'remaining_end' => bcsub($fees, $cumulative, 0),
            'mode' => 'spread',
        ];
    }

    /**
     * Répartit `$amount` sur `$numYears` années calendaires à partir de `$startYear`, la
     * DERNIÈRE absorbant le reliquat de troncature pour que la somme retombe exactement sur
     * `$amount`.
     *
     * - `interest` : pondère chaque année par ses intérêts courus (`$scheduleByYear`,
     *   restreint à la période demandée) — sans ça, décroissant avec le capital restant dû,
     *   comme l'exige le § 100-110. Si le total des poids sur la période est nul (prêt à
     *   taux 0, ou aucune échéance sur cette période précise), repli sur `linear` pour CET
     *   appel seulement.
     * - `linear` : taux mensuel `$amount / $monthsForRate × 12`, appliqué aux `$numYears - 1`
     *   premières années — c'est ce qui reproduit, pour la répartition initiale, la formule
     *   du lot 1 (`$monthsForRate` = durée du prêt en mois, qui peut différer de
     *   `$numYears × 12` quand la durée n'est pas un multiple de 12). Pour un ré-étalement
     *   après remboursement anticipé, l'appelant passe `$numYears × 12` : il n'y a alors plus
     *   de notion de mois, seulement d'années pleines restantes.
     *
     * @param  array<int, string>|null  $scheduleByYear
     * @return array<int, string> année => centimes (bcmath), sommant exactement à `$amount`
     */
    private function spreadFractions(
        string $amount,
        int $startYear,
        int $numYears,
        int $monthsForRate,
        string $method,
        ?array $scheduleByYear,
    ): array {
        $years = range($startYear, $startYear + $numYears - 1);

        if ($method === Loan::FEES_METHOD_INTEREST && $scheduleByYear !== null) {
            $totalWeight = '0';
            $weights = [];
            foreach ($years as $y) {
                $weights[$y] = $scheduleByYear[$y] ?? '0';
                $totalWeight = bcadd($totalWeight, $weights[$y], 0);
            }

            if (bccomp($totalWeight, '0', 0) > 0) {
                $fractions = [];
                $cumulative = '0';
                foreach ($years as $index => $y) {
                    if ($index === $numYears - 1) {
                        $fractions[$y] = bcsub($amount, $cumulative, 0);
                        break;
                    }

                    $fractions[$y] = bcdiv(bcmul($amount, $weights[$y], 0), $totalWeight, 0);
                    $cumulative = bcadd($cumulative, $fractions[$y], 0);
                }

                return $fractions;
            }
            // Aucun intérêt sur cette période : impossible de pondérer, on retombe sur le
            // linéaire ci-dessous pour cette répartition précise.
        }

        $annual = bcdiv(bcmul($amount, '12', 0), (string) $monthsForRate, 0);
        $fractions = [];
        $cumulative = '0';
        foreach ($years as $index => $y) {
            if ($index === $numYears - 1) {
                $fractions[$y] = bcsub($amount, $cumulative, 0);
                break;
            }

            $fractions[$y] = $annual;
            $cumulative = bcadd($cumulative, $annual, 0);
            if (bccomp($cumulative, $amount, 0) >= 0) {
                $cumulative = $amount;
            }
        }

        return $fractions;
    }

    /**
     * Intérêts courus par année calendaire de paiement, sur TOUTE la durée du prêt — la
     * base du prorata § 100-110. `null` si l'emprunt ne porte encore aucune échéance (ni
     * calculée, ni importée) : signal pour `feesForYear()`/`effectiveFeesSpreadMethod()`
     * de retomber sur le linéaire, faute de pouvoir mesurer des intérêts courus.
     *
     * @return array<int, string>|null année => centimes (bcmath)
     */
    private function interestScheduleByYear(Loan $loan): ?array
    {
        $payments = $loan->payments()->get(['payment_date', 'interest_amount']);

        if ($payments->isEmpty()) {
            return null;
        }

        $byYear = [];
        foreach ($payments as $payment) {
            $y = (int) $payment->payment_date->year;
            $byYear[$y] = bcadd($byYear[$y] ?? '0', (string) $payment->interest_amount, 0);
        }

        return $byYear;
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
