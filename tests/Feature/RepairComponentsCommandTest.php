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

/**
 * Fabrique un état PÉRIMÉ sans passer par une mise à jour du bien.
 *
 * ⚠️ Depuis l'issue #11 (2026-09-06), `PropertyObserver` resynchronise les composants
 * ventilés en pourcentage dès que la base amortissable change : corriger le prix du bien ne
 * laisse donc plus de composants périmés derrière lui — c'était tout l'objet du correctif.
 * Ces tests décrivent la dette DÉJÀ EN BASE des instances qui ont vécu le bug avant lui, et
 * doivent la fabriquer directement pour continuer à exercer la commande de réparation.
 */
function makeStaleComponents(App\Models\Property $property, int $staleBaseTotal): void
{
    $components = PropertyComponent::withoutGlobalScopes()
        ->where('property_id', $property->id)->orderBy('id')->get();

    $reste = $staleBaseTotal;

    foreach ($components as $i => $component) {
        $part = $i === $components->count() - 1
            ? $reste
            : (int) round($staleBaseTotal * ((float) $component->percentage) / 100);
        $reste -= $part;

        $component->forceFill(['base_amount' => $part])->saveQuietly();
    }
}

it('repairs components left stale after a price correction', function () {
    $user = User::factory()->create();

    // Le bien est créé au prix corrompu (x100) : 25 000 000 € au lieu de 250 000 €.
    $property = makeRepairTestProperty($user, 2_500_000_000);
    app(DepreciationService::class)->generateDefaultComponents($property);

    // Le prix est corrigé, puis on REFABRIQUE l'état périmé : l'observer de l'issue #11
    // recale désormais les composants à la correction, ce qui est le comportement voulu.
    $property->forceFill(['acquisition_price' => 25_000_000])->save();
    makeStaleComponents($property, 2_125_000_000);

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
        ->expectsOutputToContain('Aucune désynchronisation')
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
    makeStaleComponents($property, 2_125_000_000);

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
    // `percentage` est un decimal(7,4) casté en float depuis le 2026-09-03.
    expect($component->percentage)->toBe(40.0)
        ->and((int) $component->base_amount)->toBe(8_500_000)
        ->and((int) $component->annual_depreciation)->toBe(425_000);
});

it('never repairs a base that was set by hand', function () {
    $user = User::factory()->create();
    $property = makeRepairTestProperty($user, 25_000_000);

    // Base théorique : 212 500 € x 50 % = 106 250 €. L'utilisateur a saisi 100 000 €
    // pour reproduire le plan de son comptable — c'est volontaire, pas une dérive.
    PropertyComponent::forceCreate([
        'property_id' => $property->id,
        'name' => 'Gros œuvre repris',
        'percentage' => 50,
        'duration_years' => 50,
        'base_amount' => 10_000_000,
        'annual_depreciation' => 200_000,
        'base_source' => PropertyComponent::BASE_SOURCE_MANUAL,
        'sort_order' => 1,
    ]);

    $this->artisan('openlmnp:repair-components', ['--fix' => true])
        ->expectsOutputToContain('Bases saisies à la main')
        ->assertSuccessful();

    $component = PropertyComponent::withoutGlobalScopes()->where('property_id', $property->id)->firstOrFail();
    expect((int) $component->base_amount)->toBe(10_000_000)  // intacte
        ->and((int) $component->annual_depreciation)->toBe(200_000);

    // --all est la seule porte de sortie, et elle est destructrice.
    $this->artisan('openlmnp:repair-components', ['--fix' => true, '--all' => true])->assertSuccessful();

    $component->refresh();
    expect((int) $component->base_amount)->toBe(10_625_000);
});

it('repairs a small divergence on a ventilated base, without needing --all', function () {
    $user = User::factory()->create();
    $property = makeRepairTestProperty($user, 25_000_000);

    // Même écart que ci-dessus, mais sur une base DÉRIVÉE : rien ne le justifie.
    // Avant `base_source`, le seuil « facteur 10 » laissait passer ce cas.
    PropertyComponent::forceCreate([
        'property_id' => $property->id,
        'name' => 'Gros œuvre',
        'percentage' => 50,
        'duration_years' => 50,
        'base_amount' => 10_000_000,
        'annual_depreciation' => 200_000,
        'base_source' => PropertyComponent::BASE_SOURCE_PERCENTAGE,
        'sort_order' => 1,
    ]);

    $this->artisan('openlmnp:repair-components', ['--fix' => true])->assertSuccessful();

    $component = PropertyComponent::withoutGlobalScopes()->where('property_id', $property->id)->firstOrFail();
    expect((int) $component->base_amount)->toBe(10_625_000)
        ->and((int) $component->annual_depreciation)->toBe(212_500);
});
