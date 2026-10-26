<?php

use App\Models\FiscalYear;
use App\Models\Property;
use App\Models\PropertyComponent;
use App\Models\User;

function makeLandRepairFixture(): array
{
    $user = User::factory()->create();

    $property = Property::forceCreate([
        'user_id' => $user->id,
        'name' => 'Bien Terrain',
        'address' => '1 rue Test',
        'city' => 'Paris',
        'postal_code' => '75001',
        'type' => 'house',
        'total_area' => 100,
        'rented_area' => 100,
        'acquisition_date' => '2023-01-01',
        'acquisition_price' => 57_500_000,
        'notary_fees' => 0,
        'land_percentage' => 15,
        'rental_start_date' => '2023-01-01',
        'rental_type' => 'seasonal',
        'is_primary_residence' => false,
    ]);

    // État antérieur au correctif : un terrain importé comme composant amortissable
    // sur 10 ans (durée par défaut, faute de durée imprimée sur la liasse).
    $land = PropertyComponent::forceCreate([
        'property_id' => $property->id,
        'name' => 'Terrains',
        'percentage' => 5,
        'duration_years' => 10,
        'base_amount' => 2_715_000,
        'annual_depreciation' => 271_500,
        'base_source' => PropertyComponent::BASE_SOURCE_MANUAL,
        'sort_order' => 0,
    ]);

    $building = PropertyComponent::forceCreate([
        'property_id' => $property->id,
        'name' => 'Gros œuvre',
        'percentage' => 50,
        'duration_years' => 50,
        'base_amount' => 8_000_000,
        'annual_depreciation' => 160_000,
        'base_source' => PropertyComponent::BASE_SOURCE_MANUAL,
        'sort_order' => 1,
    ]);

    return [$user, $property, $land, $building];
}

it('reports a "Terrains" component without touching it, absent --fix', function () {
    [, $property, $land, $building] = makeLandRepairFixture();

    $this->artisan('openlmnp:repair-land-components')
        ->expectsOutputToContain('Terrains')
        ->assertSuccessful();

    expect(PropertyComponent::withoutGlobalScopes()->where('property_id', $property->id)->count())->toBe(2)
        ->and(PropertyComponent::withoutGlobalScopes()->find($land->id))->not->toBeNull();
});

it('deletes a "Terrains" component and leaves other components alone, with --fix', function () {
    [, $property, $land, $building] = makeLandRepairFixture();

    $this->artisan('openlmnp:repair-land-components', ['--fix' => true])->assertSuccessful();

    expect(PropertyComponent::withoutGlobalScopes()->find($land->id))->toBeNull()
        ->and(PropertyComponent::withoutGlobalScopes()->find($building->id))->not->toBeNull();
});

it('recalculates draft fiscal years but leaves closed ones alone', function () {
    [$user, $property] = makeLandRepairFixture();

    // L'exercice clôturé est ANTÉRIEUR au brouillon : le recalcul en cascade de
    // `FiscalYearService::calculate()` ne propage que vers N+1, jamais vers N-1 — le
    // faire suivre éviterait de dépendre de ce mécanisme, sans rapport avec ce correctif.
    $closed = FiscalYear::forceCreate([
        'user_id' => $user->id, 'year' => 2023, 'status' => FiscalYear::STATUS_CLOSED,
        'total_income' => 0, 'total_depreciation' => 999_999_999, 'fiscal_result' => 0,
    ]);
    $draft = FiscalYear::forceCreate([
        'user_id' => $user->id, 'year' => 2024, 'status' => FiscalYear::STATUS_DRAFT,
        'total_income' => 0, 'total_depreciation' => 999_999_999, 'fiscal_result' => 0,
    ]);

    $this->artisan('openlmnp:repair-land-components', ['--fix' => true])
        ->expectsOutputToContain('CLÔTURÉS')
        ->assertSuccessful();

    expect($draft->fresh()->total_depreciation)->not->toBe(999_999_999)
        ->and($closed->fresh()->total_depreciation)->toBe(999999999);
});

it('does nothing when no property carries a land component', function () {
    $user = User::factory()->create();
    $property = Property::forceCreate([
        'user_id' => $user->id, 'name' => 'Bien Sain', 'address' => '1 rue Test', 'city' => 'Paris',
        'postal_code' => '75001', 'type' => 'apartment', 'total_area' => 50, 'rented_area' => 50,
        'acquisition_date' => '2023-01-01', 'acquisition_price' => 20_000_000, 'notary_fees' => 0,
        'land_percentage' => 15, 'rental_start_date' => '2023-01-01', 'rental_type' => 'seasonal',
        'is_primary_residence' => false,
    ]);
    PropertyComponent::forceCreate([
        'property_id' => $property->id, 'name' => 'Gros œuvre', 'percentage' => 50,
        'duration_years' => 50, 'base_amount' => 8_000_000, 'annual_depreciation' => 160_000,
        'base_source' => PropertyComponent::BASE_SOURCE_MANUAL, 'sort_order' => 0,
    ]);

    $this->artisan('openlmnp:repair-land-components', ['--fix' => true])
        ->expectsOutputToContain('rien à faire')
        ->assertSuccessful();

    expect(PropertyComponent::withoutGlobalScopes()->where('property_id', $property->id)->count())->toBe(1);
});
