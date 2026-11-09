<?php

use App\Filament\Resources\Properties\Pages\CreateProperty;
use App\Models\Property;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

it('creates an independent-unit property through the Filament form', function () {
    Livewire::test(CreateProperty::class)
        ->fillForm([
            'name' => 'Studio indépendant',
            'type' => 'studio',
            'rental_type' => 'seasonal',
            'address' => '3 chemin des Lilas',
            'city' => 'Annecy',
            'postal_code' => '74000',
            'residence_situation' => Property::RESIDENCE_INDEPENDENT_UNIT,
            // Surface TOTALE de la propriété entière (maison + studio), pas du seul studio.
            'total_area' => 140,
            'rented_area' => 28,
            'acquisition_price' => '400000',
            'acquisition_date' => '2021-05-10',
            'land_percentage' => 15,
            'rental_start_date' => '2022-01-01',
            'tva_regime' => 'exempt',
            'tourism_classification' => Property::CLASSIFICATION_UNCLASSIFIED,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $property = Property::where('name', 'Studio indépendant')->firstOrFail();

    expect($property->residence_situation)->toBe(Property::RESIDENCE_INDEPENDENT_UNIT)
        ->and($property->is_primary_residence)->toBeTrue()
        ->and($property->tourism_classification)->toBe(Property::CLASSIFICATION_UNCLASSIFIED)
        ->and($property->total_area)->toBe(140)
        ->and($property->rented_area)->toBe(28);
});

it('creates a separate property by default, without touching residence_situation', function () {
    Livewire::test(CreateProperty::class)
        ->fillForm([
            'name' => 'Appartement classique',
            'type' => 'apartment',
            'rental_type' => 'seasonal',
            'address' => '10 avenue des Fleurs',
            'city' => 'Lyon',
            'postal_code' => '69000',
            'total_area' => 45,
            'rented_area' => 45,
            'acquisition_price' => '180000',
            'acquisition_date' => '2020-03-01',
            'land_percentage' => 15,
            'rental_start_date' => '2020-06-01',
            'tva_regime' => 'exempt',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $property = Property::where('name', 'Appartement classique')->firstOrFail();

    expect($property->residence_situation)->toBe(Property::RESIDENCE_SEPARATE)
        ->and($property->is_primary_residence)->toBeFalse();
});
