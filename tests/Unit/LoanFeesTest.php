<?php

use App\Models\AccountingEntry;
use App\Models\Loan;
use App\Models\Property;
use App\Models\User;
use App\Services\FiscalYearService;
use App\Services\LoanService;
use App\Services\TaxReturnService;

/**
 * Frais d'emprunt (art. 39-1-1° quater CGI, BOI-BIC-CHG-20-30-40 § 30-50) : frais de
 * dossier, courtage, part non restituable d'une caution. Déduits en une fois par défaut,
 * ou étalés linéairement sur la durée du prêt si l'utilisateur en a fait le choix
 * (`User::loan_fees_spread_from`, option GLOBALE au dossier).
 */
function feesLoanProperty(User $user, array $extra = []): Property
{
    return Property::forceCreate(array_merge([
        'user_id' => $user->id,
        'name' => 'Bien à frais',
        'address' => '7 rue des Frais',
        'city' => 'Lyon',
        'postal_code' => '69003',
        'type' => 'apartment',
        'total_area' => 100,
        'rented_area' => 100, // quote-part = 1, sauf override
        'acquisition_date' => '2022-01-01',
        'acquisition_price' => 20000000,
        'notary_fees' => 0,
        'land_percentage' => 0,
        'rental_start_date' => '2022-01-01',
        'rental_type' => 'seasonal',
        'is_primary_residence' => false,
    ], $extra));
}

function feesLoan(Property $property, array $extra = []): Loan
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
    ], $extra));
}

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->loanService = app(LoanService::class);
});

it('deducts loan fees in full the year they are paid when no spread option is set', function () {
    $property = feesLoanProperty($this->user);
    $loan = feesLoan($property, [
        'fees_amount' => 120000, // 1 200 €
        'fees_paid_on' => '2024-03-15',
    ]);

    expect($this->loanService->feesForYear($loan, 2024))
        ->toBe(['deducted' => '120000', 'remaining_end' => '0', 'mode' => 'immediate'])
        ->and($this->loanService->feesForYear($loan, 2025))
        ->toBe(['deducted' => '0', 'remaining_end' => '0', 'mode' => 'immediate']);
});

it('spreads loan fees linearly over the loan duration once the dossier option covers the payment year', function () {
    $this->user->forceFill(['loan_fees_spread_from' => 2024])->save();

    $property = feesLoanProperty($this->user);
    $loan = feesLoan($property, [
        'duration_months' => 120, // 10 ans
        'fees_amount' => 120000, // 1 200 €, soit 120 € / an
        'fees_paid_on' => '2024-01-10',
    ]);

    // Année de paiement (index 0) comme chacune des 9 suivantes : 120 €/an, sans prorata
    // temporis (§ 70). La dernière année (2033) absorbe le reliquat de troncature.
    expect($this->loanService->feesForYear($loan, 2024))
        ->toBe(['deducted' => '12000', 'remaining_end' => '108000', 'mode' => 'spread'])
        ->and($this->loanService->feesForYear($loan, 2028))
        ->toBe(['deducted' => '12000', 'remaining_end' => '60000', 'mode' => 'spread'])
        ->and($this->loanService->feesForYear($loan, 2033))
        ->toBe(['deducted' => '12000', 'remaining_end' => '0', 'mode' => 'spread'])
        ->and($this->loanService->feesForYear($loan, 2034))
        ->toBe(['deducted' => '0', 'remaining_end' => '0', 'mode' => 'spread'])
        ->and($this->loanService->feesForYear($loan, 2023))
        ->toBe(['deducted' => '0', 'remaining_end' => '0', 'mode' => 'spread']);
});

it('ignores the spread option for fees paid before it takes effect', function () {
    $this->user->forceFill(['loan_fees_spread_from' => 2025])->save();

    $property = feesLoanProperty($this->user);
    $loan = feesLoan($property, [
        'fees_amount' => 50000,
        'fees_paid_on' => '2024-06-01', // avant l'année d'effet de l'option
    ]);

    expect($this->loanService->feesForYear($loan, 2024))
        ->toBe(['deducted' => '50000', 'remaining_end' => '0', 'mode' => 'immediate']);
});

