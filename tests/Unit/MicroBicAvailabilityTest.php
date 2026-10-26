<?php

use App\Models\Income;
use App\Models\Property;
use App\Models\User;
use App\Services\FiscalYearService;

/**
 * Au-delà du plafond, le micro-BIC ne doit plus être présenté comme possible —
 * `FiscalYearService::compareMicroBicVsReal()` est le point d'entrée commun au
 * simulateur, au `FiscalYearWizard`, à `Projection`/`GetProjection` et aux outils
 * MCP `compare_micro_bic`/`get_simulation`.
 */
beforeEach(function () {
    $this->user = User::factory()->create();
    $this->service = app(FiscalYearService::class);
});

function microBicProperty(User $user): Property
{
    return Property::forceCreate([
        'user_id' => $user->id,
        'name' => 'Bien micro-BIC',
        'address' => '1 rue Test',
        'city' => 'Paris',
        'postal_code' => '75001',
        'type' => 'apartment',
        'total_area' => 100,
        'rented_area' => 100,
        'acquisition_date' => '2020-01-01',
        'acquisition_price' => 10000000,
        'notary_fees' => 0,
        'land_percentage' => 0,
        'rental_start_date' => '2023-01-01',
        'rental_type' => 'seasonal',
        'is_primary_residence' => false,
    ]);
}

it('stays available under the 30 percent (unclassified) ceiling', function () {
    $property = microBicProperty($this->user);
    Income::create([
        'property_id' => $property->id,
        'income_date' => '2026-03-01',
        'amount' => 1_000_000, // 10 000 €
        'platform_fee' => 0,
        'tourist_tax' => 0,
        'source' => 'airbnb',
    ]);

    $comparison = $this->service->compareMicroBicVsReal($this->user, 2026, '30');

    expect($comparison['available'])->toBeTrue()
        ->and($comparison['unavailable_reason'])->toBeNull();
});

it('becomes unavailable once gross income crosses the unclassified ceiling', function () {
    $property = microBicProperty($this->user);
    Income::create([
        'property_id' => $property->id,
        'income_date' => '2026-03-01',
        'amount' => 2_000_000, // 20 000 € > plafond 15 000 €
        'platform_fee' => 0,
        'tourist_tax' => 0,
        'source' => 'airbnb',
    ]);

    $comparison = $this->service->compareMicroBicVsReal($this->user, 2026, '30');

    expect($comparison['available'])->toBeFalse()
        ->and($comparison['unavailable_reason'])->toContain('15 000');
});

it('stays available at the same income under the 50 percent (classified) ceiling', function () {
    $property = microBicProperty($this->user);
    Income::create([
        'property_id' => $property->id,
        'income_date' => '2026-03-01',
        'amount' => 2_000_000, // 20 000 €, sous le plafond classé (83 600 €)
        'platform_fee' => 0,
        'tourist_tax' => 0,
        'source' => 'airbnb',
    ]);

    $comparison = $this->service->compareMicroBicVsReal($this->user, 2026, '50');

    expect($comparison['available'])->toBeTrue();
});
