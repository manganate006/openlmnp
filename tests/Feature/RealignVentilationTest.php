<?php

use App\Filament\Pages\DepreciationEditor;
use App\Models\Property;
use App\Models\PropertyComponent;
use App\Models\User;
use App\Services\DepreciationService;
use Livewire\Livewire;

/**
 * Le recalage depuis l'éditeur (issue #11 de cocool97).
 *
 * Sans lui, un utilisateur dont les composants dépassent la base est ENFERMÉ : l'écran ne
 * signale rien à l'ouverture, et `syncComponents()` refuse tout enregistrement. Sa seule
 * sortie était `openlmnp:repair-components --fix --all`, une console qu'un utilisateur du
 * cloud n'a pas.
 */
beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);

    $this->property = Property::create([
        'user_id' => $this->user->id, 'name' => 'Bien de reprise', 'address' => '1 rue du Test',
        'city' => 'Lyon', 'postal_code' => '69003', 'type' => 'apartment',
        'total_area' => 45, 'rented_area' => 45, 'acquisition_date' => '2022-01-01',
        'acquisition_price' => 10_350_000, 'land_percentage' => 15,
        'rental_start_date' => '2022-03-01', 'rental_type' => 'seasonal',
        'is_primary_residence' => false,
    ]);
});

/** Reproduit l'état de cocool97 : 60/20/20 figés sur une base disparue, marqués « manuel ». */
function etatDeCocool(Property $property): void
{
    app(DepreciationService::class)->syncComponents($property, [
        ['name' => 'Gros œuvre', 'duration_years' => 40, 'sort_order' => 0, 'percentage' => 60],
        ['name' => 'Installations', 'duration_years' => 20, 'sort_order' => 1, 'percentage' => 20],
        ['name' => 'Agencements', 'duration_years' => 15, 'sort_order' => 2, 'percentage' => 20],
    ]);

    // Les bases sont requalifiées « manuel » — c'est ce qu'a fait le classifieur de la 1.2.0.
    PropertyComponent::withoutGlobalScopes()->where('property_id', $property->id)
        ->update(['base_source' => PropertyComponent::BASE_SOURCE_MANUAL]);

    // Puis la valeur du bien baisse de 5 100 €, sans que rien ne suive.
    $property->forceFill(['acquisition_price' => 9_840_000])->saveQuietly();
    $property->refresh();
}

it('mesure exactement l\'écart de 4 335 € du rapporteur', function () {
    etatDeCocool($this->property);

    expect(app(DepreciationService::class)->overAllocation($this->property))->toBe(433_500)
        ->and((int) $this->property->depreciable_base)->toBe(8_364_000);
});

it('annonce l\'écart à l\'ouverture de l\'éditeur', function () {
    etatDeCocool($this->property);

    Livewire::test(DepreciationEditor::class, ['propertyId' => $this->property->id])
        ->assertSee('4 335')
        ->assertSee('Recaler sur la base actuelle');
});

it('recale la ventilation sur la base courante', function () {
    etatDeCocool($this->property);

    Livewire::test(DepreciationEditor::class, ['propertyId' => $this->property->id])
        ->call('realignToBase')
        // ⚠️ Le conteneur de l'éditeur porte `wire:ignore` : sans ce dispatch, l'écran
        // annonce le succès et continue d'afficher les ANCIENS montants — et un
        // enregistrement depuis là réécrirait la dérive qu'on vient de corriger.
        // Trouvé au navigateur, invisible en test tant que rien ne l'assertait.
        ->assertDispatched('components-loaded');

    $this->property->refresh();

    expect(app(DepreciationService::class)->overAllocation($this->property))->toBe(0)
        ->and((int) $this->property->components->sum('base_amount'))
        ->toBe((int) $this->property->depreciable_base);
});

it('conserve les proportions 60 / 20 / 20 après recalage', function () {
    etatDeCocool($this->property);

    Livewire::test(DepreciationEditor::class, ['propertyId' => $this->property->id])
        ->call('realignToBase');

    $parts = $this->property->fresh()->components
        ->sortBy('sort_order')->pluck('base_amount')->map(fn ($v) => (int) $v)->values()->all();

    $total = array_sum($parts);

    expect(round($parts[0] / $total * 100))->toBe(60.0)
        ->and(round($parts[1] / $total * 100))->toBe(20.0)
        ->and(round($parts[2] / $total * 100))->toBe(20.0);
});

it('n\'affiche aucun bandeau quand la ventilation tient dans la base', function () {
    app(DepreciationService::class)->syncComponents($this->property, [
        ['name' => 'Gros œuvre', 'duration_years' => 40, 'sort_order' => 0, 'percentage' => 100],
    ]);

    Livewire::test(DepreciationEditor::class, ['propertyId' => $this->property->id])
        ->assertDontSee('Recaler sur la base actuelle');
});
