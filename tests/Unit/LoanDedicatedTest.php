<?php

use App\Models\Loan;
use App\Models\Property;
use App\Models\User;
use App\Services\FiscalYearService;
use App\Services\LoanService;
use App\Services\TaxReturnService;

/**
 * `Loan::is_dedicated` — un emprunt dédié finance EXCLUSIVEMENT le logement loué et
 * n'est donc jamais proratisé par la quote-part de surface, contrairement à un
 * emprunt partagé (ex : achat de toute la résidence principale).
 *
 * Couvre aussi la non-régression visée par la mémoire du projet sur la quote-part
 * appliquée deux fois (`reference_quota_part_appliquee_deux_fois`) : un emprunt
 * dédié doit rester à 100 % partout où la quote-part s'applique d'ordinaire
 * (résultat fiscal, ligne 294 de la liasse), jamais 0 % ni une quote-part au carré.
 */
beforeEach(function () {
    $this->user = User::factory()->create();
    $this->fiscalYearService = app(FiscalYearService::class);
    $this->loanService = app(LoanService::class);
    $this->taxReturnService = app(TaxReturnService::class);
});

function loanQuotaProperty(User $user, array $overrides = []): Property
{
    return Property::forceCreate(array_merge([
        'user_id' => $user->id,
        'name' => 'Bien emprunt',
        'address' => '1 rue Test',
        'city' => 'Paris',
        'postal_code' => '75001',
        'type' => 'apartment',
        'total_area' => 100,
        'rented_area' => 50, // quote-part exacte de 50 %
        'acquisition_date' => '2022-01-01',
        'acquisition_price' => 20000000,
        'notary_fees' => 0,
        'land_percentage' => 0,
        'rental_start_date' => '2022-01-01',
        'rental_type' => 'seasonal',
        'is_primary_residence' => true,
    ], $overrides));
}

function makeQuotaTestLoan(Property $property, bool $isDedicated): Loan
{
    return Loan::create([
        'property_id' => $property->id,
        'bank_name' => 'Banque',
        'amount' => 10000000,
        'annual_rate' => 2.0,
        'duration_months' => 120,
        'start_date' => '2024-01-01',
        'monthly_payment' => 0,
        'insurance_monthly' => 0,
        'is_dedicated' => $isDedicated,
    ]);
}

it('defaults is_dedicated to false, preserving the historical prorated behaviour', function () {
    $property = loanQuotaProperty($this->user);
    $loan = Loan::create([
        'property_id' => $property->id,
        'bank_name' => 'Banque',
        'amount' => 10000000,
        'annual_rate' => 2.0,
        'duration_months' => 120,
        'start_date' => '2024-01-01',
        'monthly_payment' => 0,
        'insurance_monthly' => 0,
    ]);

    // Eloquent ne relit pas le défaut posé par la colonne après un create() : c'est
    // la ligne en base qui fait foi, pas l'objet tel qu'il sort de l'insertion.
    expect($loan->fresh()->is_dedicated)->toBeFalse();
});

it('applies the quota share exactly once to a shared loan\'s fiscal result', function () {
    $property = loanQuotaProperty($this->user);
    $loan = makeQuotaTestLoan($property, isDedicated: false);
    $this->loanService->generateSchedule($loan);

    $totalInterest = $loan->payments()->whereYear('payment_date', 2024)->sum('interest_amount');
    $fiscalYear = $this->fiscalYearService->getOrCreate($this->user, 2024);

    // Quote-part = 50 % exactement une fois : ni le montant brut (pas de quote-part),
    // ni son carré (quote-part appliquée deux fois).
    $expected = (int) bcmul((string) $totalInterest, '0.5', 0);
    expect($fiscalYear->total_expenses)->toBe($expected)
        ->and($fiscalYear->total_expenses)->not->toBe($totalInterest)
        ->and($fiscalYear->total_expenses)->not->toBe((int) bcmul((string) $totalInterest, '0.25', 0));
});

it('deducts a dedicated loan in full, without any quota share', function () {
    $property = loanQuotaProperty($this->user);
    $loan = makeQuotaTestLoan($property, isDedicated: true);
    $this->loanService->generateSchedule($loan);

    $totalInterest = $loan->payments()->whereYear('payment_date', 2024)->sum('interest_amount');
    $fiscalYear = $this->fiscalYearService->getOrCreate($this->user, 2024);

    expect($fiscalYear->total_expenses)->toBe($totalInterest);
});

it('keeps a dedicated loan at 100% on the 2033-B line 294 (financial charges)', function () {
    $property = loanQuotaProperty($this->user);
    $dedicated = makeQuotaTestLoan($property, isDedicated: true);
    $this->loanService->generateSchedule($dedicated);

    $fiscalYear = $this->fiscalYearService->getOrCreate($this->user, 2024);
    $properties = Property::withoutGlobalScopes()->where('user_id', $this->user->id)->get();
    $form2033B = $this->taxReturnService->compute2033B($fiscalYear, $properties, 2024);

    $totalInterest = $dedicated->getInterestsForYear(2024);
    $totalInsurance = $dedicated->getInsuranceForYear(2024);

    expect($form2033B['294'])->toBe($totalInterest + $totalInsurance);
});

it('the prorate() helper never squares the quota share, on either branch', function () {
    $property = loanQuotaProperty($this->user);
    $quota = $property->quota_share; // '0.5000000000'

    $dedicated = new Loan(['is_dedicated' => true]);
    $shared = new Loan(['is_dedicated' => false]);

    expect($dedicated->prorate('100000', $quota))->toBe('100000')
        ->and($shared->prorate('100000', $quota))->toBe('50000');
});
