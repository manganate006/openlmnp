<?php

use App\Filament\Pages\DepreciationEditor;
use App\Models\Property;
use App\Models\PropertyComponent;
use App\Models\User;
use App\Services\DepreciationService;
use Livewire\Livewire;

/**
 * L'éditeur d'amortissements — le seul point d'entrée depuis le 2026-09-03.
 *
 * `saveComponents()` et `resetToDefaults()` n'étaient couverts par AUCUN test, alors
 * qu'ils supprimaient et recréaient l'intégralité des composants d'un bien à chaque
 * enregistrement.
 */
function editorProperty(User $user, string $name = 'Bien éditeur'): Property
{
    return Property::forceCreate([
        'user_id' => $user->id,
        'name' => $name,
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

/** Reconstruit le format de charge utile envoyé par le navigateur. */
function editorPayload(Property $property, array $overridesByName = []): array
{
    return $property->components()->orderBy('sort_order')->get()
        ->map(fn ($c) => array_merge([
            'id' => $c->id,
            'name' => $c->name,
            'percentage' => (float) $c->percentage,
            'baseAmount' => (int) $c->base_amount,
            'baseSource' => $c->base_source,
            'annualDepreciation' => null,
            'duration' => $c->duration_years,
            'sortOrder' => $c->sort_order,
            'enabled' => true,
        ], $overridesByName[$c->name] ?? []))->all();
}

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->property = editorProperty($this->user);
    app(DepreciationService::class)->generateDefaultComponents($this->property);
});

it('renders the editor for a property that actually has components', function () {
    // Le test existant montait /depreciation-editor avec un utilisateur SANS bien, donc
    // la branche « aucun bien » : les 500 lignes d'Alpine n'étaient jamais rendues.
    Livewire::actingAs($this->user)
        ->test(DepreciationEditor::class, ['propertyId' => $this->property->id])
        ->assertOk()
        ->assertSee('Montants par composant')
        ->assertSee('Reste à ventiler', escape: false);
});

it('saves a base typed in euros and marks it manual', function () {
    Livewire::actingAs($this->user)
        ->test(DepreciationEditor::class, ['propertyId' => $this->property->id])
        ->call('saveComponents', editorPayload($this->property, [
            // 98 765,43 € : sous les 100 000 € que le gros œuvre occupait, donc la
            // ventilation reste dans la base. Et surtout, un montant aux centimes,
            // impossible à exprimer avec l'ancien pourcentage entier.
            'Gros œuvre' => ['baseAmount' => 9_876_543, 'baseSource' => 'manual'],
        ]));

    $component = $this->property->components()->where('name', 'Gros œuvre')->firstOrFail();

    expect((int) $component->base_amount)->toBe(9_876_543)
        ->and($component->base_source)->toBe(PropertyComponent::BASE_SOURCE_MANUAL);
});

it('does not truncate a fractional percentage sent by the browser', function () {
    // Avant le correctif, `(int) $comp['percentage']` ramenait 33,7 % à 33 %.
    Livewire::actingAs($this->user)
        ->test(DepreciationEditor::class, ['propertyId' => $this->property->id])
        ->call('saveComponents', [[
            'id' => null,
            'name' => 'Composant unique',
            'percentage' => 33.7,
            'baseAmount' => 0,
            'baseSource' => 'percentage',
            'annualDepreciation' => null,
            'duration' => 20,
            'sortOrder' => 1,
            'enabled' => true,
        ]]);

    $component = $this->property->components()->firstOrFail();

    // 33,7 % de 200 000 € = 67 400 €, et non 66 000 € (33 %).
    expect((int) $component->base_amount)->toBe(6_740_000);
});

it('keeps component ids stable across a save', function () {
    $before = $this->property->components()->orderBy('id')->pluck('id')->all();

    Livewire::actingAs($this->user)
        ->test(DepreciationEditor::class, ['propertyId' => $this->property->id])
        ->call('saveComponents', editorPayload($this->property));

    expect($this->property->components()->orderBy('id')->pluck('id')->all())->toBe($before);
});

it('does not overwrite a manual base when the sliders are saved again', function () {
    $editor = Livewire::actingAs($this->user)
        ->test(DepreciationEditor::class, ['propertyId' => $this->property->id]);

    $editor->call('saveComponents', editorPayload($this->property, [
        'Gros œuvre' => ['baseAmount' => 9_000_000, 'baseSource' => 'manual'],
    ]));

    // Deuxième enregistrement : le navigateur renvoie la ligne telle qu'il l'a relue,
    // toujours marquée manuelle. La base ne doit pas retomber sur les 50 % du curseur.
    $editor->call('saveComponents', editorPayload($this->property));

    expect((int) $this->property->components()->where('name', 'Gros œuvre')->firstOrFail()->base_amount)
        ->toBe(9_000_000);
});

it('notifies and writes nothing when the ventilation exceeds the base', function () {
    $before = $this->property->components()->pluck('base_amount', 'id')->toArray();

    Livewire::actingAs($this->user)
        ->test(DepreciationEditor::class, ['propertyId' => $this->property->id])
        ->call('saveComponents', editorPayload($this->property, [
            'Gros œuvre' => ['baseAmount' => 99_000_000, 'baseSource' => 'manual'],
        ]))
        ->assertNotified();

    expect($this->property->components()->pluck('base_amount', 'id')->toArray())->toBe($before);
});

it('never touches the components of another user', function () {
    $other = User::factory()->create();
    $otherProperty = editorProperty($other, 'Bien du voisin');
    app(DepreciationService::class)->generateDefaultComponents($otherProperty);

    $foreign = PropertyComponent::withoutGlobalScopes()
        ->where('property_id', $otherProperty->id)->firstOrFail();
    $before = (int) $foreign->base_amount;

    Livewire::actingAs($this->user)
        ->test(DepreciationEditor::class, ['propertyId' => $this->property->id])
        ->call('saveComponents', [[
            'id' => $foreign->id, // identifiant volé
            'name' => 'Tentative',
            'percentage' => 10,
            'baseAmount' => 0,
            'baseSource' => 'percentage',
            'annualDepreciation' => null,
            'duration' => 10,
            'sortOrder' => 1,
            'enabled' => true,
        ]]);

    $stillThere = PropertyComponent::withoutGlobalScopes()
        ->where('property_id', $otherProperty->id);

    expect((int) $stillThere->clone()->whereKey($foreign->id)->firstOrFail()->base_amount)->toBe($before)
        ->and($stillThere->count())->toBe(6);
});

it('restores the six standard components on reset', function () {
    Livewire::actingAs($this->user)
        ->test(DepreciationEditor::class, ['propertyId' => $this->property->id])
        ->call('saveComponents', [[
            'id' => null, 'name' => 'Tout en un', 'percentage' => 100, 'baseAmount' => 0,
            'baseSource' => 'percentage', 'annualDepreciation' => null,
            'duration' => 30, 'sortOrder' => 1, 'enabled' => true,
        ]])
        ->call('resetToDefaults');

    expect($this->property->components()->count())->toBe(6)
        ->and((int) $this->property->components()->sum('base_amount'))->toBe(20_000_000);
});
