<?php

use App\Models\Furniture;
use App\Models\Property;
use App\Models\PropertyWork;
use App\Models\User;
use App\Services\DepreciationService;

/**
 * Valeurs d'or de l'amortissement, capturées le 2026-09-03 sur la v1.1.7 corrigée du
 * prorata (commit `6902ad6f`), AVANT le chantier « saisie manuelle des bases ».
 *
 * Ce chantier bascule la source de vérité du pourcentage vers `base_amount`, migre
 * `percentage` en décimal et remplace le delete/recreate des composants par une
 * synchronisation. Aucun de ces changements ne doit déplacer d'un centime le calcul
 * d'un bien créé normalement : ce fichier est le garde-fou qui le prouve.
 *
 * Le bien de référence exerce délibérément tout ce qui peut diverger :
 *   - quote-part 35/120 sur résidence principale ;
 *   - prorata de première année (mise en location au 15 mars) ;
 *   - travaux ANTÉRIEURS à la mise en location, donc amortis avant le bâti ;
 *   - mobilier acheté deux ans après ;
 *   - frais de notaire (25 ans) ;
 *   - une année où une partie des composants est arrivée à terme (2038) ;
 *   - une année où tout est fini (2073).
 *
 * Si l'un de ces montants change, ce n'est pas au test de s'adapter : c'est qu'un calcul
 * a bougé, et il faut alors une commande de réparation pour les bases déjà en production
 * (règle du projet sur les données dérivées).
 */
beforeEach(function () {
    $this->user = User::factory()->create();
    $this->service = new DepreciationService();

    $this->property = Property::forceCreate([
        'user_id' => $this->user->id,
        'name' => 'Bien de référence',
        'address' => '1 rue Test',
        'city' => 'Paris',
        'postal_code' => '75001',
        'type' => 'apartment',
        'total_area' => 120,
        'rented_area' => 35,
        'acquisition_date' => '2022-06-10',
        'acquisition_price' => 31700000,
        'notary_fees' => 2537000,
        'agency_fees' => 0,
        'market_value' => null,
        'land_percentage' => 17,
        'rental_start_date' => '2023-03-15',
        'rental_type' => 'seasonal',
        'is_primary_residence' => true,
    ]);

    $this->service->generateDefaultComponents($this->property);

    PropertyWork::create([
        'property_id' => $this->property->id,
        'description' => 'Rénovation',
        'work_date' => '2022-09-20',
        'amount' => 1837500,
        'tva_rate' => 20,
        'duration_years' => 10,
        'is_dedicated' => false,
    ]);

    Furniture::create([
        'property_id' => $this->property->id,
        'description' => 'Mobilier',
        'purchase_date' => '2024-05-02',
        'amount' => 428000,
        'tva_rate' => 20,
        'duration_years' => 5,
        'is_dedicated' => true,
    ]);
});

it('keeps the depreciable base and the generated components unchanged', function () {
    expect($this->property->depreciable_base)->toBe('7674041');

    $components = $this->property->components;

    expect($components->pluck('base_amount')->all())
        ->toBe([3837020, 767404, 767404, 383702, 1151106, 767404])
        ->and($components->pluck('annual_depreciation')->all())
        ->toBe([76740, 30696, 30696, 25580, 76740, 51160]);
});

it('keeps every yearly depreciation unchanged', function (int $year, array $expected) {
    $result = $this->service->calculateAnnualDepreciation($this->property->fresh(), $year);

    expect([
        $result['building'],
        $result['works'],
        $result['furniture'],
        $result['notary'],
        $result['total'],
    ])->toBe($expected);
})->with([
    // année      bâti       travaux    mobilier  notaire   total
    'travaux seuls, avant la mise en location' => [2022, ['0', '4410', '0', '0', '4410']],
    'première année, au prorata'               => [2023, ['233288', '15631', '0', '23678', '272597']],
    'première année pleine, avec mobilier'     => [2024, ['291612', '15631', '57066', '29598', '393907']],
    'mobilier arrivé à terme'                  => [2029, ['291612', '15631', '0', '29598', '336841']],
    'composants partiellement à terme'         => [2038, ['138132', '0', '0', '29598', '167730']],
    'plan entièrement amorti'                  => [2073, ['0', '0', '0', '0', '0']],
]);

it('sums the component details to the building total', function () {
    $result = $this->service->calculateAnnualDepreciation($this->property->fresh(), 2024);

    $building = collect($result['details'])
        ->where('type', 'building')
        ->reduce(fn ($carry, $line) => bcadd($carry, $line['amount'], 0), '0');

    expect($building)->toBe($result['building']);
});
