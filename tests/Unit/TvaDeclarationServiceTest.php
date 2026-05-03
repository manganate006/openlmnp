<?php

use App\Models\Expense;
use App\Models\Income;
use App\Models\Property;
use App\Models\User;
use App\Services\TvaDeclarationService;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->service = new TvaDeclarationService();
});

it('returns empty when no TVA-liable properties', function () {
    Property::forceCreate([
        'user_id' => $this->user->id,
        'name' => 'Bien exempt',
        'address' => '1 rue Test',
        'city' => 'Paris',
        'postal_code' => '75001',
        'type' => 'apartment',
        'total_area' => 100,
        'rented_area' => 100,
        'acquisition_date' => '2020-01-01',
        'acquisition_price' => 20000000,
        'notary_fees' => 0,
        'land_percentage' => 15,
        'rental_start_date' => '2023-01-01',
        'rental_type' => 'seasonal',
        'is_primary_residence' => false,
        'tva_regime' => 'exempt',
    ]);

    $result = $this->service->calculate($this->user, 2026);

    expect($result['properties'])->toBeEmpty();
    expect($result['totals']['collected'])->toBe(0);
});

it('calculates TVA collected and deductible for a liable property', function () {
    $property = Property::forceCreate([
        'user_id' => $this->user->id,
        'name' => 'Para-hotelier',
        'address' => '1 rue Test',
        'city' => 'Paris',
        'postal_code' => '75001',
        'type' => 'apartment',
        'total_area' => 100,
        'rented_area' => 100,
        'acquisition_date' => '2020-01-01',
        'acquisition_price' => 20000000,
        'notary_fees' => 0,
        'land_percentage' => 15,
        'rental_start_date' => '2023-01-01',
        'rental_type' => 'seasonal',
        'is_primary_residence' => false,
        'tva_regime' => 'liable',
    ]);

    // Revenu 1100 EUR TTC a 10% → 1000 HT + 100 TVA
    Income::forceCreate([
        'property_id' => $property->id,
        'income_date' => '2026-03-15',
        'amount' => 110000,
        'tva_rate' => 1000,
        'amount_ht' => 100000,
        'tva_collected' => 10000,
        'platform_fee' => 0,
        'tourist_tax' => 0,
        'source' => 'airbnb',
    ]);

    // Charge 120 EUR TTC a 20% → 100 HT + 20 TVA
    Expense::forceCreate([
        'property_id' => $property->id,
        'expense_date' => '2026-02-10',
        'amount' => 12000,
        'tva_rate' => 2000,
        'amount_ht' => 10000,
        'amount_tva' => 2000,
        'category' => 'cleaning',
        'description' => 'Menage',
        'is_dedicated' => true,
        'recurring_type' => 'once',
    ]);

    $result = $this->service->calculate($this->user, 2026);

    expect($result['totals']['collected'])->toBe(10000);
    expect($result['totals']['deductible'])->toBe(2000);
    expect($result['totals']['balance'])->toBe(8000); // TVA a reverser

    // Ventilation trimestrielle
    expect($result['quarters'][1]['collected'])->toBe(10000); // mars = T1
    expect($result['quarters'][1]['deductible'])->toBe(2000); // fevrier = T1
});

it('calculates quarterly breakdown correctly', function () {
    $property = Property::forceCreate([
        'user_id' => $this->user->id,
        'name' => 'Para-hotelier',
        'address' => '1 rue Test',
        'city' => 'Paris',
        'postal_code' => '75001',
        'type' => 'apartment',
        'total_area' => 100,
        'rented_area' => 100,
        'acquisition_date' => '2020-01-01',
        'acquisition_price' => 20000000,
        'notary_fees' => 0,
        'land_percentage' => 15,
        'rental_start_date' => '2023-01-01',
        'rental_type' => 'seasonal',
        'is_primary_residence' => false,
        'tva_regime' => 'liable',
    ]);

    // T1 income
    Income::forceCreate([
        'property_id' => $property->id,
        'income_date' => '2026-01-15',
        'amount' => 55000,
        'tva_rate' => 1000,
        'amount_ht' => 50000,
        'tva_collected' => 5000,
        'platform_fee' => 0,
        'tourist_tax' => 0,
        'source' => 'airbnb',
    ]);

    // T3 income
    Income::forceCreate([
        'property_id' => $property->id,
        'income_date' => '2026-07-20',
        'amount' => 110000,
        'tva_rate' => 1000,
        'amount_ht' => 100000,
        'tva_collected' => 10000,
        'platform_fee' => 0,
        'tourist_tax' => 0,
        'source' => 'airbnb',
    ]);

    $result = $this->service->calculate($this->user, 2026);

    expect($result['quarters'][1]['collected'])->toBe(5000);
    expect($result['quarters'][3]['collected'])->toBe(10000);
    expect($result['quarters'][2]['collected'])->toBe(0);
    expect($result['quarters'][4]['collected'])->toBe(0);
});
