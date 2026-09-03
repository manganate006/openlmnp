<?php

use App\Models\Property;
use App\Models\PropertyComponent;
use App\Models\User;
use App\Services\DepreciationService;

/**
 * `DepreciationService::syncComponents()` — l'écriture des composants.
 *
 * Remplace le `delete()` + `create()` de `DepreciationEditor`, qui changeait tous les
 * identifiants à chaque enregistrement et effaçait toute donnée posée sur la ligne par
 * une autre fonctionnalité. C'est aussi le point d'entrée de la saisie manuelle des
 * bases demandée par l'issue #8.
 */
beforeEach(function () {
    $this->user = User::factory()->create();
    $this->service = new DepreciationService();

    // Base amortissable : 250 000 € - 20 % de terrain = 200 000 €, quote-part 1.
    $this->property = Property::forceCreate([
        'user_id' => $this->user->id,
        'name' => 'Bien sync',
        'address' => '1 rue Test',
        'city' => 'Paris',
        'postal_code' => '75001',
        'type' => 'apartment',
        'total_area' => 100,
        'rented_area' => 100,
        'acquisition_date' => '2020-01-01',
        'acquisition_price' => 25000000,
        'notary_fees' => 0,
        'land_percentage' => 20,
        'rental_start_date' => '2023-01-01',
        'rental_type' => 'seasonal',
        'is_primary_residence' => false,
    ]);
});

function syncLine(array $overrides = []): array
{
    return array_merge([
        'name' => 'Gros œuvre',
        'duration_years' => 50,
        'sort_order' => 1,
        'base_source' => PropertyComponent::BASE_SOURCE_PERCENTAGE,
        'percentage' => 100,
    ], $overrides);
}

it('stores a base typed by hand, to the cent', function () {
    $this->service->syncComponents($this->property, [
        syncLine([
            'base_source' => PropertyComponent::BASE_SOURCE_MANUAL,
            'base_amount' => 12_345_678, // 123 456,78 €
        ]),
    ]);

    $component = $this->property->components()->firstOrFail();

    expect((int) $component->base_amount)->toBe(12_345_678)
        ->and($component->base_source)->toBe(PropertyComponent::BASE_SOURCE_MANUAL);
});

it('derives the percentage from the base and never the reverse', function () {
    // 66 666,66 € sur une base de 200 000 € = 33,3333 %, impossible à exprimer
    // avec l'ancien pourcentage entier.
    $this->service->syncComponents($this->property, [
        syncLine(['base_source' => PropertyComponent::BASE_SOURCE_MANUAL, 'base_amount' => 6_666_666]),
    ]);

    $component = $this->property->components()->firstOrFail();

    expect($component->percentage)->toBe(33.3333)
        ->and((int) $component->base_amount)->toBe(6_666_666);
});

it('absorbs the truncation dust so components sum exactly to the depreciable base', function () {
    // 3 x 33,33 % tronqués séparément laissent quelques centimes sur le carreau.
    $this->service->syncComponents($this->property, [
        syncLine(['name' => 'A', 'percentage' => 33.33, 'sort_order' => 1]),
        syncLine(['name' => 'B', 'percentage' => 33.33, 'sort_order' => 2]),
        syncLine(['name' => 'C', 'percentage' => 33.34, 'sort_order' => 3]),
    ]);

    $allocated = (int) $this->property->components()->sum('base_amount');

    expect($allocated)->toBe((int) $this->property->depreciable_base);
});

it('leaves a deliberate under-allocation alone', function () {
    $result = $this->service->syncComponents($this->property, [
        syncLine(['percentage' => 80]),
    ]);

    // 20 % de 200 000 € = 40 000 € non ventilés, et surtout PAS gonflés en douce.
    expect($result['remainder'])->toBe('4000000')
        ->and((int) $this->property->components()->sum('base_amount'))->toBe(16_000_000);
});

it('refuses an over-allocation and writes nothing at all', function () {
    $this->service->syncComponents($this->property, [syncLine(['percentage' => 100])]);
    $before = $this->property->components()->pluck('base_amount', 'id')->toArray();

    expect(fn () => $this->service->syncComponents($this->property, [
        syncLine([
            'base_source' => PropertyComponent::BASE_SOURCE_MANUAL,
            'base_amount' => 99_999_999,
        ]),
    ]))->toThrow(RuntimeException::class, 'dépasse la base amortissable');

    expect($this->property->components()->pluck('base_amount', 'id')->toArray())->toBe($before);
});

it('updates components in place instead of deleting and recreating them', function () {
    $this->service->generateDefaultComponents($this->property);
    $before = $this->property->components()->orderBy('id')->pluck('id')->all();

    $lines = $this->property->components()->orderBy('sort_order')->get()
        ->map(fn ($c) => [
            'id' => $c->id,
            'name' => $c->name,
            'duration_years' => $c->duration_years,
            'sort_order' => $c->sort_order,
            'base_source' => PropertyComponent::BASE_SOURCE_PERCENTAGE,
            'percentage' => $c->percentage,
        ])->all();

    $this->service->syncComponents($this->property, $lines);

    expect($this->property->components()->orderBy('id')->pluck('id')->all())->toBe($before);
});

it('deletes the components left out of the payload', function () {
    $this->service->generateDefaultComponents($this->property);

    $this->service->syncComponents($this->property, [syncLine(['percentage' => 100])]);

    expect($this->property->components()->count())->toBe(1);
});

it('keeps an explicit annual depreciation in manual mode', function () {
    // Le cabinet précédent arrondissait autrement : 2 469,00 € et non 2 469,13 €.
    $this->service->syncComponents($this->property, [
        syncLine([
            'base_source' => PropertyComponent::BASE_SOURCE_MANUAL,
            'base_amount' => 12_345_678,
            'duration_years' => 50,
            'annual_depreciation' => 246_900,
        ]),
    ]);

    expect((int) $this->property->components()->firstOrFail()->annual_depreciation)->toBe(246_900);
});

it('recomputes the annual depreciation of a ventilated component', function () {
    $this->service->syncComponents($this->property, [
        syncLine(['percentage' => 50, 'duration_years' => 25, 'annual_depreciation' => 999]),
    ]);

    // 100 000 € sur 25 ans : la dotation fournie est ignorée hors mode manuel.
    expect((int) $this->property->components()->firstOrFail()->annual_depreciation)->toBe(400_000);
});
