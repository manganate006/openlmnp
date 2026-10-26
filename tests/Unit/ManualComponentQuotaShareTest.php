<?php

use App\Models\Property;
use App\Models\PropertyComponent;
use App\Models\User;
use App\Services\DepreciationService;

/**
 * CONVENTION — voir le docblock de `Property::getDepreciableBaseAttribute()` et de
 * `RepriseDossier::applyLiasse()` : le 2033-C d'un bien PARTIELLEMENT loué (`quota_share
 * < 1`) porte déjà la part professionnelle de chaque immobilisation, jamais la valeur du
 * bien entier — un cabinet n'inscrit à son bilan que ce qui relève de l'activité BIC.
 * Une base `manual` (reprise d'une liasse) est donc copiée TELLE QUELLE dans
 * `base_amount`/`annual_depreciation`, sans jamais être re-multipliée par `quota_share` :
 * elle l'a déjà subie chez le cabinet.
 *
 * Chiffres d'un dossier de test (2033-C, ligne Constructions), sur un compte dont la
 * quote-part est 30 m² / 115 m² = 26,09 % : base 152 340 €, dotation annuelle déclarée par
 * le cabinet 5 790 € (volontairement différente de base ÷ durée). `quota_share` != 1 est
 * délibérément non rond, pour qu'une remultiplication ne passe pas inaperçue derrière un
 * facteur 1.
 *
 * ⚠️ Si ce test ne pinçait que le calcul de LECTURE (`depreciationDetailForYear()`) sans
 * couvrir aussi `calculateAnnualDepreciation()` — qui alimente la ligne 254 du 2033-B — une
 * régression sur l'un des deux resterait invisible à l'autre, cassant l'égalité 572 = 254.
 */
it('keeps a manual component base and its annual dotation exactly as declared by the cabinet, quota_share never reapplied', function () {
    $user = User::factory()->create();

    $property = Property::forceCreate([
        'user_id' => $user->id, 'name' => 'Résidence partagée', 'address' => '1 rue Test',
        'city' => 'Paris', 'postal_code' => '75001', 'type' => 'house',
        'total_area' => 115, 'rented_area' => 30, // quota_share = 30/115 ≈ 26,09 %
        'acquisition_date' => '2020-01-01', 'market_value' => 80_000_000,
        'market_value_date' => '2025-01-01', 'acquisition_price' => 80_000_000,
        'notary_fees' => 0, 'land_percentage' => 15,
        'rental_start_date' => '2025-01-01', 'rental_type' => 'seasonal',
        'is_primary_residence' => true,
    ]);

    app(DepreciationService::class)->syncComponents($property, [
        [
            'id' => null, 'name' => 'Constructions', 'duration_years' => 26,
            'sort_order' => 0, 'base_source' => PropertyComponent::BASE_SOURCE_MANUAL,
            'base_amount' => 15_234_000,       // 152 340,00 € — valeur brute déclarée
            'annual_depreciation' => 579_000,  // 5 790,00 € — dotation déclarée par le cabinet
        ],
    ]);

    $property = $property->fresh();
    $component = $property->components->firstOrFail();

    // Stockage : verbatim, aucune trace de quota_share dans base_amount/annual_depreciation.
    expect((int) $component->base_amount)->toBe(15_234_000)
        ->and((int) $component->annual_depreciation)->toBe(579_000);

    // Lecture pour la liasse (2033-C) : la dotation de l'exercice reste celle du cabinet.
    $detail = app(DepreciationService::class)->depreciationDetailForYear($property, 2025);
    $buildingLine = collect($detail)->firstWhere('type', 'building');
    expect((int) $buildingLine['annual'])->toBe(579_000)
        ->and((int) $buildingLine['base'])->toBe(15_234_000);

    // Lecture pour le résultat fiscal (ligne 254 du 2033-B, doit tomber d'accord avec 572).
    $annual = app(DepreciationService::class)->calculateAnnualDepreciation($property, 2025);
    expect((int) $annual['building'])->toBe(579_000);
});
