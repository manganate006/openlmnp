<?php

use App\Models\Property;
use App\Models\PropertyComponent;
use App\Models\User;
use App\Services\DepreciationService;

function makeRepairTestProperty(User $user, int $priceCents): Property
{
    return Property::forceCreate([
        'user_id' => $user->id,
        'name' => 'Bien Test',
        'address' => '1 rue Test',
        'city' => 'Paris',
        'postal_code' => '75001',
        'type' => 'apartment',
        'total_area' => 50,
        'rented_area' => 50,
        'acquisition_date' => '2023-01-01',
        'acquisition_price' => $priceCents,
        'notary_fees' => 0,
        'land_percentage' => 15,
        'rental_start_date' => '2023-01-01',
        'rental_type' => 'seasonal',
        'is_primary_residence' => false,
    ]);
}

it('repairs components left stale after a price correction', function () {
    $user = User::factory()->create();

    // Le bien est créé au prix corrompu (x100) : 25 000 000 € au lieu de 250 000 €.
    $property = makeRepairTestProperty($user, 2_500_000_000);
    app(DepreciationService::class)->generateDefaultComponents($property);

    // L'utilisateur corrige le prix à la main. Les composants restent périmés.
    $property->forceFill(['acquisition_price' => 25_000_000])->save();

    $staleTotal = (int) PropertyComponent::withoutGlobalScopes()->where('property_id', $property->id)->sum('base_amount');
    expect($staleTotal)->toBe(2_125_000_000); // 21 250 000 € : 100x trop

    $this->artisan('openlmnp:repair-components', ['--fix' => true])->assertSuccessful();

    $repairedTotal = (int) PropertyComponent::withoutGlobalScopes()->where('property_id', $property->id)->sum('base_amount');

    // 250 000 € - 15 % de terrain = 212 500 €
    expect($repairedTotal)->toBe(21_250_000);
});

it('leaves consistent components untouched and reports nothing to fix', function () {
    $user = User::factory()->create();
    $property = makeRepairTestProperty($user, 25_000_000);
    app(DepreciationService::class)->generateDefaultComponents($property);

    $before = PropertyComponent::withoutGlobalScopes()->where('property_id', $property->id)
        ->pluck('base_amount', 'id')->toArray();

    $this->artisan('openlmnp:repair-components', ['--fix' => true])
        ->expectsOutputToContain('aucune réparation nécessaire')
        ->assertSuccessful();

    $after = PropertyComponent::withoutGlobalScopes()->where('property_id', $property->id)
        ->pluck('base_amount', 'id')->toArray();

    expect($after)->toBe($before);
});

it('does not modify anything without --fix', function () {
    $user = User::factory()->create();
    $property = makeRepairTestProperty($user, 2_500_000_000);
    app(DepreciationService::class)->generateDefaultComponents($property);
    $property->forceFill(['acquisition_price' => 25_000_000])->save();

    $this->artisan('openlmnp:repair-components')->assertSuccessful();

    $total = (int) PropertyComponent::withoutGlobalScopes()->where('property_id', $property->id)->sum('base_amount');
    expect($total)->toBe(2_125_000_000); // inchangé
});

it('preserves custom percentages when repairing', function () {
    $user = User::factory()->create();
    $property = makeRepairTestProperty($user, 25_000_000);

    PropertyComponent::forceCreate([
        'property_id' => $property->id,
        'name' => 'Composant sur mesure',
        'percentage' => 40,
        'duration_years' => 20,
        'base_amount' => 999, // volontairement faux
        'annual_depreciation' => 999,
        'sort_order' => 1,
    ]);

    $this->artisan('openlmnp:repair-components', ['--fix' => true])->assertSuccessful();

    $component = PropertyComponent::withoutGlobalScopes()->where('property_id', $property->id)->firstOrFail();

    // Base amortissable 212 500 € x 40 % = 85 000 €, sur 20 ans = 4 250 €/an
    expect($component->percentage)->toBe(40)
        ->and((int) $component->base_amount)->toBe(8_500_000)
        ->and((int) $component->annual_depreciation)->toBe(425_000);
});
