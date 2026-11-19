<?php

use App\Models\Loan;
use App\Models\LoanPayment;
use App\Models\Property;
use App\Models\User;
use App\Services\FiscalYearService;
use App\Services\LoanService;
use App\Services\TaxReturnService;

/**
 * Lot 2 des frais d'emprunt : méthode d'étalement PAR EMPRUNT (BOI-BIC-CHG-20-30-40
 * § 60, § 100-110) et remboursement anticipé (§ 120-130).
 */
function methodLoanProperty(User $user, array $extra = []): Property
{
    return Property::forceCreate(array_merge([
        'user_id' => $user->id,
        'name' => 'Bien à méthode',
        'address' => '9 rue des Méthodes',
        'city' => 'Nantes',
        'postal_code' => '44000',
        'type' => 'apartment',
        'total_area' => 100,
        'rented_area' => 100, // quote-part = 1
        'acquisition_date' => '2022-01-01',
        'acquisition_price' => 20000000,
        'notary_fees' => 0,
        'land_percentage' => 0,
        'rental_start_date' => '2022-01-01',
        'rental_type' => 'seasonal',
        'is_primary_residence' => false,
    ], $extra));
}

function methodLoan(Property $property, array $extra = []): Loan
{
    return Loan::create(array_merge([
        'property_id' => $property->id,
        'bank_name' => 'Banque Test',
        'amount' => 10000000,
        'annual_rate' => 2.0,
        'duration_months' => 120,
        'start_date' => '2024-01-01',
        'monthly_payment' => 0,
        'insurance_monthly' => 0,
        'fees_spread_method' => Loan::FEES_METHOD_LINEAR,
    ], $extra));
}

/** Pose une échéance unique par année, pour contrôler exactement les intérêts courus. */
function methodPayment(Loan $loan, string $date, int $month, int $interest, int $remaining): LoanPayment
{
    return LoanPayment::create([
        'loan_id' => $loan->id,
        'payment_date' => $date,
        'month_number' => $month,
        'capital_amount' => 0,
        'interest_amount' => $interest,
        'insurance_amount' => 0,
        'remaining_capital' => $remaining,
    ]);
}

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->loanService = app(LoanService::class);
});

// --- Méthode « au prorata des intérêts » (§ 100-110) ----------------------------------

it('spreads fees proportionally to the interest accrued each year, decreasing, summing exactly to the fees', function () {
    $this->user->forceFill(['loan_fees_spread_from' => 2024])->save();

    $property = methodLoanProperty($this->user);
    $loan = methodLoan($property, [
        'duration_months' => 36, // 3 ans
        'fees_amount' => 100000, // 1 000 €
        'fees_paid_on' => '2024-01-01',
        'fees_spread_method' => Loan::FEES_METHOD_INTEREST,
    ]);

    // Intérêts courus décroissants : 600 €, 400 €, 200 € (total 1 200 €) — un tableau
    // d'amortissement classique, où le capital restant dû diminue chaque année.
    methodPayment($loan, '2024-06-01', 1, 60000, 800000);
    methodPayment($loan, '2025-06-01', 13, 40000, 400000);
    methodPayment($loan, '2026-06-01', 25, 20000, 0);

    // Fraction année k = frais × intérêts(k) / intérêts totaux (§ 100-110) :
    //   2024 : 100 000 × 60 000 / 120 000 = 50 000
    //   2025 : 100 000 × 40 000 / 120 000 = 33 333,33... tronqué à 33 333
    //   2026 (dernière) : reliquat = 100 000 − 50 000 − 33 333 = 16 667
    expect($this->loanService->feesForYear($loan, 2024))
        ->toBe(['deducted' => '50000', 'remaining_end' => '50000', 'mode' => 'spread'])
        ->and($this->loanService->feesForYear($loan, 2025))
        ->toBe(['deducted' => '33333', 'remaining_end' => '16667', 'mode' => 'spread'])
        ->and($this->loanService->feesForYear($loan, 2026))
        ->toBe(['deducted' => '16667', 'remaining_end' => '0', 'mode' => 'spread']);

    $sum = 0;
    foreach ([2024, 2025, 2026] as $year) {
        $sum += (int) $this->loanService->feesForYear($loan, $year)['deducted'];
    }
    expect($sum)->toBe(100000);

    $deducted2024 = (int) $this->loanService->feesForYear($loan, 2024)['deducted'];
    $deducted2025 = (int) $this->loanService->feesForYear($loan, 2025)['deducted'];
    $deducted2026 = (int) $this->loanService->feesForYear($loan, 2026)['deducted'];
    expect($deducted2024)->toBeGreaterThan($deducted2025)
        ->and($deducted2025)->toBeGreaterThan($deducted2026);
});

