<?php

use App\Models\Furniture;
use App\Models\Property;
use App\Models\PropertyWork;
use App\Models\User;
use App\Services\DepreciationService;
use App\Services\FiscalYearService;
use App\Services\TaxReturnService;

/**
 * Cohérence du tableau 2033-C.
 *
 * Jusqu'au 2026-09-03, la liasse imprimait « ⚠ Écart : ligne 572 ≠ ligne 254 » sur trois
 * défauts cumulés, et les trois exercices réels de la production le déclenchaient :
 *
 *   1. la ligne 572 sommait les `annual_depreciation` BRUTS — sans prorata de première
 *      année, sans tenir compte des plans arrivés à terme — quand la 254 somme les
 *      dotations effectives ;
 *   2. les frais de notaire et d'agence n'avaient AUCUNE ligne dans ce tableau, alors que
 *      la 254 les compte ;
 *   3. le cumul était écrasé d'un bien à l'autre (`=` au lieu de `+=`), puis approximé par
 *      `dotation × années`.
 *
 * Les deux lignes viennent désormais des mêmes méthodes de calcul : l'égalité est vraie
 * par construction, et ces tests sont là pour qu'elle le reste.
 */
function taxProperty(User $user, array $overrides = []): Property
{
    return Property::forceCreate(array_merge([
        'user_id' => $user->id,
        'name' => 'Bien liasse',
        'address' => '1 rue Test',
        'city' => 'Paris',
        'postal_code' => '75001',
        'type' => 'apartment',
        'total_area' => 100,
        'rented_area' => 100,
        'acquisition_date' => '2022-01-01',
        'acquisition_price' => 25000000,
        'notary_fees' => 0,
        'agency_fees' => 0,
        'market_value' => null,
        'land_percentage' => 20,
        'rental_start_date' => '2023-01-01',
        'rental_type' => 'seasonal',
        'is_primary_residence' => false,
    ], $overrides));
}

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->depreciation = app(DepreciationService::class);
    $this->taxReturn = app(TaxReturnService::class);
});

/** Les deux lignes que le contrôle du PDF compare. */
function lines572and254(TaxReturnService $svc, User $user, int $year): array
{
    $properties = Property::withoutGlobalScopes()->where('user_id', $user->id)->get();
    $fy = app(FiscalYearService::class)->getOrCreate($user, $year);

    return [
        $svc->compute2033C($properties, $year)['total_dotation'],
        $svc->compute2033B($fy, $properties, $year)['254'],
    ];
}

it('matches line 572 with line 254 when the property carries acquisition fees', function () {
    // Le cas de production : des frais de notaire, que le 2033-C ignorait totalement.
    $property = taxProperty($this->user, ['notary_fees' => 3960000, 'agency_fees' => 2875000]);
    $this->depreciation->generateDefaultComponents($property);

    [$l572, $l254] = lines572and254($this->taxReturn, $this->user, 2025);

    expect($l572)->toBe($l254)->and($l572)->toBeGreaterThan(0);
});

it('matches line 572 with line 254 during a prorated first year', function () {
    $property = taxProperty($this->user, [
        'rental_start_date' => '2023-07-01',
        'notary_fees' => 1200000,
    ]);
    $this->depreciation->generateDefaultComponents($property);

    [$l572, $l254] = lines572and254($this->taxReturn, $this->user, 2023);

    expect($l572)->toBe($l254);
});

it('matches line 572 with line 254 once some plans have run out', function () {
    $property = taxProperty($this->user);
    $this->depreciation->generateDefaultComponents($property);

    Furniture::create([
        'property_id' => $property->id, 'description' => 'Canapé',
        'purchase_date' => '2023-01-01', 'amount' => 150000, 'tva_rate' => 0,
        'duration_years' => 5, 'is_dedicated' => true,
    ]);

    // 2040 : le mobilier (5 ans) et plusieurs composants (15 ans) sont éteints.
    [$l572, $l254] = lines572and254($this->taxReturn, $this->user, 2040);

    expect($l572)->toBe($l254);
});

it('accumulates the 2033-C cumul across properties instead of overwriting it', function () {
    // Deux biens aux dates de mise en location DIFFÉRENTES : c'est ce qui démasque
    // l'ancien bug. Le cumul était écrasé à chaque tour de boucle, si bien que le
    // total valait `dotation totale x années du DERNIER bien`.
    $a = taxProperty($this->user, ['name' => 'Bien A', 'rental_start_date' => '2018-01-01']);
    $b = taxProperty($this->user, [
        'name' => 'Bien B',
        'acquisition_price' => 50000000,
        'rental_start_date' => '2024-01-01',
    ]);
    $this->depreciation->generateDefaultComponents($a);
    $this->depreciation->generateDefaultComponents($b);

    $properties = Property::withoutGlobalScopes()->where('user_id', $this->user->id)->get();
    $both = $this->taxReturn->compute2033C($properties, 2025)['total_cumul'];
    $onlyA = $this->taxReturn->compute2033C($properties->where('name', 'Bien A'), 2025)['total_cumul'];
    $onlyB = $this->taxReturn->compute2033C($properties->where('name', 'Bien B'), 2025)['total_cumul'];

    expect($both)->toBe($onlyA + $onlyB)
        ->and($onlyA)->toBeGreaterThan($onlyB); // 8 exercices contre 2

});