it('absorbs the bcmath rounding remainder in the last year of the spread, exactly', function () {
    $this->user->forceFill(['loan_fees_spread_from' => 2024])->save();

    $property = feesLoanProperty($this->user);
    // 130 mois = 11 années civiles (ceil(130/12)) ; 100 000 / 130 mois * 12 = 9 230,76... €
    // tronqués à 9 230 € : les 10 premières années ne totalisent que 92 300 €, la 11e doit
    // récupérer le reliquat de 7 700 € pour retomber exactement sur fees_amount.
    $loan = feesLoan($property, [
        'duration_months' => 130,
        'fees_amount' => 100000,
        'fees_paid_on' => '2024-01-01',
    ]);

    $sum = 0;
    for ($year = 2024; $year <= 2035; $year++) {
        $sum += (int) $this->loanService->feesForYear($loan, $year)['deducted'];
    }

    expect($this->loanService->feesForYear($loan, 2033)) // 10e année (index 9)
        ->toBe(['deducted' => '9230', 'remaining_end' => '7700', 'mode' => 'spread'])
        ->and($this->loanService->feesForYear($loan, 2034)) // 11e et dernière année (index 10)
        ->toBe(['deducted' => '7700', 'remaining_end' => '0', 'mode' => 'spread'])
        ->and($sum)->toBe(100000);
});

it('adds the immediate fees to the fiscal result, quote-part applied', function () {
    $property = feesLoanProperty($this->user, ['rented_area' => 50]); // quote-part 50 %
    feesLoan($property, [
        'fees_amount' => 100000, // 1 000 €
        'fees_paid_on' => '2024-05-01',
    ]);

    $fy = app(FiscalYearService::class)->getOrCreate($this->user, 2024);
    $totals = app(FiscalYearService::class)->computeTotals($fy);

    expect($totals['total_expenses'])->toBe(50000); // 1 000 € × 50 %
});

it('adds the spread fees fraction (not the whole amount) to the fiscal result', function () {
    $this->user->forceFill(['loan_fees_spread_from' => 2024])->save();

    $property = feesLoanProperty($this->user); // quote-part 100 %
    feesLoan($property, [
        'duration_months' => 120,
        'fees_amount' => 120000, // 1 200 €, soit 120 €/an
        'fees_paid_on' => '2024-01-01',
    ]);

    $fy = app(FiscalYearService::class)->getOrCreate($this->user, 2024);
    $totals = app(FiscalYearService::class)->computeTotals($fy);

    expect($totals['total_expenses'])->toBe(12000); // 120 €, pas les 1 200 € payés
});

it('puts immediate fees on line 242 of the 2033-B, not 294', function () {
    $property = feesLoanProperty($this->user, ['name' => 'Bien immédiat']);
    feesLoan($property, [
        'fees_amount' => 60000,
        'fees_paid_on' => '2024-02-01',
    ]);

    $fy = app(FiscalYearService::class)->getOrCreate($this->user, 2024);
    $properties = Property::withoutGlobalScopes()->where('user_id', $this->user->id)->get();
    $form = app(TaxReturnService::class)->compute2033B($fy, $properties, 2024);

    expect($form['242'])->toBe(60000)
        ->and($form['294'])->toBe(0);
});

it('puts spread fees on line 294 of the 2033-B, not 242', function () {
    // Option GLOBALE au dossier (`User::loan_fees_spread_from`) : dès qu'elle est activée,
    // TOUS les emprunts dont les frais sont payés à partir de cette année basculent, pas
    // seulement un emprunt choisi un par un.
    $this->user->forceFill(['loan_fees_spread_from' => 2024])->save();

    $property = feesLoanProperty($this->user, ['name' => 'Bien étalé']);
    feesLoan($property, [
        'duration_months' => 120,
        'fees_amount' => 120000, // 1/10e par an = 12 000 € en 2024
        'fees_paid_on' => '2024-01-01',
    ]);

    $fy = app(FiscalYearService::class)->getOrCreate($this->user, 2024);
    $properties = Property::withoutGlobalScopes()->where('user_id', $this->user->id)->get();
    $form = app(TaxReturnService::class)->compute2033B($fy, $properties, 2024);

    expect($form['242'])->toBe(0)
        ->and($form['294'])->toBe(12000);
});

it('carries the non-amortized spread balance on line 092 of the 2033-A, zero in immediate mode', function () {
    $this->user->forceFill(['loan_fees_spread_from' => 2024])->save();

    $property = feesLoanProperty($this->user);
    feesLoan($property, [
        'duration_months' => 120,
        'fees_amount' => 120000, // 1 200 €, 120 €/an, solde restant = 1 080 € fin 2024
        'fees_paid_on' => '2024-01-01',
    ]);

    $fy = app(FiscalYearService::class)->getOrCreate($this->user, 2024);
    $properties = Property::withoutGlobalScopes()->where('user_id', $this->user->id)->get();
    $formA = app(TaxReturnService::class)->compute2033A($fy, $properties, 2024);

    expect($formA['092'])->toBe(108000);

    // Dix ans plus tard, le solde est épuisé.
    $fyLast = app(FiscalYearService::class)->getOrCreate($this->user, 2033);
    $formALast = app(TaxReturnService::class)->compute2033A($fyLast, $properties, 2033);

    expect($formALast['092'])->toBe(0);
});

