<?php

use App\Models\Property;
use App\Models\PropertyComponent;
use App\Models\User;
use App\Services\DepreciationService;

/**
 * Une ventilation en POURCENTAGE suit la valeur du bien (issue #11 de cocool97).
 *
 * Le défaut : `base_amount` est la source de vérité depuis la 1.2.0, et rien ne la
 * recalculait quand la base amortissable changeait. Baisser la valeur du bien laissait les
 * composants à leur ancien montant, et l'utilisateur ne l'apprenait qu'à la génération de sa
 * liasse — 4 335 € d'écart chez le rapporteur, pour une valeur passée de 103 500 € à 98 400 €.
 *
 * ⚠️ Les valeurs de ce fichier sont celles de son dossier, reconstituées depuis ses captures
 * et recoupées sur ses trois totaux (028 = 103 500, 044 = 112 400, 490 = 116 735). Elles
 * servent de cas d'or : si le recalage change, c'est ce scénario-là qui doit le dire.
 */
beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

function bienDeCocool(int $valeurEnCentimes = 10_350_000): Property
{
    return Property::create([
        'user_id'           => auth()->id(),
        'name'              => 'Bien de reprise',
        'address'           => '1 rue du Test',
        'city'              => 'Lyon',
        'postal_code'       => '69003',
        'type'              => 'apartment',
        'total_area'        => 45,
        'rented_area'       => 45,
        'acquisition_date'  => '2022-01-01',
        'acquisition_price' => $valeurEnCentimes,
        'market_value'      => null,
        'land_percentage'   => 15,
        'rental_start_date' => '2022-03-01',
        'rental_type'       => 'seasonal',
        'is_primary_residence' => false,
    ]);
}

it('recale les composants ventilés en pourcentage quand la valeur du bien baisse', function () {
    $property = bienDeCocool();

    // Ventilation 60 / 20 / 20 sur la base de l'époque : 103 500 € × 0,85 = 87 975 €.
    app(DepreciationService::class)->syncComponents($property, [
        ['name' => 'Gros œuvre', 'duration_years' => 40, 'sort_order' => 0, 'percentage' => 60],
        ['name' => 'Installations', 'duration_years' => 20, 'sort_order' => 1, 'percentage' => 20],
        ['name' => 'Agencements', 'duration_years' => 15, 'sort_order' => 2, 'percentage' => 20],
    ]);

    expect((int) $property->fresh()->components->sum('base_amount'))->toBe(8_797_500);

    // Il sort 5 100 € du prix du bien pour les déclarer à part.
    $property->update(['acquisition_price' => 9_840_000]);

    $property->refresh();
    $base = (int) $property->depreciable_base;

    expect($base)->toBe(8_364_000)
        ->and((int) $property->components->sum('base_amount'))->toBe($base);
});

it('laisse intacte une base saisie à la main', function () {
    $property = bienDeCocool();

    app(DepreciationService::class)->syncComponents($property, [
        ['name' => 'Recopié du cabinet', 'duration_years' => 40, 'sort_order' => 0,
            'base_source' => PropertyComponent::BASE_SOURCE_MANUAL, 'base_amount' => 5_000_000],
        ['name' => 'Ventilé', 'duration_years' => 20, 'sort_order' => 1, 'percentage' => 10],
    ]);

    // ⚠️ On repère le composant par son NOM, jamais par `base_source` : c'est justement
    // l'attribut qu'une régression ferait basculer, et un test ancré dessus ne trouverait
    // plus la ligne au lieu de constater qu'elle a changé.
    $manuelAvant = (int) $property->fresh()->components
        ->firstWhere('name', 'Recopié du cabinet')->base_amount;

    $property->update(['acquisition_price' => 9_840_000]);

    $apres = $property->fresh()->components->firstWhere('name', 'Recopié du cabinet');

    expect((int) $apres->base_amount)->toBe($manuelAvant)->toBe(5_000_000)
        ->and($apres->base_source)->toBe(PropertyComponent::BASE_SOURCE_MANUAL);
});

it('recale aussi quand c\'est la part du terrain qui change', function () {
    $property = bienDeCocool();

    app(DepreciationService::class)->syncComponents($property, [
        ['name' => 'Gros œuvre', 'duration_years' => 40, 'sort_order' => 0, 'percentage' => 100],
    ]);

    $property->update(['land_percentage' => 30]);

    $property->refresh();
    expect((int) $property->components->sum('base_amount'))
        ->toBe((int) $property->depreciable_base)
        ->toBe(7_245_000); // 103 500 € × 0,70
});

it('ne touche à rien quand une modification ne change pas la base', function () {
    $property = bienDeCocool();

    app(DepreciationService::class)->syncComponents($property, [
        ['name' => 'Gros œuvre', 'duration_years' => 40, 'sort_order' => 0, 'percentage' => 100],
    ]);

    $avant = $property->fresh()->components->first()->updated_at;

    $property->update(['name' => 'Nom changé, base inchangée']);

    expect($property->fresh()->components->first()->updated_at->eq($avant))->toBeTrue();
});

