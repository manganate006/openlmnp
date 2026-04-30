<?php

use App\Models\Property;
use App\Models\PropertyComponent;
use App\Models\User;
use App\Services\DepreciationService;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->service = new DepreciationService();
});

it('generates default components for a property', function () {
    $property = Property::forceCreate([
        'user_id' => $this->user->id,
        'name' => 'Test',
        'address' => '1 rue Test',
        'city' => 'Paris',
        'postal_code' => '75001',
        'type' => 'apartment',
        'total_area' => 100,
        'rented_area' => 50,
        'acquisition_date' => '2020-01-01',
        'acquisition_price' => 30000000, // 300k€
        'notary_fees' => 0,
        'market_value' => null,
        'land_percentage' => 20,
        'rental_start_date' => '2023-01-01',
        'rental_type' => 'seasonal',
        'is_primary_residence' => true,
    ]);

    $this->service->generateDefaultComponents($property);

    expect($property->components()->count())->toBe(6);

    $totalPercentage = $property->components()->sum('percentage');
    expect($totalPercentage)->toBe(100);
});

it('calculates depreciable base with quota share', function () {
    $property = Property::forceCreate([
        'user_id' => $this->user->id,
        'name' => 'Test',
        'address' => '1 rue Test',
        'city' => 'Paris',
        'postal_code' => '75001',
        'type' => 'apartment',
        'total_area' => 100,
        'rented_area' => 50, // 50% quote-part
        'acquisition_date' => '2020-01-01',
        'acquisition_price' => 40000000, // 400k€
        'notary_fees' => 0,
        'market_value' => null,
        'land_percentage' => 20, // 20% terrain
        'rental_start_date' => '2023-01-01',
        'rental_type' => 'seasonal',
        'is_primary_residence' => true,
    ]);

    // Base = 400k * (1 - 20%) * 50% = 400k * 0.80 * 0.50 = 160k€ = 16000000 cts
    $base = $property->depreciable_base;
    expect((int) $base)->toBe(16000000);
});

it('calculates depreciable base with market value when available', function () {
    $property = Property::forceCreate([
        'user_id' => $this->user->id,
        'name' => 'Test',
        'address' => '1 rue Test',
        'city' => 'Paris',
        'postal_code' => '75001',
        'type' => 'apartment',
        'total_area' => 100,
        'rented_area' => 100, // 100% loué
        'acquisition_date' => '2020-01-01',
        'acquisition_price' => 30000000,
        'notary_fees' => 0,
        'market_value' => 50000000, // 500k€ valeur vénale
        'market_value_date' => '2023-01-01',
        'land_percentage' => 15,
        'rental_start_date' => '2023-01-01',
        'rental_type' => 'seasonal',
        'is_primary_residence' => false,
    ]);

    // Base = 500k * (1 - 15%) * 100% = 500k * 0.85 = 425k€ = 42500000 cts
    $base = $property->depreciable_base;
    expect((int) $base)->toBe(42500000);
});

it('calculates annual depreciation for a full year', function () {
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
        'acquisition_price' => 10000000, // 100k€
        'notary_fees' => 0,
        'market_value' => null,
        'land_percentage' => 0, // pas de terrain pour simplifier
        'rental_start_date' => '2023-01-01',
        'rental_type' => 'seasonal',
        'is_primary_residence' => false,
    ]);

    $this->service->generateDefaultComponents($property);
    $result = $this->service->calculateAnnualDepreciation($property, 2024);

    // Total annuel > 0
    expect((int) $result['total'])->toBeGreaterThan(0);
    expect($result['details'])->toHaveCount(6);
});

it('returns zero depreciation before rental start', function () {
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
        'rental_start_date' => '2025-01-01',
        'rental_type' => 'seasonal',
        'is_primary_residence' => false,
    ]);

    $this->service->generateDefaultComponents($property);
    $result = $this->service->calculateAnnualDepreciation($property, 2024);

    expect((int) $result['total'])->toBe(0);
});