it('books immediate fees on 6272 debit / 512 credit', function () {
    $property = feesLoanProperty($this->user);
    $loan = feesLoan($property, [
        'fees_amount' => 45000,
        'fees_paid_on' => '2024-04-10',
    ]);

    $fy = app(FiscalYearService::class)->getOrCreate($this->user, 2024);

    expect((int) $fy->accountingEntries()->where('account_code', '6272')->sum('debit'))->toBe(45000)
        ->and((int) $fy->accountingEntries()->where('account_code', '481')->sum('debit'))->toBe(0)
        ->and((int) $fy->accountingEntries()->sum('debit'))
            ->toBe((int) $fy->accountingEntries()->sum('credit'));
});

it('books spread fees on 481/512 at payment, then a yearly 6862/481 dotation', function () {
    $this->user->forceFill(['loan_fees_spread_from' => 2024])->save();

    $property = feesLoanProperty($this->user);
    feesLoan($property, [
        'duration_months' => 120,
        'fees_amount' => 120000,
        'fees_paid_on' => '2024-01-01',
    ]);

    $fy = app(FiscalYearService::class)->getOrCreate($this->user, 2024);

    // L'inscription en charges à répartir (481/512) se fait une seule fois, à la date du
    // paiement, pour le montant TOTAL — pas seulement la fraction 2024.
    expect((int) $fy->accountingEntries()->where('account_code', '481')->where('debit', '>', 0)->sum('debit'))
        ->toBe(120000)
        ->and((int) $fy->accountingEntries()->where('account_code', '6862')->sum('debit'))->toBe(12000)
        ->and((int) $fy->accountingEntries()->where('account_code', '481')->sum('credit'))->toBe(12000)
        ->and((int) $fy->accountingEntries()->sum('debit'))
            ->toBe((int) $fy->accountingEntries()->sum('credit'));

    // L'année suivante : plus d'écriture 481/512 (déjà inscrits en 2024), mais une nouvelle
    // dotation 6862/481.
    $fy2025 = app(FiscalYearService::class)->getOrCreate($this->user, 2025);

    expect((int) $fy2025->accountingEntries()->where('account_code', '481')->where('debit', '>', 0)->sum('debit'))
        ->toBe(0)
        ->and((int) $fy2025->accountingEntries()->where('account_code', '6862')->sum('debit'))->toBe(12000);
});

it('includes interest, insurance and fees in the projected loan charges of a future year', function () {
    $property = feesLoanProperty($this->user, ['rented_area' => 50]); // quote-part 50 %
    $loan = feesLoan($property, [
        'duration_months' => 120,
        'insurance_monthly' => 2000, // 20 €/mois
        'fees_amount' => 120000,
        'fees_paid_on' => '2024-01-01',
    ]);
    $this->loanService->generateSchedule($loan);

    $year = 2026;
    $interest = $loan->getInterestsForYear($year);
    $insurance = $loan->getInsuranceForYear($year);
    $fees = (int) $this->loanService->feesForYear($loan, $year)['deducted'];
    $expected = (int) $loan->prorate((string) ($interest + $insurance + $fees), $property->quota_share);

    expect($expected)->toBeGreaterThan(0)
        ->and($this->loanService->projectedLoanChargesForYear($property, $year))->toBe($expected);
});

it('deducts loan charges (interest, insurance, fees) in the multi-year projection page, not just the depreciation', function () {
    $this->actingAs($this->user);

    $property = feesLoanProperty($this->user);
    $loan = feesLoan($property, [
        'duration_months' => 120,
        'insurance_monthly' => 3000,
        'fees_amount' => 120000,
        'fees_paid_on' => '2024-01-01',
    ]);
    $this->loanService->generateSchedule($loan);

    $component = \Livewire\Livewire::test(\App\Filament\Pages\Projection::class)
        ->set('startYear', 2026)
        ->set('projectionYears', 1);

    $data = $component->instance()->projectionData();
    $expectedLoanCharges = $this->loanService->projectedLoanChargesForYear($property, 2026);

    expect($expectedLoanCharges)->toBeGreaterThan(0)
        ->and($data['rows'][0]['expenses'])->toBeGreaterThanOrEqual($expectedLoanCharges);
});