it('ne rogne jamais une base saisie, même quand elle dépasse à elle seule', function () {
    // ⚠️ Ce test affirmait auparavant que le recalage RENONÇAIT entièrement dans ce cas.
    //    C'était le défaut mesuré en production le 2026-09-06 : abandonner tout laissait les
    //    composants en pourcentage sur une base disparue. La garantie qui compte n'a pas
    //    changé — un montant saisi n'est jamais rogné — mais elle ne justifie pas de figer
    //    aussi ceux qui devaient suivre.
    $property = bienDeCocool();

    app(DepreciationService::class)->syncComponents($property, [
        ['name' => 'Recopié du cabinet', 'duration_years' => 40, 'sort_order' => 0,
            'base_source' => PropertyComponent::BASE_SOURCE_MANUAL, 'base_amount' => 8_700_000],
        ['name' => 'Ventilé', 'duration_years' => 20, 'sort_order' => 1, 'percentage' => 1],
    ]);

    // La nouvelle base (2 070 000) est très inférieure à la seule base saisie.
    $property->update(['land_percentage' => 80]);
    $property->refresh();

    $composants = $property->components->keyBy('name');

    expect((int) $composants['Recopié du cabinet']->base_amount)->toBe(8_700_000)
        // ... et le composant ventilé suit bien la nouvelle base : 1 % de 2 070 000.
        ->and((int) $composants['Ventilé']->base_amount)->toBe(20_700)
        // Le débordement subsiste, et c'est ce que le bandeau et la liasse signalent.
        ->and(app(DepreciationService::class)->overAllocation($property))->toBeGreaterThan(0);
});

/**
 * Le garde-fou d'accord entre l'accesseur et l'observer — COMPORTEMENTAL, plus textuel.
 *
 * ⚠️ La version d'avant comparait des CHAÎNES : elle vérifiait que « quota_share » figurait
 * à la fois dans le corps de `getDepreciableBaseAttribute()` et dans `CHAMPS_DE_LA_BASE`.
 * Les deux étaient vraies, et pourtant le recalage ne partait jamais sur une surface : la
 * quote-part est un ACCESSEUR (`rented_area / total_area`), pas une colonne, donc
 * `wasChanged('quota_share')` est toujours faux. Le test annonçait plus qu'il ne mesurait —
 * exactement le mode d'échec que ce fichier est censé fermer.
 *
 * On mesure donc l'effet : chaque champ dont dépend la base est modifié pour de vrai, et le
 * composant ventilé doit avoir suivi.
 */
it('recale sur CHAQUE champ dont dépend la base amortissable', function (string $champ, mixed $valeur) {
    $property = bienDeCocool();

    app(DepreciationService::class)->syncComponents($property, [
        ['name' => 'Gros œuvre', 'duration_years' => 40, 'sort_order' => 0, 'percentage' => 100],
    ]);

    $property->update([$champ => $valeur]);
    $property->refresh();

    expect((int) $property->components->sum('base_amount'))
        ->toBe((int) $property->depreciable_base)
        // Sans quoi le test passerait aussi sur une modification qui ne change rien.
        ->not->toBe(8_797_500);
})->with([
    'prix d\'acquisition' => ['acquisition_price', 9_840_000],
    'valeur vénale'       => ['market_value', 12_000_000],
    'part du terrain'     => ['land_percentage', 30],
    // Les deux surfaces composent la quote-part, qui n'est pas une colonne : c'est
    // précisément le cas que le garde-fou textuel ne voyait pas.
    'surface louée'       => ['rented_area', 30],
    'surface totale'      => ['total_area', 90],
]);

/**
 * L'INVARIANT dont dépend l'accord entre les deux compteurs de l'écran.
 *
 * L'éditeur dérive la base d'un composant en pourcentage de la base COURANTE
 * (`baseCentsOf()` dans `depreciation-editor-assets.blade.php`), alors que
 * `overAllocation()` somme les valeurs STOCKÉES. Les deux ne peuvent diverger que si une
 * base stockée est périmée — c'est ce qui affichait « 23 812 € » dans le bandeau contre un
 * écart de 11 416 € déduit des cartes, mesuré en production le 2026-09-06.
 *
 * Asserter l'invariant plutôt que le symptôme : tant que chaque composant en pourcentage
 * porte exactement sa part de la base courante, la contradiction est impossible.
 */
it('garde toute base en pourcentage égale à sa part de la base courante', function () {
    $property = bienDeCocool();

    app(DepreciationService::class)->syncComponents($property, [
        ['name' => 'Recopié du cabinet', 'duration_years' => 40, 'sort_order' => 0,
            'base_source' => PropertyComponent::BASE_SOURCE_MANUAL, 'base_amount' => 6_100_000],
        ['name' => 'Ventilé A', 'duration_years' => 25, 'sort_order' => 1, 'percentage' => 20],
        ['name' => 'Ventilé B', 'duration_years' => 15, 'sort_order' => 2, 'percentage' => 10],
    ]);

    // La valeur baisse : la base passe sous la somme (le montant saisi ne bouge pas).
    $property->update(['acquisition_price' => 8_000_000]);
    $property->refresh();

    $base = (string) $property->depreciable_base;

    foreach ($property->components as $composant) {
        if ($composant->base_source !== PropertyComponent::BASE_SOURCE_PERCENTAGE) {
            continue;
        }

        expect((int) $composant->base_amount)->toBe(
            (int) DepreciationService::baseFromPercentage($base, (string) $composant->percentage),
            "le composant « {$composant->name} » n'est plus aligné sur la base courante",
        );
    }

    // Et le montant saisi, lui, est resté intact — c'est ce qui fait déborder, légitimement.
    expect((int) $property->components->firstWhere('name', 'Recopié du cabinet')->base_amount)
        ->toBe(6_100_000)
        ->and(app(DepreciationService::class)->overAllocation($property))->toBeGreaterThan(0);
});
