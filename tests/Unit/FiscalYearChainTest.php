<?php

use App\Models\Expense;
use App\Models\FiscalYear;
use App\Models\Income;
use App\Models\Property;
use App\Models\User;
use App\Services\DepreciationService;
use App\Services\FiscalYearService;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->service = app(FiscalYearService::class);
});

function makeChainProperty(User $user, string $acquisitionDate, string $rentalStartDate): Property
{
    return Property::forceCreate([
        'user_id' => $user->id,
        'name' => 'Bien Chaîne',
        'address' => '1 rue Test',
        'city' => 'Paris',
        'postal_code' => '75001',
        'type' => 'apartment',
        'total_area' => 100,
        'rented_area' => 100,
        'acquisition_date' => $acquisitionDate,
        'acquisition_price' => 10000000,
        'notary_fees' => 0,
        'market_value' => null,
        'land_percentage' => 0,
        'rental_start_date' => $rentalStartDate,
        'rental_type' => 'seasonal',
        'is_primary_residence' => false,
    ]);
}

// === firstDataYear ===

it('uses the acquisition year as first data year', function () {
    makeChainProperty($this->user, '2020-06-15', '2022-06-01');

    expect($this->service->firstDataYear($this->user))->toBe(2020);
});

it('uses the earliest income year when it predates the property dates', function () {
    $property = makeChainProperty($this->user, '2021-01-01', '2021-06-01');

    Income::create([
        'property_id' => $property->id,
        'income_date' => '2019-07-15',
        'amount' => 100000,
        'platform_fee' => 3000,
        'tourist_tax' => 0,
        'source' => 'airbnb',
    ]);

    expect($this->service->firstDataYear($this->user))->toBe(2019);
});

it('uses the earliest expense year when it predates the other dates', function () {
    $property = makeChainProperty($this->user, '2021-01-01', '2021-06-01');

    Expense::create([
        'property_id' => $property->id,
        'expense_date' => '2018-03-01',
        'amount' => 50000,
        'category' => 'maintenance',
        'description' => 'Travaux préparatoires',
        'is_dedicated' => true,
        'recurring_type' => 'once',
    ]);

    expect($this->service->firstDataYear($this->user))->toBe(2018);
});

it('falls back to the current year without any data', function () {
    expect($this->service->firstDataYear($this->user))->toBe((int) date('Y'));
});

// === firstActivityYear ===

it('anchors the chain on the rental start year, not the acquisition year', function () {
    $property = makeChainProperty($this->user, '2020-06-15', '2022-06-01');
    app(DepreciationService::class)->generateDefaultComponents($property);

    // L'acquisition (2020) élargit la liste d'années…
    expect($this->service->firstDataYear($this->user))->toBe(2020)
        // …mais la chaîne d'exercices démarre à la mise en location (2022).
        ->and($this->service->firstActivityYear($this->user))->toBe(2022)
        ->and($this->service->missingPreviousYearError($this->user, 2022))->toBeNull()
        ->and($this->service->nextYearToCreate($this->user))->toBe(2022);
});

// === missingPreviousYearError ===

it('allows creating the first fiscal year of the chain without a predecessor', function () {
    $property = makeChainProperty($this->user, '2022-01-01', '2022-06-01');
    app(DepreciationService::class)->generateDefaultComponents($property);

    expect($this->service->missingPreviousYearError($this->user, 2022))->toBeNull();
});

it('blocks a later year when the previous fiscal year is missing', function () {
    $property = makeChainProperty($this->user, '2022-01-01', '2022-06-01');
    app(DepreciationService::class)->generateDefaultComponents($property);

    expect($this->service->missingPreviousYearError($this->user, 2024))
        ->toContain('L\'exercice 2023 n\'existe pas');
});

it('allows a later year when the previous fiscal year exists', function () {
    $property = makeChainProperty($this->user, '2022-01-01', '2022-06-01');
    app(DepreciationService::class)->generateDefaultComponents($property);

    FiscalYear::forceCreate([
        'user_id' => $this->user->id,
        'year' => 2023,
        'status' => FiscalYear::STATUS_CLOSED,
    ]);

    expect($this->service->missingPreviousYearError($this->user, 2024))->toBeNull();
});

it('allows any year without a predecessor when no depreciation exists', function () {
    makeChainProperty($this->user, '2022-01-01', '2022-06-01');

    expect($this->service->missingPreviousYearError($this->user, 2024))->toBeNull();
});

