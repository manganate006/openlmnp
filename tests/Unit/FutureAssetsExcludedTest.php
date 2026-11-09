<?php

use App\Models\Furniture;
use App\Models\Property;
use App\Models\PropertyWork;
use App\Models\User;
use App\Services\DepreciationService;
use App\Services\FiscalYearService;
use App\Services\TaxReturnService;

/**
 * Un actif mis en service APRÈS la clôture n'appartient pas au bilan de l'exercice.
 *
 * Jusqu'au 2026-10-29 (OpenProject n° 2362), `depreciationDetailForYear()` rendait tous les
 * composants, travaux, meubles et frais d'acquisition du bien sans regarder leur date. Un
 * meuble acheté en N+1 entrait donc dans la valeur brute de la liasse N si on la régénérait
 * après l'avoir saisi : dotation nulle, mais cases 028/044 du 2033-A, colonnes du 2033-C et
 * annexe gonflées. Même chose pour un bien dont la location ne commence qu'en N+1.
 *
 * Montants synthétiques, sans rapport avec un dossier réel.
 */
function futureAssetProperty(User $user, array $overrides = []): Property
{
    return Property::forceCreate(array_merge([
        'user_id' => $user->id,
        'name' => 'Bien futur',
        'address' => '7 rue du Futur',
        'city' => 'Paris',
        'postal_code' => '75001',
        'type' => 'apartment',
        'total_area' => 100,
        'rented_area' => 100,
        'acquisition_date' => '2022-01-01',
        'acquisition_price' => 20_000_000,
        'notary_fees' => 0,
        'agency_fees' => 0,
        'market_value' => null,
        'land_percentage' => 15,
        'rental_start_date' => '2023-01-01',
        'rental_type' => 'seasonal',
        'is_primary_residence' => false,
    ], $overrides));
}

function futureFurniture(Property $property, string $date, int $amount = 300_000): Furniture
{
    return Furniture::create([
        'property_id' => $property->id,
        'description' => 'Canapé ' . $date,
        'amount' => $amount,
        'purchase_date' => $date,
        'duration_years' => 5,
        'is_dedicated' => true,
    ]);
}

/** @return array{a: array, c: array, annexe: array} */
function futureLiasse(User $user, int $year): array
{
    $tax = app(TaxReturnService::class);
    $properties = Property::withoutGlobalScopes()->where('user_id', $user->id)->get();
    $fy = app(FiscalYearService::class)->getOrCreate($user, $year);

    return [
        'a' => $tax->compute2033A($fy, $properties, $year, 0),
        'c' => $tax->compute2033C($properties, $year),
        'annexe' => $tax->assetBreakdown($properties, $year),
    ];
}

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->depreciation = app(DepreciationService::class);
});

it('leaves furniture bought the following year out of the gross values', function () {
    $property = futureAssetProperty($this->user);
    $this->depreciation->generateDefaultComponents($property);

    $before = futureLiasse($this->user, 2025);

    futureFurniture($property, '2026-03-15');

    $after = futureLiasse($this->user, 2025);

    expect($after['a']['028'])->toBe($before['a']['028'])
        ->and($after['a']['044'])->toBe($before['a']['044'])
        ->and($after['c']['total_debut'])->toBe($before['c']['total_debut'])
        ->and($after['c']['total_augmentations'])->toBe($before['c']['total_augmentations'])
        ->and($after['c']['total_brut'])->toBe($before['c']['total_brut'])
        ->and($after['c']['categories']['autres']['brut'])->toBe(0)
        ->and(collect($after['annexe'])->where('origin', 'Mobilier'))->toBeEmpty();

    // Et l'année suivante, il entre bien, en augmentation.
    $next = futureLiasse($this->user, 2026);
    expect($next['c']['categories']['autres']['augmentations'])->toBe(300_000)
        ->and($next['a']['028'] - $after['a']['028'])->toBe(300_000);
});

it('leaves works of the following year out of the gross values', function () {
    $property = futureAssetProperty($this->user);
    $this->depreciation->generateDefaultComponents($property);

    $before = futureLiasse($this->user, 2025);

    PropertyWork::create([
        'property_id' => $property->id,
        'description' => 'Salle de bains',
        'amount' => 900_000,
        'work_date' => '2026-02-01',
        'duration_years' => 10,
        'is_dedicated' => true,
    ]);

    $after = futureLiasse($this->user, 2025);

    expect($after['a']['028'])->toBe($before['a']['028'])
        ->and($after['a']['044'])->toBe($before['a']['044'])
        ->and($after['c']['total_brut'])->toBe($before['c']['total_brut'])
        ->and($after['c']['total_augmentations'])->toBe($before['c']['total_augmentations'])
        ->and(collect($after['annexe'])->where('origin', 'Travaux'))->toBeEmpty();
});

it('carries no fixed asset for a property rented out only the following year', function () {
    $property = futureAssetProperty($this->user, [
        'rental_start_date' => '2026-04-01',
        'notary_fees' => 1_500_000,
    ]);
    $this->depreciation->generateDefaultComponents($property);
    futureFurniture($property, '2025-11-01');

    $liasse = futureLiasse($this->user, 2025);

    expect($liasse['a']['028'])->toBe(0)
        ->and($liasse['a']['014'])->toBe(0)
        ->and($liasse['a']['030'])->toBe(0)
        ->and($liasse['a']['044'])->toBe(0)
        ->and($liasse['c']['total_brut'])->toBe(0)
        ->and($liasse['c']['total_debut'])->toBe(0)
        ->and($liasse['c']['total_augmentations'])->toBe(0)
        ->and($liasse['c']['categories']['terrains']['brut'])->toBe(0)
        ->and($liasse['annexe'])->toBe([])
        ->and($this->depreciation->depreciationDetailForYear($property->fresh(), 2025))->toBe([]);
});

it('includes an asset put in service on 31 December and excludes one on 1 January', function () {
    $property = futureAssetProperty($this->user);

    futureFurniture($property, '2025-12-31', 200_000);
    futureFurniture($property, '2026-01-01', 700_000);

    $lines = collect($this->depreciation->depreciationDetailForYear($property->fresh(), 2025))
        ->where('type', 'furniture');

    expect($lines)->toHaveCount(1)
        ->and((int) $lines->first()['base'])->toBe(200_000)
        ->and($lines->first()['in_service'])->toBe('2025-12-31');

    $c = futureLiasse($this->user, 2025)['c'];
    expect($c['categories']['autres']['augmentations'])->toBe(200_000)
        ->and($c['categories']['autres']['brut'])->toBe(200_000);
});

it('does not change the depreciation charge of the year', function () {
    $property = futureAssetProperty($this->user);
    $this->depreciation->generateDefaultComponents($property);

    $before = $this->depreciation->calculateAnnualDepreciation($property->fresh(), 2025)['total'];
    futureFurniture($property, '2026-03-15');
    $after = $this->depreciation->calculateAnnualDepreciation($property->fresh(), 2025)['total'];

    $l572 = futureLiasse($this->user, 2025)['c']['total_dotation'];

    expect($after)->toBe($before)->and((string) $l572)->toBe((string) $after);
});