it('falls back to the linear method when the loan has no amortization schedule, and signals it', function () {
    $this->user->forceFill(['loan_fees_spread_from' => 2024])->save();

    $property = methodLoanProperty($this->user);
    $loan = methodLoan($property, [
        'duration_months' => 120,
        'fees_amount' => 120000, // 1 200 €, soit 120 €/an en linéaire
        'fees_paid_on' => '2024-01-01',
        'fees_spread_method' => Loan::FEES_METHOD_INTEREST,
    ]);

    // Aucune échéance : impossible de connaître les intérêts courus année par année.
    expect($loan->payments()->count())->toBe(0);

    expect($this->loanService->feesForYear($loan, 2024))
        ->toBe(['deducted' => '12000', 'remaining_end' => '108000', 'mode' => 'spread']);

    $method = $this->loanService->effectiveFeesSpreadMethod($loan);
    expect($method)->toBe([
        'requested' => Loan::FEES_METHOD_INTEREST,
        'applied' => Loan::FEES_METHOD_LINEAR,
        'fallback' => true,
    ]);
});

it('reports the interest method as applied without fallback once a schedule exists', function () {
    $this->user->forceFill(['loan_fees_spread_from' => 2024])->save();

    $property = methodLoanProperty($this->user);
    $loan = methodLoan($property, [
        'duration_months' => 36,
        'fees_amount' => 100000,
        'fees_paid_on' => '2024-01-01',
        'fees_spread_method' => Loan::FEES_METHOD_INTEREST,
    ]);
    methodPayment($loan, '2024-06-01', 1, 60000, 800000);

    $method = $this->loanService->effectiveFeesSpreadMethod($loan);
    expect($method)->toBe([
        'requested' => Loan::FEES_METHOD_INTEREST,
        'applied' => Loan::FEES_METHOD_INTEREST,
        'fallback' => false,
    ]);
});

// --- Remboursement anticipé (§ 120-130) ------------------------------------------------

/**
 * Exemple numérique (style BOFiP) : frais de 1 000 €, étalés linéairement sur 10 ans
 * (100 €/an, chiffres ronds — aucune troncature bcmath ici, pour isoler le seul effet du
 * remboursement anticipé). Capital emprunté 10 000 €, dont il ne reste que 4 000 € juste
 * avant le remboursement anticipé de 2027 (après les échéances 2024 à 2026).
 */
function earlyRepaymentLoan(User $user): Loan
{
    $property = methodLoanProperty($user);
    $loan = methodLoan($property, [
        'amount' => 1000000, // 10 000 €
        'duration_months' => 120, // 10 ans
        'fees_amount' => 100000, // 1 000 €, 100 €/an
        'fees_paid_on' => '2024-01-01',
    ]);

    methodPayment($loan, '2024-06-01', 1, 0, 700000); // 7 000 € restants fin 2024
    methodPayment($loan, '2025-06-01', 13, 0, 550000); // 5 500 € restants fin 2025
    methodPayment($loan, '2026-06-01', 25, 0, 400000); // 4 000 € restants fin 2026

    return $loan;
}