it('reports a cumul that follows a real replay, not dotation times years', function () {
    // Mise en location au 1er juillet : la 1re année ne vaut que 184/365 d'une dotation.
    $property = taxProperty($this->user, ['rental_start_date' => '2023-07-01']);
    $this->depreciation->generateDefaultComponents($property);

    $properties = Property::withoutGlobalScopes()->where('user_id', $this->user->id)->get();
    $c = $this->taxReturn->compute2033C($properties, 2025);

    // Trois exercices, dont un au prorata : le cumul est donc strictement inférieur
    // à 3 dotations pleines, ce que l'approximation linéaire ne savait pas exprimer.
    expect($c['total_cumul'])->toBeLessThan($c['total_dotation'] * 3)
        ->and($c['total_cumul'])->toBeGreaterThan($c['total_dotation'] * 2);
});

it('counts the gross value of acquisition fees, which the table ignored entirely', function () {
    $withFees = taxProperty($this->user, ['name' => 'Avec frais', 'notary_fees' => 2500000]);
    $this->depreciation->generateDefaultComponents($withFees);

    $other = User::factory()->create();
    $withoutFees = taxProperty($other, ['name' => 'Sans frais']);
    app(DepreciationService::class)->generateDefaultComponents($withoutFees);

    $cat = fn (Property $p, string $c) => $this->taxReturn->compute2033C(collect([$p]), 2025)['categories'][$c]['brut'];

    // Les frais d'acquisition sont des immobilisations INCORPORELLES (410/500) : depuis le
    // 2026-09-05 ils ne gonflent plus les constructions, où ils étaient rangés faute de
    // catégorie pour les accueillir. L'écart entre les deux biens, par ailleurs identiques,
    // doit donc valoir leur montant sur la ligne incorporelle, et zéro sur les constructions.
    expect($cat($withFees, 'incorporelles') - $cat($withoutFees, 'incorporelles'))->toBe(2500000)
        ->and($cat($withFees, 'constructions'))->toBe($cat($withoutFees, 'constructions'));
});

it('still counts the gross value of fees once they are fully depreciated', function () {
    $property = taxProperty($this->user, ['notary_fees' => 2500000]);
    $this->depreciation->generateDefaultComponents($property);

    // 2060 : les 25 ans des frais sont révolus, mais le gros œuvre (50 ans) court encore.
    $c = $this->taxReturn->compute2033C(collect([$property]), 2060);

    // La valeur brute des frais reste au bilan, sur sa propre ligne incorporelle, tandis que
    // les constructions gardent le seul bâti (gros œuvre 50 % + toiture 10 % de 200 000 €)…
    expect($c['categories']['incorporelles']['brut'])->toBe(2500000)
        ->and($c['categories']['constructions']['brut'])->toBe(12000000)
        // … alors qu'ils ne dotent plus rien : seul le gros œuvre alimente l'exercice.
        ->and($c['total_dotation'])->toBe(200000)
        // et leur cumul est complet, plafonné à leur valeur brute.
        ->and($c['categories']['incorporelles']['cumul'])->toBe(2500000);
});

it('carries the land in the total gross assets, where the table ignored it', function () {
    // Le terrain ne sort d'aucune ligne du détail d'amortissement — il ne s'amortit pas —, et
    // la ligne 490 l'oubliait donc : 32 625 € manquants sur 245 643 € pour la liasse réelle
    // rejouée le 2026-09-05. Ici : 250 000 € à 20 % de terrain, soit 50 000 €.
    $property = taxProperty($this->user);
    $this->depreciation->generateDefaultComponents($property);

    $c = $this->taxReturn->compute2033C(collect([$property]), 2025);

    expect($c['categories']['terrains']['brut'])->toBe(5000000)
        // Le terrain ne dote rien et n'a pas de ligne d'amortissement au Cerfa.
        ->and($c['categories']['terrains']['dotation'])->toBe(0)
        ->and($c['categories']['terrains']['lines']['amort'])->toBeNull()
        // Et le total brut vaut bien la valeur de référence entière.
        ->and($c['total_brut'])->toBe(25000000);
});

it('matches line 572 with line 254 on a primary residence with a quota share', function () {
    // La configuration réelle observée en production le 2026-09-03 : 35 m² loués sur 120,
    // résidence principale, gros frais d'acquisition. Elle affichait « ⚠ Écart » de 595 €.
    $property = taxProperty($this->user, [
        'total_area' => 120,
        'rented_area' => 35,
        'is_primary_residence' => true,
        'notary_fees' => 3960000,
        'agency_fees' => 2875000,
        'rental_start_date' => '2025-01-01',
    ]);
    $this->depreciation->generateDefaultComponents($property);

    [$l572, $l254] = lines572and254($this->taxReturn, $this->user, 2025);

    expect($l572)->toBe($l254)->and($l572)->toBeGreaterThan(0);
});
