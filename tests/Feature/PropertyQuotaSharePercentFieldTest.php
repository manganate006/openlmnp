<?php

use App\Filament\Resources\Properties\Pages\EditProperty;
use App\Models\Property;
use App\Models\User;
use Livewire\Livewire;

/**
 * `quota_share_percent` est une alternative de saisie à `rented_area`, pour qui connaît sa
 * quote-part (« je loue 27 % de ma résidence ») sans connaître le nombre exact de m² loués.
 * Ce n'est PAS une colonne du bien : elle ne doit jamais partir dans l'enregistrement,
 * seule `rented_area`, recalculée à partir d'elle, doit l'être.
 */
beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);

    $this->property = Property::create([
        'user_id' => $this->user->id, 'name' => 'Résidence', 'address' => '1 rue du Test',
        'city' => 'Lyon', 'postal_code' => '69003', 'type' => 'apartment',
        'total_area' => 118, 'rented_area' => 31, 'acquisition_date' => '2022-01-01',
        'acquisition_price' => 30_000_000, 'land_percentage' => 15,
        'rental_start_date' => '2022-03-01', 'rental_type' => 'seasonal',
        'is_primary_residence' => true,
    ]);
});

it('shows the quota share already stored, computed from total and rented area', function () {
    Livewire::test(EditProperty::class, ['record' => $this->property->getRouteKey()])
        ->assertFormSet(['quota_share_percent' => round(31 / 118 * 100, 1)]);
});

it('computes the rented area live from a quota share typed instead of it, and never persists the percentage itself', function () {
    Livewire::test(EditProperty::class, ['record' => $this->property->getRouteKey()])
        ->fillForm(['quota_share_percent' => '30'])
        ->assertFormSet(['rented_area' => (string) round(118 * 30 / 100)])
        ->call('save');

    $property = $this->property->fresh();
    expect($property->rented_area)->toBe((int) round(118 * 30 / 100));
});

it('recomputes the displayed quota share when the rented area is typed directly', function () {
    Livewire::test(EditProperty::class, ['record' => $this->property->getRouteKey()])
        ->fillForm(['rented_area' => '59'])
        ->assertFormSet(['quota_share_percent' => 50.0]);
});