it('makes the whole remaining fees balance deductible the year of a full early repayment', function () {
    $this->user->forceFill(['loan_fees_spread_from' => 2024])->save();
    $loan = earlyRepaymentLoan($this->user);

    // Remboursement total du capital restant dû (4 000 €) au 1er janvier 2027.
    $loan->forceFill(['early_repayment_date' => '2027-01-01', 'early_repayment_amount' => 400000])->save();

    // 2024-2026 inchangés : 100 €/an (10 000 centimes).
    foreach ([2024, 2025, 2026] as $year) {
        expect((int) $this->loanService->feesForYear($loan, $year)['deducted'])->toBe(10000);
    }

    // Solde avant remboursement : 100 000 − 3 × 10 000 = 70 000. Remboursement intégral
    // (4 000 € remboursés sur 4 000 € restants dus) : tout le solde devient déductible en 2027.
    expect($this->loanService->feesForYear($loan, 2027))
        ->toBe(['deducted' => '70000', 'remaining_end' => '0', 'mode' => 'spread']);

    // Plus rien les années suivantes.
    foreach ([2028, 2030, 2033] as $year) {
        expect($this->loanService->feesForYear($loan, $year))
            ->toBe(['deducted' => '0', 'remaining_end' => '0', 'mode' => 'spread']);
    }

    $sum = 0;
    for ($year = 2024; $year <= 2033; $year++) {
        $sum += (int) $this->loanService->feesForYear($loan, $year)['deducted'];
    }
    expect($sum)->toBe(100000);
});

it('accelerates only the proportional share of the balance on a partial early repayment, the repayment year included in the re-spread', function () {
    $this->user->forceFill(['loan_fees_spread_from' => 2024])->save();
    $loan = earlyRepaymentLoan($this->user);

    // Remboursement PARTIEL : 2 000 € sur les 4 000 € restants dus (moitié) au 1er janvier 2027.
    $loan->forceFill(['early_repayment_date' => '2027-01-01', 'early_repayment_amount' => 200000])->save();

    foreach ([2024, 2025, 2026] as $year) {
        expect((int) $this->loanService->feesForYear($loan, $year)['deducted'])->toBe(10000);
    }

    // Solde avant remboursement = 70 000. Part accélérée = 70 000 × 2 000 / 4 000 = 35 000.
    // Reste à ré-étaler : 35 000, sur les 7 années 2027 À 2033 INCLUSES (§ 130 : l'année du
    // remboursement est la première du ré-étalement, pas la suivante) : 35 000 / 7 = 5 000
    // exactement. L'année 2027 cumule donc la part accélérée ET sa propre fraction de
    // ré-étalement : 35 000 + 5 000 = 40 000.
    expect($this->loanService->feesForYear($loan, 2027))
        ->toBe(['deducted' => '40000', 'remaining_end' => '30000', 'mode' => 'spread']);

    foreach ([2028, 2029, 2030, 2031, 2032, 2033] as $year) {
        expect((int) $this->loanService->feesForYear($loan, $year)['deducted'])->toBe(5000);
    }
    expect($this->loanService->feesForYear($loan, 2033))
        ->toBe(['deducted' => '5000', 'remaining_end' => '0', 'mode' => 'spread']);

    $sum = 0;
    for ($year = 2024; $year <= 2033; $year++) {
        $sum += (int) $this->loanService->feesForYear($loan, $year)['deducted'];
    }
    expect($sum)->toBe(100000);
});

/**
 * Exemple chiffré LITTÉRAL de BOI-BIC-CHG-20-30-40 § 130 : prêt de 15 000 000 €, 12 ans,
 * frais de 90 000 € étalés linéairement (7 500 €/an). Après quatre fractions (30 000 €
 * déduits, 60 000 € restants), un tiers du capital (5 000 000 € sur 15 000 000 € restant dû)
 * est remboursé par anticipation en année 5. Déduction supplémentaire = 20 000 € (un tiers
 * des 60 000 € restants) ; le reste (40 000 €) est ré-étalé sur les 8 années restantes — 5 À
 * 12 INCLUSES, pas 6 à 12 — soit 5 000 €/an. Année 5 = 20 000 + 5 000 = 25 000 €.
 */
