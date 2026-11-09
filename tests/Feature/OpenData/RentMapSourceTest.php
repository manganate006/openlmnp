<?php

use App\Services\OpenData\RentMapSource;

/**
 * Lecture du CSV de la « Carte des loyers » (ANIL) — aucune E/S réseau, aucune base.
 *
 * Les fixtures (`tests/Fixtures/opendata/rent-map/*.csv`) sont un extrait RÉEL du millésime
 * 2025 (3 lignes par fichier, têtées le 2026-09-30 sur les 4 CSV publiés par data.gouv.fr) :
 * Bordeaux (33063, niveau « commune », bon échantillon), Paris 1er (75101, arrondissement —
 * la seule maille que le jeu publie pour Paris), et La Haute-Beaume (05066, niveau « maille »,
 * zéro observation communale — le cas qui doit déclencher la prudence).
 */
function rentMapFixture(string $name): string
{
    return base_path("tests/Fixtures/opendata/rent-map/{$name}.csv");
}

it('reads a commune-level row and converts euros per m² to cents', function () {
    $rows = iterator_to_array(RentMapSource::rows(rentMapFixture('apartment')));
    $bordeaux = collect($rows)->firstWhere('insee_code', '33063');

    expect($bordeaux)->not->toBeNull()
        ->and($bordeaux['per_m2_cents'])->toBe(1599)
        ->and($bordeaux['low_per_m2_cents'])->toBe(1227)
        ->and($bordeaux['high_per_m2_cents'])->toBe(2084)
        ->and($bordeaux['observations'])->toBe(84641)
        ->and($bordeaux['mesh_observations'])->toBe(96006)
        ->and($bordeaux['prediction_level'])->toBe('commune')
        ->and($bordeaux['r2'])->toBe(0.821);
});

it('reads a Paris arrondissement, never the city-wide code', function () {
    $rows = collect(iterator_to_array(RentMapSource::rows(rentMapFixture('apartment'))));

    expect($rows->firstWhere('insee_code', '75101'))->not->toBeNull()
        // Le code de la commune globale (75056) n'existe tout simplement pas dans la
        // source : ce n'est pas un oubli de la fixture, c'est le format réel.
        ->and($rows->firstWhere('insee_code', '75056'))->toBeNull();
});

it('flags a mesh-level row with too few observations as needing caution', function () {
    $rows = collect(iterator_to_array(RentMapSource::rows(rentMapFixture('apartment'))));
    $ruralRow = $rows->firstWhere('insee_code', '05066');

    expect($ruralRow['observations'])->toBe(0)
        ->and($ruralRow['prediction_level'])->toBe('maille')
        ->and(RentMapSource::callsForCaution($ruralRow))->toBeTrue();

    $bordeauxRow = $rows->firstWhere('insee_code', '33063');
    expect(RentMapSource::callsForCaution($bordeauxRow))->toBeFalse();
});

it('throws when a required column is missing, instead of silently misreading the format', function () {
    $broken = sys_get_temp_dir().'/rent-map-broken-'.uniqid().'.csv';
    file_put_contents($broken, "\"id_zone\";\"INSEE_C\";\"loypredm2\"\n\"1\";\"33063\";\"15,99\"\n");

    RentMapSource::rows($broken)->valid();
})->throws(RuntimeException::class, 'absente du CSV Carte des loyers');

it('recognizes a valid header without parsing the whole file', function () {
    expect(RentMapSource::headerMatches(rentMapFixture('apartment')))->toBeTrue();
});

it('rejects a header missing a required column, the check RefreshRentMap uses before replacing a vintage', function () {
    $broken = sys_get_temp_dir().'/rent-map-header-'.uniqid().'.csv';
    file_put_contents($broken, "\"id_zone\";\"INSEE_C\";\"loyer_m2\"\n\"1\";\"33063\";\"15,99\"\n");

    expect(RentMapSource::headerMatches($broken))->toBeFalse();
});

it('maps property types the same way DVF does: only house gets its own file', function () {
    expect(RentMapSource::typeForPropertyType('house'))->toBe(RentMapSource::TYPE_HOUSE)
        ->and(RentMapSource::typeForPropertyType('apartment'))->toBe(RentMapSource::TYPE_APARTMENT)
        ->and(RentMapSource::typeForPropertyType('room'))->toBe(RentMapSource::TYPE_APARTMENT)
        ->and(RentMapSource::typeForPropertyType('studio'))->toBe(RentMapSource::TYPE_APARTMENT)
        ->and(RentMapSource::typeForPropertyType('other'))->toBe(RentMapSource::TYPE_APARTMENT);
});