// === nextYearToCreate ===

it('proposes the first data year when no fiscal year exists', function () {
    makeChainProperty($this->user, '2022-01-01', '2022-06-01');

    expect($this->service->nextYearToCreate($this->user))->toBe(2022);
});

it('proposes the first missing year of the chain', function () {
    makeChainProperty($this->user, '2022-01-01', '2022-06-01');

    FiscalYear::forceCreate([
        'user_id' => $this->user->id,
        'year' => 2022,
        'status' => FiscalYear::STATUS_CLOSED,
    ]);

    expect($this->service->nextYearToCreate($this->user))->toBe(2023);
});

it('proposes the previous year when the chain is complete', function () {
    makeChainProperty($this->user, '2022-01-01', '2022-06-01');

    $currentYear = (int) date('Y');
    for ($y = 2022; $y < $currentYear; $y++) {
        FiscalYear::forceCreate([
            'user_id' => $this->user->id,
            'year' => $y,
            'status' => FiscalYear::STATUS_CLOSED,
        ]);
    }

    expect($this->service->nextYearToCreate($this->user))->toBe($currentYear - 1);
});

// === nextYearToCreate après une reprise ===

function makeChainRepriseYear(User $user, int $year, string $status = FiscalYear::STATUS_DRAFT): FiscalYear
{
    return FiscalYear::forceCreate([
        'user_id' => $user->id,
        'year' => $year,
        'status' => $status,
        'opening_deferred_depreciation' => 0,
        'opening_source' => FiscalYear::OPENING_SOURCE_MANUAL,
    ]);
}

it('proposes the year that follows the reprise, not the year declared by the accountant', function () {
    // Liasse N-1 déposée par le cabinet, reprise portée par l'exercice N (brouillon).
    // Avant le correctif, la boucle ne tournait pas et l'assistant proposait N-1.
    $currentYear = (int) date('Y');
    $property = makeChainProperty($this->user, '2019-03-01', '2019-06-01');
    app(DepreciationService::class)->generateDefaultComponents($property);

    makeChainRepriseYear($this->user, $currentYear);

    expect($this->service->nextYearToCreate($this->user))->toBe($currentYear);
});

it('skips the years closed after an older reprise', function () {
    $currentYear = (int) date('Y');
    $property = makeChainProperty($this->user, '2019-03-01', '2019-06-01');
    app(DepreciationService::class)->generateDefaultComponents($property);

    makeChainRepriseYear($this->user, $currentYear - 3, FiscalYear::STATUS_CLOSED);
    FiscalYear::forceCreate(['user_id' => $this->user->id, 'year' => $currentYear - 2, 'status' => FiscalYear::STATUS_CLOSED]);

    expect($this->service->nextYearToCreate($this->user))->toBe($currentYear - 1);

    // Une fois N-1 clôturé, on ne propose pas de le reclôturer : c'est au tour de N.
    FiscalYear::forceCreate(['user_id' => $this->user->id, 'year' => $currentYear - 1, 'status' => FiscalYear::STATUS_CLOSED]);

    expect($this->service->nextYearToCreate($this->user))->toBe($currentYear);
});

it('proposes a draft that follows an older reprise rather than a later missing year', function () {
    $currentYear = (int) date('Y');
    $property = makeChainProperty($this->user, '2019-03-01', '2019-06-01');
    app(DepreciationService::class)->generateDefaultComponents($property);

    makeChainRepriseYear($this->user, $currentYear - 2, FiscalYear::STATUS_CLOSED);
    FiscalYear::forceCreate(['user_id' => $this->user->id, 'year' => $currentYear - 1, 'status' => FiscalYear::STATUS_DRAFT]);

    expect($this->service->nextYearToCreate($this->user))->toBe($currentYear - 1);
});

it('keeps proposing the first missing year when nothing was taken over', function () {
    // Contre-épreuve : un exercice sans provenance de reprise ne change rien à la règle.
    $currentYear = (int) date('Y');
    $property = makeChainProperty($this->user, '2019-03-01', '2019-06-01');
    app(DepreciationService::class)->generateDefaultComponents($property);

    FiscalYear::forceCreate([
        'user_id' => $this->user->id,
        'year' => $currentYear - 3,
        'status' => FiscalYear::STATUS_CLOSED,
        'opening_accumulated_depreciation' => 4_739_400,
    ]);

    expect($this->service->nextYearToCreate($this->user))->toBe($currentYear - 2);
});