it('replicates the literal BOI-BIC-CHG-20-30-40 paragraph 130 example, year by year, in cents', function () {
    $this->user->forceFill(['loan_fees_spread_from' => 2024])->save();

    $property = methodLoanProperty($this->user);
    $loan = methodLoan($property, [
        'amount' => 1500000000, // 15 000 000 €
        'duration_months' => 144, // 12 ans
        'fees_amount' => 9000000, // 90 000 €
        'fees_paid_on' => '2024-01-01',
        // Aucune échéance créée : le capital restant dû avant le remboursement anticipé
        // retombe donc sur le capital initial (15 000 000 €), comme le BOFiP le pose.
    ]);
    $loan->forceFill(['early_repayment_date' => '2028-01-01', 'early_repayment_amount' => 500000000])->save(); // 5 000 000 €

    expect($loan->capitalOutstandingBefore($loan->early_repayment_date))->toBe(1500000000);

    $expectedByYear = [
        2024 => 750000,  // 7 500 €
        2025 => 750000,
        2026 => 750000,
        2027 => 750000,
        2028 => 2500000, // 25 000 € = 20 000 € accélérés + 5 000 € de ré-étalement
        2029 => 500000,  // 5 000 €
        2030 => 500000,
        2031 => 500000,
        2032 => 500000,
        2033 => 500000,
        2034 => 500000,
        2035 => 500000,
    ];

    $sum = 0;
    foreach ($expectedByYear as $year => $expected) {
        $deducted = (int) $this->loanService->feesForYear($loan, $year)['deducted'];
        expect($deducted)->toBe($expected, "année {$year}");
        $sum += $deducted;
    }

    expect($sum)->toBe(9000000); // 90 000 €, à l'euro (centime) près
});

it('leaves the fees deduction untouched when the early repayment happened before the spread took effect', function () {
    // L'option du dossier ne prend effet qu'à partir de 2025 : les frais, payés en 2024,
    // restent déduits en une fois — le remboursement anticipé est donc sans objet.
    $this->user->forceFill(['loan_fees_spread_from' => 2025])->save();
    $loan = earlyRepaymentLoan($this->user);
    $loan->forceFill(['early_repayment_date' => '2027-01-01', 'early_repayment_amount' => 400000])->save();

    expect($this->loanService->feesForYear($loan, 2024))
        ->toBe(['deducted' => '100000', 'remaining_end' => '0', 'mode' => 'immediate']);
});

it('ignores an early repayment recorded outside the spread period', function () {
    $this->user->forceFill(['loan_fees_spread_from' => 2024])->save();
    $loan = earlyRepaymentLoan($this->user);

    // Remboursement en 2040, largement après le terme du prêt (2033) : sans effet, les
    // fractions linéaires normales continuent de s'appliquer jusqu'au bout.
    $loan->forceFill(['early_repayment_date' => '2040-01-01', 'early_repayment_amount' => 1])->save();

    expect($this->loanService->feesForYear($loan, 2033))
        ->toBe(['deducted' => '10000', 'remaining_end' => '0', 'mode' => 'spread']);
});

// --- Cohérence de la liasse après remboursement anticipé -------------------------------

it('keeps the 2033-B result check (checkResultat) in balance after an accelerated fees deduction', function () {
    $this->user->forceFill(['loan_fees_spread_from' => 2024])->save();

    $property = methodLoanProperty($this->user, [
        'rental_start_date' => '2024-01-01',
        'acquisition_date' => '2024-01-01',
    ]);
    $loan = methodLoan($property, [
        'amount' => 1000000,
        'duration_months' => 120,
        'fees_amount' => 100000,
        'fees_paid_on' => '2024-01-01',
    ]);
    methodPayment($loan, '2024-06-01', 1, 0, 700000);
    methodPayment($loan, '2025-06-01', 13, 0, 550000);
    methodPayment($loan, '2026-06-01', 25, 0, 400000);
    $loan->forceFill(['early_repayment_date' => '2027-01-01', 'early_repayment_amount' => 200000])->save();

    app(\App\Services\DepreciationService::class)->generateDefaultComponents($property);

    $tax = app(TaxReturnService::class);
    $fiscalYearService = app(FiscalYearService::class);

    $fy = null;
    foreach ([2024, 2025, 2026, 2027] as $year) {
        $fy = $fiscalYearService->getOrCreate($this->user, $year);
    }

    $properties = Property::withoutGlobalScopes()->where('user_id', $this->user->id)->get();
    $checks = $tax->checks(
        $tax->compute2033A($fy, $properties, 2027),
        $tax->compute2033B($fy, $properties, 2027),
        $tax->compute2033C($properties, 2027),
        $properties,
    );

    $resultat = collect($checks)->firstWhere('id', 'resultat');
    expect($resultat['status'])->toBe(TaxReturnService::CHECK_OK)
        ->and($resultat['delta'])->toBe(0);
});
