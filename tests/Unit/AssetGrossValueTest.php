<?php

use App\Models\Furniture;
use App\Models\Property;
use App\Models\PropertyComponent;
use App\Models\PropertyWork;
use App\Models\User;
use App\Services\DepreciationService;

/**
 * La valeur BRUTE d'un travail ou d'un meuble au bilan, et la ligne du 2033-C qui la porte.
 *
 * Deux défauts corrigés le 2026-09-08, tous deux trouvés en cartographiant le code pour
 * l'issue #11 plutôt qu'en le testant :
 *
 *   1. la valeur brute était prise en TTC alors que la dotation se calcule sur le HT dès que
 *      le bien est assujetti à la TVA. Les cases 028/044 et la ligne 490 portaient donc la
 *      TVA d'un actif dont elle avait été récupérée, et la ligne ne s'amortissait jamais
 *      entièrement ;
 *   2. la ligne Cerfa était codée en dur — travaux en agencements, mobilier en autres —, si
 *      bien qu'un cabinet qui classait des travaux de gros œuvre en constructions ne pouvait
 *      pas être reproduit, et le contrôle de reprise signalait un écart insoluble.
 */
function grossValueProperty(User $user, array $overrides = []): Property
{
    return Property::forceCreate(array_merge([
        'user_id' => $user->id,
        'name' => 'Bien brut',
        'address' => '3 rue du Brut',
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

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->depreciation = app(DepreciationService::class);
});

it('books works and furniture excluding VAT when the property is liable', function () {
    $property = grossValueProperty($this->user, ['tva_regime' => Property::TVA_LIABLE]);

    $work = PropertyWork::create([
        'property_id' => $property->id,
        'description' => 'Réfection',
        'amount' => 1_200_000,   // 12 000 € TTC
        'tva_rate' => 2000,   // 20 %, le taux étant exprimé en points de base
        'work_date' => '2023-01-01',
        'duration_years' => 10,
        'is_dedicated' => true,
    ]);

    $item = Furniture::create([
        'property_id' => $property->id,
        'description' => 'Mobilier',
        'amount' => 600_000,     // 6 000 € TTC
        'tva_rate' => 2000,   // 20 %, le taux étant exprimé en points de base
        'purchase_date' => '2023-01-01',
        'duration_years' => 5,
        'is_dedicated' => true,
    ]);

    $lines = collect($this->depreciation->depreciationDetailForYear($property->fresh(), 2025))
        ->keyBy('type');

    // 10 000 € et 5 000 € HT : la TVA a été récupérée, elle n'est pas au bilan.
    expect((int) $lines['work']['base'])->toBe((int) $work->amount_ht)->toBe(1_000_000)
        ->and((int) $lines['furniture']['base'])->toBe((int) $item->amount_ht)->toBe(500_000);
});

it('books the VAT-inclusive amount when the property is not liable', function () {
    $property = grossValueProperty($this->user);

    PropertyWork::create([
        'property_id' => $property->id,
        'description' => 'Réfection',
        'amount' => 1_200_000,
        'tva_rate' => 2000,   // 20 %, le taux étant exprimé en points de base
        'work_date' => '2023-01-01',
        'duration_years' => 10,
        'is_dedicated' => true,
    ]);

    $line = collect($this->depreciation->depreciationDetailForYear($property->fresh(), 2025))
        ->firstWhere('type', 'work');

    expect((int) $line['base'])->toBe(1_200_000);
});

it('lets an asset reach the full end of its plan, gross value and cumul meeting', function () {
    // Le symptôme direct du défaut : brut en TTC contre dotations en HT, la ligne restait
    // éternellement partiellement amortie et le bilan portait un actif net qui n'existait pas.
    $property = grossValueProperty($this->user, ['tva_regime' => Property::TVA_LIABLE]);

    PropertyWork::create([
        'property_id' => $property->id,
        'description' => 'Réfection',
        'amount' => 1_200_000,
        'tva_rate' => 2000,   // 20 %, le taux étant exprimé en points de base
        'work_date' => '2023-01-01',
        'duration_years' => 5,
        'is_dedicated' => true,
    ]);

    // 2030 : les cinq annuités sont passées.
    $line = collect($this->depreciation->depreciationDetailForYear($property->fresh(), 2030))
        ->firstWhere('type', 'work');

    expect((int) $line['cumul'])->toBe((int) $line['base']);
});

it('carries works and furniture on the 2033-C line their accountant chose', function () {
    $property = grossValueProperty($this->user);

    PropertyWork::create([
        'property_id' => $property->id,
        'description' => 'Gros œuvre repris du cabinet',
        'amount' => 1_000_000,
        'work_date' => '2023-01-01',
        'duration_years' => 25,
        'cerfa_category' => PropertyComponent::CERFA_CATEGORY_CONSTRUCTIONS,
        'is_dedicated' => true,
    ]);

    Furniture::create([
        'property_id' => $property->id,
        'description' => 'Cuisine équipée',
        'amount' => 500_000,
        'purchase_date' => '2023-01-01',
        'duration_years' => 10,
        'cerfa_category' => PropertyComponent::CERFA_CATEGORY_FITTINGS,
        'is_dedicated' => true,
    ]);

    $lines = collect($this->depreciation->depreciationDetailForYear($property->fresh(), 2025))
        ->keyBy('type');

    expect($lines['work']['cerfa_category'])->toBe(PropertyComponent::CERFA_CATEGORY_CONSTRUCTIONS)
        ->and($lines['furniture']['cerfa_category'])->toBe(PropertyComponent::CERFA_CATEGORY_FITTINGS);
});

it('falls back on the historical lines when no category was chosen', function () {
    // `null` vaut l'ancien comportement : aucune ligne existante ne change de place à la
    // migration, il n'y a donc rien à réparer après la mise à jour.
    $property = grossValueProperty($this->user);

    PropertyWork::create([
        'property_id' => $property->id,
        'description' => 'Réfection',
        'amount' => 1_000_000,
        'work_date' => '2023-01-01',
        'duration_years' => 10,
        'is_dedicated' => true,
    ]);

    Furniture::create([
        'property_id' => $property->id,
        'description' => 'Mobilier',
        'amount' => 500_000,
        'purchase_date' => '2023-01-01',
        'duration_years' => 5,
        'is_dedicated' => true,
    ]);

    $lines = collect($this->depreciation->depreciationDetailForYear($property->fresh(), 2025))
        ->keyBy('type');

    expect($lines['work']['cerfa_category'])->toBe(PropertyComponent::CERFA_CATEGORY_FITTINGS)
        ->and($lines['furniture']['cerfa_category'])->toBe(PropertyComponent::CERFA_CATEGORY_OTHER);
});
