<?php

use App\Models\Furniture;
use App\Models\Property;
use App\Models\PropertyWork;
use App\Models\User;
use App\Services\DepreciationService;

/**
 * La quote-part s'applique UNE fois à un travail ou un meuble non dédié.
 *
 * Elle s'appliquait deux fois : `expectedAnnualDepreciation()` la posait sur l'assiette
 * avant division et le hook `saving` figeait le résultat en base, puis
 * `DepreciationService::calculateWorkForYear()` la reposait à la lecture. La dotation
 * valait donc `q² × montant / durée`.
 *
 * ⚠️ Pourquoi ça a survécu : à quote-part 1 — la quasi-totalité des dossiers — `q² = q`,
 * le défaut est rigoureusement invisible. Les cinq tests du dépôt portant
 * `is_dedicated => false` avaient tous `rented_area == total_area`. **Un test qui n'écarte
 * pas les deux valeurs ne mesure rien ici**, et c'est la seule raison pour laquelle ces
 * tests-là fixent la quote-part à 60 %.
 */
function quotaProperty(User $user, array $overrides = []): Property
{
    return Property::forceCreate(array_merge([
        'user_id' => $user->id,
        'name' => 'Bien partiellement loué',
        'address' => '2 rue de la Quote-part',
        'city' => 'Paris',
        'postal_code' => '75001',
        'type' => 'apartment',
        // 60 m² loués sur 100 : q = 0,6, donc q² = 0,36. L'écart est massif et ne peut
        // pas passer pour un arrondi.
        'total_area' => 100,
        'rented_area' => 60,
        'acquisition_date' => '2022-01-01',
        'acquisition_price' => 20_000_000,
        'notary_fees' => 0,
        'agency_fees' => 0,
        'market_value' => null,
        'land_percentage' => 20,
        'rental_start_date' => '2022-01-01',
        'rental_type' => 'seasonal',
        'is_primary_residence' => false,
    ], $overrides));
}

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->depreciation = app(DepreciationService::class);
});

it('applies the quota share once to a shared work, not twice', function () {
    $property = quotaProperty($this->user);

    PropertyWork::create([
        'property_id' => $property->id,
        'description' => 'Réfection partagée',
        'amount' => 1_000_000,          // 10 000 €
        'work_date' => '2022-01-01',
        'duration_years' => 10,
        'is_dedicated' => false,
    ]);

    $line = collect($this->depreciation->depreciationDetailForYear($property->fresh(), 2025))
        ->firstWhere('type', 'work');

    // 10 000 € × 60 % ÷ 10 ans = 600 €. Avec la double application : 360 €.
    expect((int) $line['annual'])->toBe(60_000);
});

it('applies the quota share once to shared furniture, not twice', function () {
    $property = quotaProperty($this->user);

    Furniture::create([
        'property_id' => $property->id,
        'description' => 'Mobilier partagé',
        'amount' => 500_000,            // 5 000 €
        'purchase_date' => '2022-01-01',
        'duration_years' => 5,
        'is_dedicated' => false,
    ]);

    $line = collect($this->depreciation->depreciationDetailForYear($property->fresh(), 2025))
        ->firstWhere('type', 'furniture');

    // 5 000 € × 60 % ÷ 5 ans = 600 €. Avec la double application : 360 €.
    expect((int) $line['annual'])->toBe(60_000);
});

it('keeps a shared asset consistent with its own gross value', function () {
    // ⚠️ Le symptôme le plus parlant, et celui qui prouve que c'était bien un défaut et non
    // une convention : la valeur BRUTE de la ligne n'applique la quote-part qu'une fois
    // (`grossAmount()`). `base ÷ durée` ne retombait donc pas sur `annual` — la même ligne
    // du 2033-C se contredisait elle-même.
    $property = quotaProperty($this->user);

    PropertyWork::create([
        'property_id' => $property->id,
        'description' => 'Réfection partagée',
        'amount' => 1_000_000,
        'work_date' => '2022-01-01',
        'duration_years' => 10,
        'is_dedicated' => false,
    ]);

    $line = collect($this->depreciation->depreciationDetailForYear($property->fresh(), 2025))
        ->firstWhere('type', 'work');

    expect((int) bcdiv($line['base'], '10', 0))->toBe((int) $line['annual']);
});

it('amortises a shared asset fully by the end of its plan', function () {
    // Conséquence de la cohérence ci-dessus : sur la durée, le cumul rejoint la valeur
    // brute. Avec `q²`, il plafonnait à 60 % de la base et l'actif ne s'amortissait jamais.
    $property = quotaProperty($this->user);

    Furniture::create([
        'property_id' => $property->id,
        'description' => 'Mobilier partagé',
        'amount' => 500_000,
        'purchase_date' => '2022-01-01',
        'duration_years' => 5,
        'is_dedicated' => false,
    ]);

    // 2026 : les cinq annuités sont passées.
    $line = collect($this->depreciation->depreciationDetailForYear($property->fresh(), 2026))
        ->firstWhere('type', 'furniture');

    expect((int) $line['cumul'])->toBe((int) $line['base']);
});

it('leaves a dedicated asset untouched by the quota share', function () {
    // La contrepartie : un actif dédié à la location ne subit aucune quote-part, ni avant
    // ni après le correctif.
    $property = quotaProperty($this->user);

    PropertyWork::create([
        'property_id' => $property->id,
        'description' => 'Réfection dédiée',
        'amount' => 1_000_000,
        'work_date' => '2022-01-01',
        'duration_years' => 10,
        'is_dedicated' => true,
    ]);

    $line = collect($this->depreciation->depreciationDetailForYear($property->fresh(), 2025))
        ->firstWhere('type', 'work');

    expect((int) $line['annual'])->toBe(100_000)
        ->and((int) $line['base'])->toBe(1_000_000);
});

// ─────────────────────────────────────────────────────────────────────
// La part de terrain accepte des décimales
// ─────────────────────────────────────────────────────────────────────

it('lets the land share carry decimals through to the depreciable base', function () {
    // Signalé le 2026-09-09 : 17,5 % — une valeur d'acte ordinaire — n'était pas saisissable.
    //
    // ⚠️ Ce test-ci ne prouve QUE le calcul, pas le stockage : SQLite est faiblement typé et
    // rangeait déjà 17,5 dans une colonne `integer` sans broncher. Mesuré : il reste VERT
    // sans la migration `decimal(5,2)`. Celle-ci reste nécessaire pour PostgreSQL, où la
    // valeur serait tronquée — mais aucun test de ce dépôt ne peut le démontrer.
    //
    // Le vrai blocage était ailleurs, et c'est le test suivant qui le tient.
    $property = quotaProperty($this->user, [
        'total_area' => 100,
        'rented_area' => 100,
        'land_percentage' => 17.5,
    ]);

    // 20 000 000 × 82,5 %
    expect((int) $property->depreciable_base)->toBe(16_500_000)
        ->and((int) $property->depreciable_base)->not->toBe(16_600_000)   // arrondi à 17 %
        ->and((int) $property->depreciable_base)->not->toBe(16_400_000);  // arrondi à 18 %
});
