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

it('calculates fiscal result with income and expenses', function () {
    $property = Property::forceCreate([
        'user_id' => $this->user->id,
        'name' => 'Test',
        'address' => '1 rue Test',
        'city' => 'Paris',
        'postal_code' => '75001',
        'type' => 'apartment',
        'total_area' => 100,
        'rented_area' => 100,
        'acquisition_date' => '2020-01-01',
        'acquisition_price' => 10000000,
        'notary_fees' => 0,
        'market_value' => null,
        'land_percentage' => 0,
        'rental_start_date' => '2023-01-01',
        'rental_type' => 'seasonal',
        'is_primary_residence' => false,
    ]);

    app(DepreciationService::class)->generateDefaultComponents($property);

    // Ajouter des recettes : 2000€
    Income::create([
        'property_id' => $property->id,
        'income_date' => '2024-06-15',
        'amount' => 200000, // 2000€
        'platform_fee' => 6000, // 60€
        'tourist_tax' => 0,
        'source' => 'airbnb',
    ]);

    // Ajouter une charge dédiée : 500€
    Expense::create([
        'property_id' => $property->id,
        'expense_date' => '2024-01-01',
        'amount' => 50000, // 500€
        'category' => 'maintenance',
        'description' => 'Réparation',
        'is_dedicated' => true,
        'recurring_type' => 'once',
    ]);

    $fiscalYear = $this->service->getOrCreate($this->user, 2024);

    // Recettes nettes = 2000 - 60 = 1940€ = 194000 cts
    expect($fiscalYear->total_income)->toBe(194000);

    // Charges = 500€ = 50000 cts
    expect($fiscalYear->total_expenses)->toBe(50000);

    // Résultat fiscal >= 0 (grâce au plafonnement)
    expect($fiscalYear->fiscal_result)->toBeGreaterThanOrEqual(0);
});

it('caps depreciation to prevent deficit', function () {
    $property = Property::forceCreate([
        'user_id' => $this->user->id,
        'name' => 'Test',
        'address' => '1 rue Test',
        'city' => 'Paris',
        'postal_code' => '75001',
        'type' => 'apartment',
        'total_area' => 100,
        'rented_area' => 100,
        'acquisition_date' => '2020-01-01',
        'acquisition_price' => 50000000, // 500k€ — gros amortissement
        'notary_fees' => 0,
        'market_value' => null,
        'land_percentage' => 0,
        'rental_start_date' => '2023-01-01',
        'rental_type' => 'seasonal',
        'is_primary_residence' => false,
    ]);

    app(DepreciationService::class)->generateDefaultComponents($property);

    // Petite recette : 100€
    Income::create([
        'property_id' => $property->id,
        'income_date' => '2024-06-15',
        'amount' => 10000, // 100€
        'platform_fee' => 0,
        'tourist_tax' => 0,
        'source' => 'direct',
    ]);

    $fiscalYear = $this->service->getOrCreate($this->user, 2024);

    // Résultat fiscal = 0 (amortissements plafonnés, excédent différé)
    expect($fiscalYear->fiscal_result)->toBe(0);
    expect($fiscalYear->deferred_depreciation)->toBeGreaterThan(0);
    expect($fiscalYear->capped_depreciation)->toBeLessThanOrEqual($fiscalYear->total_income);
});

it('applies quota share to shared expenses', function () {
    $property = Property::forceCreate([
        'user_id' => $this->user->id,
        'name' => 'Test RP',
        'address' => '1 rue Test',
        'city' => 'Paris',
        'postal_code' => '75001',
        'type' => 'room',
        'total_area' => 100,
        'rented_area' => 25, // 25% quote-part
        'acquisition_date' => '2020-01-01',
        'acquisition_price' => 10000000,
        'notary_fees' => 0,
        'market_value' => null,
        'land_percentage' => 0,
        'rental_start_date' => '2023-01-01',
        'rental_type' => 'seasonal',
        'is_primary_residence' => true,
    ]);

    app(DepreciationService::class)->generateDefaultComponents($property);

    Income::create([
        'property_id' => $property->id,
        'income_date' => '2024-06-15',
        'amount' => 2000000, // 20 000€
        'platform_fee' => 0,
        'tourist_tax' => 0,
        'source' => 'airbnb',
    ]);

    // Charge partagée : 1000€
    Expense::create([
        'property_id' => $property->id,
        'expense_date' => '2024-01-01',
        'amount' => 100000, // 1000€
        'category' => 'property_tax',
        'description' => 'Taxe foncière',
        'is_dedicated' => false, // partagée → quote-part 25%
        'recurring_type' => 'yearly',
    ]);

    $fiscalYear = $this->service->getOrCreate($this->user, 2024);

    // Charges effectives = 1000€ * 25% = 250€ = 25000 cts
    expect($fiscalYear->total_expenses)->toBe(25000);
});

it('compares micro-bic vs real regime', function () {
    $property = Property::forceCreate([
        'user_id' => $this->user->id,
        'name' => 'Test',
        'address' => '1 rue Test',
        'city' => 'Paris',
        'postal_code' => '75001',
        'type' => 'apartment',
        'total_area' => 100,
        'rented_area' => 100,
        'acquisition_date' => '2020-01-01',
        'acquisition_price' => 30000000,
        'notary_fees' => 0,
        'market_value' => null,
        'land_percentage' => 15,
        'rental_start_date' => '2023-01-01',
        'rental_type' => 'seasonal',
        'is_primary_residence' => false,
    ]);

    app(DepreciationService::class)->generateDefaultComponents($property);

    Income::create([
        'property_id' => $property->id,
        'income_date' => '2024-06-15',
        'amount' => 2000000, // 20 000€
        'platform_fee' => 60000,
        'tourist_tax' => 0,
        'source' => 'airbnb',
    ]);

    $comparison = $this->service->compareMicroBicVsReal($this->user, 2024, '50');

    expect($comparison['gross_income'])->toBe('2000000');
    expect($comparison['micro_bic_result'])->toBe('1000000'); // 20k * 50% = 10k€
    expect($comparison['recommended'])->toBeIn(['real', 'micro_bic']);
});
