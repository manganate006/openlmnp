<?php

use App\Models\Property;
use App\Models\PropertyComponent;
use App\Models\User;
use App\Observers\PropertyObserver;
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

it('renonce plutôt que de rogner une base manuelle devenue trop grande', function () {
    $property = bienDeCocool();

    app(DepreciationService::class)->syncComponents($property, [
        ['name' => 'Recopié du cabinet', 'duration_years' => 40, 'sort_order' => 0,
            'base_source' => PropertyComponent::BASE_SOURCE_MANUAL, 'base_amount' => 8_700_000],
        ['name' => 'Ventilé', 'duration_years' => 20, 'sort_order' => 1, 'percentage' => 1],
    ]);

    $avant = $property->fresh()->components->pluck('base_amount', 'name')->all();

    // La nouvelle base (2 070 000) est très inférieure à la seule base manuelle.
    $property->update(['land_percentage' => 80]);

    expect($property->fresh()->components->pluck('base_amount', 'name')->all())->toBe($avant);
});

it('surveille exactement les champs dont dépend la base amortissable', function () {
    // Garde-fou d'accord : si `Property::getDepreciableBaseAttribute()` gagne une entrée,
    // l'observer doit la surveiller, sinon le recalage redevient partiel en silence.
    $reflection = new ReflectionClass(PropertyObserver::class);
    $surveilles = $reflection->getConstant('CHAMPS_DE_LA_BASE');

    $source = file_get_contents(app_path('Models/Property.php'));
    $accesseur = substr(
        $source,
        (int) strpos($source, 'function getDepreciableBaseAttribute'),
        900,
    );

    foreach (['market_value', 'acquisition_price', 'land_percentage', 'quota_share'] as $champ) {
        expect($accesseur)->toContain($champ)
            ->and($surveilles)->toContain($champ);
    }
});
