<?php

use App\Filament\Resources\Properties\Pages\CreateProperty;
use App\Models\Property;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;

/**
 * Pour un logement indépendant dans la résidence principale, `total_area` porte la
 * surface de la PROPRIÉTÉ ENTIÈRE : c'est donc le type du BÂTIMENT (presque toujours
 * une maison) qu'il faut comparer sur DVF, pas le type du logement loué (studio,
 * chambre...) que porte le champ `type`.
 */
beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

it('compares against houses for an independent unit, even when the rented unit is a studio', function () {
    Livewire::test(CreateProperty::class)
        ->fillForm([
            'name' => 'Studio indépendant',
            'type' => 'studio',
            'residence_situation' => Property::RESIDENCE_INDEPENDENT_UNIT,
            'total_area' => 140,
        ])
        ->mountAction(TestAction::make('estimateMarketValue')->schemaComponent('market_value'))
        ->assertActionDataSet(['property_type' => 'house']);
});

it('keeps comparing against the declared type for a separate property', function () {
    Livewire::test(CreateProperty::class)
        ->fillForm([
            'name' => 'Appartement',
            'type' => 'apartment',
            'residence_situation' => Property::RESIDENCE_SEPARATE,
            'total_area' => 40,
        ])
        ->mountAction(TestAction::make('estimateMarketValue')->schemaComponent('market_value'))
        ->assertActionDataSet(['property_type' => 'apartment']);
});
