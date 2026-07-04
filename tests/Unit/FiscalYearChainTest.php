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
