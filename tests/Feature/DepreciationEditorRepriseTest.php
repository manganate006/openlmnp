<?php

use App\Filament\Pages\DepreciationEditor;
use App\Models\Property;
use App\Models\PropertyComponent;
use App\Models\User;
use App\Services\DepreciationService;
use Livewire\Livewire;

/**
 * Reprise d'un plan de cabinet depuis l'éditeur d'amortissements (lot 4).
 *
 * La grille était bâtie sur `DepreciationService::FULL_CATALOG` : un composant hors
 * catalogue s'AFFICHAIT (s'il existait déjà en base) mais ne se CRÉAIT pas. Un plan
 * comportant « Ascenseur » ou « Menuiseries extérieures » était donc irreproductible,
 * et rien à l'écran ne le disait.
 */
function repriseEditorProperty(User $user): Property
{
    return Property::forceCreate([
        'user_id' => $user->id,
        'name' => 'Bien reprise éditeur',
        'address' => '1 rue Test',
        'city' => 'Paris',
        'postal_code' => '75001',
        'type' => 'apartment',
        'total_area' => 100,
        'rented_area' => 100,
        'acquisition_date' => '2020-01-01',
        'acquisition_price' => 25000000,
        'notary_fees' => 0,
        'land_percentage' => 20, // base amortissable = 200 000 €
        'rental_start_date' => '2023-01-01',
        'rental_type' => 'seasonal',
        'is_primary_residence' => false,
    ]);
}

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->property = repriseEditorProperty($this->user);
    app(DepreciationService::class)->generateDefaultComponents($this->property);
});

it('creates a free-name component that is absent from the catalogue', function () {
    Livewire::actingAs($this->user)
        ->test(DepreciationEditor::class, ['propertyId' => $this->property->id])
        ->call('saveComponents', [[
            'id' => null,
            'name' => 'Ascenseur',
            'percentage' => 0,
            'baseAmount' => 1500000,
            'baseSource' => PropertyComponent::BASE_SOURCE_MANUAL,
            'annualDepreciation' => 75000,
            'duration' => 20,
            'sortOrder' => 20,
            'enabled' => true,
            'cerfaCategory' => PropertyComponent::CERFA_CATEGORY_INSTALLATIONS,
            'startDate' => '2024-03-15',
            'openingCumul' => 300000,
        ]]);

    $component = PropertyComponent::withoutGlobalScopes()
        ->where('property_id', $this->property->id)
        ->where('name', 'Ascenseur')
        ->first();

    expect($component)->not->toBeNull()
        ->and((int) $component->base_amount)->toBe(1500000)
        ->and((int) $component->annual_depreciation)->toBe(75000)
        ->and($component->cerfa_category)->toBe(PropertyComponent::CERFA_CATEGORY_INSTALLATIONS)
        ->and($component->depreciation_start_date->format('Y-m-d'))->toBe('2024-03-15')
        ->and((int) $component->opening_accumulated_depreciation)->toBe(300000);
});

it('refuses to create a component without a name', function () {
    Livewire::actingAs($this->user)
        ->test(DepreciationEditor::class, ['propertyId' => $this->property->id])
        ->call('saveComponents', [[
            'id' => null,
            'name' => '   ',
            'percentage' => 0,
            'baseAmount' => 500000,
            'baseSource' => PropertyComponent::BASE_SOURCE_MANUAL,
            'duration' => 10,
            'sortOrder' => 30,
            'enabled' => true,
        ]]);

    // Rien d'anonyme n'entre en base — ni sous un nom vide, ni sous un nom d'emprunt.
    expect(PropertyComponent::withoutGlobalScopes()->where('property_id', $this->property->id)->count())
        ->toBe(0);
});

it('exposes the Cerfa categories and the rental start date to the editor', function () {
    $data = Livewire::actingAs($this->user)
        ->test(DepreciationEditor::class, ['propertyId' => $this->property->id])
        ->instance()
        ->editorData();

    expect($data['cerfaCategories'])->toHaveKeys([
        PropertyComponent::CERFA_CATEGORY_CONSTRUCTIONS,
        PropertyComponent::CERFA_CATEGORY_INSTALLATIONS,
        PropertyComponent::CERFA_CATEGORY_FITTINGS,
        PropertyComponent::CERFA_CATEGORY_OTHER,
    ])->and($data['rentalStartDate'])->toBe('2023-01-01');

    $roof = collect($data['components'])->firstWhere('name', 'Toiture');
    expect($roof['cerfaCategory'])->toBe(PropertyComponent::CERFA_CATEGORY_CONSTRUCTIONS)
        ->and($roof['startDate'])->toBeNull()
        ->and($roof['openingCumul'])->toBe(0);
});

it('clears a component start date when the field is emptied', function () {
    $roof = $this->property->components()->where('name', 'Toiture')->firstOrFail();
    $roof->forceFill(['depreciation_start_date' => '2024-01-01'])->save();

    Livewire::actingAs($this->user)
        ->test(DepreciationEditor::class, ['propertyId' => $this->property->id])
        ->call('saveComponents', [[
            'id' => $roof->id,
            'name' => 'Toiture',
            'percentage' => (float) $roof->percentage,
            'baseAmount' => (int) $roof->base_amount,
            'baseSource' => $roof->base_source,
            'duration' => $roof->duration_years,
            'sortOrder' => $roof->sort_order,
            'enabled' => true,
            'cerfaCategory' => $roof->cerfa_category,
            'startDate' => '',
            'openingCumul' => 0,
        ]]);

    // Vider le champ remet le composant sur la date du bien : c'est le seul moyen de
    // revenir en arrière une fois qu'une date propre a été saisie.
    expect($roof->fresh()->depreciation_start_date)->toBeNull();
});

it('leaves the reprise columns untouched when the sliders save a ventilation', function () {
    $roof = $this->property->components()->where('name', 'Toiture')->firstOrFail();
    $roof->forceFill([
        'depreciation_start_date' => '2024-01-01',
        'cerfa_category' => PropertyComponent::CERFA_CATEGORY_FITTINGS,
        'opening_accumulated_depreciation' => 123400,
    ])->save();

    // Charge utile de l'onglet Ventilation : elle ne porte AUCUNE des trois colonnes.
    Livewire::actingAs($this->user)
        ->test(DepreciationEditor::class, ['propertyId' => $this->property->id])
        ->call('saveComponents', [[
            'id' => $roof->id,
            'name' => 'Toiture',
            'percentage' => 12.0,
            'baseAmount' => 0,
            'baseSource' => PropertyComponent::BASE_SOURCE_PERCENTAGE,
            'duration' => 25,
            'sortOrder' => 2,
            'enabled' => true,
        ]]);

    $fresh = $roof->fresh();

    expect($fresh->depreciation_start_date->format('Y-m-d'))->toBe('2024-01-01')
        ->and($fresh->cerfa_category)->toBe(PropertyComponent::CERFA_CATEGORY_FITTINGS)
        ->and((int) $fresh->opening_accumulated_depreciation)->toBe(123400)
        // … et la ventilation, elle, a bien été appliquée.
        ->and((int) $fresh->base_amount)->toBe(2400000);
});
