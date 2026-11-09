<?php

use App\Models\Property;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * `residence_situation` remplace `is_primary_residence` (migration
 * 2026_10_01_000001_add_residence_situation_to_properties).
 *
 * Deux garanties à couvrir :
 *   1. la compatibilité ascendante — un appelant qui ne connaît que l'ancien booléen
 *      (test existant, intégration tierce, MCP avec `is_primary_residence`) continue
 *      de fonctionner, le modèle dérivant `residence_situation` à sa place ;
 *   2. le backfill de la migration elle-même sur une base existante — reproduit ici
 *      en SQL direct (comme la migration), parce que `RefreshDatabase` rejoue toutes
 *      les migrations sur une base VIDE : le backfill n'a donc jamais de ligne à
 *      traiter dans le flux de test normal.
 */
beforeEach(function () {
    $this->user = User::factory()->create();
});

function baseResidenceProperty(User $user, array $overrides = []): array
{
    return array_merge([
        'user_id' => $user->id,
        'name' => 'Bien test',
        'address' => '1 rue Test',
        'city' => 'Paris',
        'postal_code' => '75001',
        'type' => 'apartment',
        'total_area' => 100,
        'rented_area' => 30,
        'acquisition_date' => '2022-01-01',
        'acquisition_price' => 20000000,
        'notary_fees' => 0,
        'land_percentage' => 15,
        'rental_start_date' => '2022-01-01',
        'rental_type' => 'seasonal',
    ], $overrides);
}

it('derives residence_situation from the legacy boolean when not given explicitly', function () {
    $property = Property::create(baseResidenceProperty($this->user, [
        'is_primary_residence' => true,
    ]));

    expect($property->residence_situation)->toBe(Property::RESIDENCE_PART_OF_RESIDENCE);
});

it('defaults residence_situation to separate when the legacy boolean is false', function () {
    $property = Property::create(baseResidenceProperty($this->user, [
        'is_primary_residence' => false,
    ]));

    expect($property->residence_situation)->toBe(Property::RESIDENCE_SEPARATE);
});

it('accepts the new independent_unit_in_residence situation and keeps the legacy boolean in sync', function () {
    $property = Property::create(baseResidenceProperty($this->user, [
        'residence_situation' => Property::RESIDENCE_INDEPENDENT_UNIT,
    ]));

    expect($property->residence_situation)->toBe(Property::RESIDENCE_INDEPENDENT_UNIT)
        ->and($property->is_primary_residence)->toBeTrue();
});

it('lets residence_situation override a contradictory legacy boolean', function () {
    $property = Property::create(baseResidenceProperty($this->user, [
        'residence_situation' => Property::RESIDENCE_SEPARATE,
        'is_primary_residence' => true,
    ]));

    expect($property->is_primary_residence)->toBeFalse();
});

it('re-derives residence_situation when the legacy boolean alone is changed on an existing property', function () {
    // Régression : un bien déjà enregistré porte déjà un `residence_situation` non vide
    // (`separate` par défaut). Sans la distinction isDirty(), la ligne qui recalcule
    // toujours `is_primary_residence` depuis `residence_situation` écrasait en silence
    // le booléen qu'un appelant venait pourtant de poser explicitement — repéré par
    // `tests/Unit/Ai/NormalizerTest.php`, qui ne connaît que l'ancien champ.
    $property = Property::create(baseResidenceProperty($this->user, [
        'is_primary_residence' => false,
    ]));
    expect($property->residence_situation)->toBe(Property::RESIDENCE_SEPARATE);

    $property->forceFill(['is_primary_residence' => true])->save();

    expect($property->refresh()->residence_situation)->toBe(Property::RESIDENCE_PART_OF_RESIDENCE)
        ->and($property->is_primary_residence)->toBeTrue();
});

it('derives a default tourism_classification from rental_type when not given', function () {
    $longTerm = Property::create(baseResidenceProperty($this->user, [
        'name' => 'Bail',
        'rental_type' => 'long_term',
    ]));
    $seasonal = Property::create(baseResidenceProperty($this->user, [
        'name' => 'Airbnb',
        'rental_type' => 'seasonal',
    ]));

    expect($longTerm->tourism_classification)->toBe(Property::CLASSIFICATION_LONG_TERM)
        ->and($seasonal->tourism_classification)->toBe(Property::CLASSIFICATION_UNCLASSIFIED);
});

it('reproduces the migration backfill on a legacy row inserted before it ran', function () {
    // Insertion SQL directe : simule une ligne telle qu'elle existait juste après le
    // `ADD COLUMN ... default('separate')` de la migration, AVANT son backfill —
    // sans passer par le hook saving() du modèle, qui masquerait ce que fait
    // réellement la migration sur une base de production existante.
    $id = DB::table('properties')->insertGetId(array_merge(
        baseResidenceProperty($this->user, ['is_primary_residence' => true]),
        [
            'residence_situation' => Property::RESIDENCE_SEPARATE,
            'tourism_classification' => Property::CLASSIFICATION_UNCLASSIFIED,
            'tva_regime' => 'exempt',
            'created_at' => now(),
            'updated_at' => now(),
        ]
    ));

    // Exactement l'instruction de 2026_10_01_000001_add_residence_situation_to_properties::up().
    DB::table('properties')
        ->where('is_primary_residence', true)
        ->update(['residence_situation' => Property::RESIDENCE_PART_OF_RESIDENCE]);

    expect(DB::table('properties')->where('id', $id)->value('residence_situation'))
        ->toBe(Property::RESIDENCE_PART_OF_RESIDENCE);
});
