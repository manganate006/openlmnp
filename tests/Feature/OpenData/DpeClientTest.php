<?php

use App\Services\OpenData\DpeClient;
use App\Services\OpenData\DpeUnavailable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;

/**
 * `DpeClient` — recherche du DPE d'un bien dans la base de l'ADEME (`dpe03existant`).
 *
 * ⚠️ `Http::fake()` CUMULE ses stubs : le premier enregistré qui matche répond. Chaque
 * scénario refait donc son propre `Http::fake()` complet plutôt que d'empiler des cas.
 */
beforeEach(function () {
    Cache::flush();
    RateLimiter::clear('dpe:guest');
});

/** @param array<int, array<string, mixed>> $rows */
function fakeAdemeLines(array $rows): void
{
    Http::fake([
        'data.geopf.fr/geocodage/*' => Http::response([
            'features' => [[
                'properties' => ['id' => '33063_0221_00021'],
            ]],
        ]),
        'data.ademe.fr/*' => Http::response(['results' => $rows]),
    ]);
}

function dpeRow(array $overrides = []): array
{
    return array_merge([
        'numero_dpe' => '2521E1234567A',
        'etiquette_dpe' => 'D',
        'date_etablissement_dpe' => '2025-01-15',
        'date_fin_validite_dpe' => '2035-01-15',
        'numero_dpe_remplace' => null,
        'type_batiment' => 'appartement',
        'numero_etage_appartement' => 3,
        'complement_adresse_logement' => null,
        'surface_habitable_logement' => 45,
    ], $overrides);
}

it('returns an empty list when nothing is found at the address', function () {
    fakeAdemeLines([]);

    $rows = app(DpeClient::class)->forAddress('1 rue de la République', '69003', 'Lyon');

    expect($rows)->toBe([]);
});

it('finds a single DPE at the address', function () {
    fakeAdemeLines([dpeRow()]);

    $rows = app(DpeClient::class)->forAddress('1 rue de la République', '69003', 'Lyon', '69383');

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['numero_dpe'])->toBe('2521E1234567A')
        ->and($rows[0]['etiquette_dpe'])->toBe('D')
        ->and($rows[0]['expired'])->toBeFalse();

    Http::assertSent(fn ($request) => str_contains($request->url(), 'citycode=69383'));
});

it('lists every DPE of a building without picking one silently', function () {
    fakeAdemeLines([
        dpeRow(['numero_dpe' => 'A', 'date_etablissement_dpe' => '2025-06-01']),
        dpeRow(['numero_dpe' => 'B', 'date_etablissement_dpe' => '2022-03-01', 'numero_etage_appartement' => 1]),
    ]);

    $rows = app(DpeClient::class)->forAddress('12 rue de la République', '69003', 'Lyon');

    expect($rows)->toHaveCount(2)
        ->and(array_column($rows, 'numero_dpe'))->toBe(['A', 'B']);
});

it('excludes a DPE replaced by a more recent one', function () {
    fakeAdemeLines([
        dpeRow(['numero_dpe' => 'NEW', 'date_etablissement_dpe' => '2026-01-01', 'numero_dpe_remplace' => 'OLD']),
        dpeRow(['numero_dpe' => 'OLD', 'date_etablissement_dpe' => '2021-08-01']),
    ]);

    $rows = app(DpeClient::class)->forAddress('12 rue de la République', '69003', 'Lyon');

    expect(array_column($rows, 'numero_dpe'))->toBe(['NEW']);
});

it('flags an expired DPE', function () {
    fakeAdemeLines([dpeRow(['date_fin_validite_dpe' => '2020-01-01'])]);

    $rows = app(DpeClient::class)->forAddress('1 rue de la République', '69003', 'Lyon');

    expect($rows[0]['expired'])->toBeTrue();
});

it('finds a DPE by its number', function () {
    Http::fake([
        'data.ademe.fr/*' => Http::response(['results' => [dpeRow(['numero_dpe' => '2521E9999999Z'])]]),
    ]);

    $row = app(DpeClient::class)->byNumber('2521E9999999Z');

    expect($row['numero_dpe'])->toBe('2521E9999999Z');
    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'geocodage'));
});

it('returns null when the number does not exist', function () {
    Http::fake(['data.ademe.fr/*' => Http::response(['results' => []])]);

    expect(app(DpeClient::class)->byNumber('INCONNU'))->toBeNull();
});

it('throws when the ADEME dataset is unavailable', function () {
    Http::fake([
        'data.geopf.fr/geocodage/*' => Http::response(['features' => [['properties' => ['id' => 'x']]]]),
        'data.ademe.fr/*' => Http::response('', 500),
    ]);

    expect(fn () => app(DpeClient::class)->forAddress('1 rue de la République', '69003', 'Lyon'))
        ->toThrow(DpeUnavailable::class);
});

it('throws when the geocoder is unavailable', function () {
    Http::fake(['data.geopf.fr/geocodage/*' => Http::response('', 500)]);

    expect(fn () => app(DpeClient::class)->forAddress('1 rue de la République', '69003', 'Lyon'))
        ->toThrow(DpeUnavailable::class);

    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'ademe'));
});

it('returns no BAN id and no result when the address does not geocode', function () {
    Http::fake(['data.geopf.fr/geocodage/*' => Http::response(['features' => []])]);

    $rows = app(DpeClient::class)->forAddress('adresse introuvable', '00000', 'Nulle-Part');

    expect($rows)->toBe([]);
    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'ademe'));
});

it('can be switched off entirely, without any outbound call', function () {
    config(['opendata.dpe.enabled' => false]);
    Http::fake();

    expect(fn () => app(DpeClient::class)->forAddress('1 rue de la République', '69003', 'Lyon'))
        ->toThrow(DpeUnavailable::class, 'désactivée');

    Http::assertNothingSent();
});

it('throttles repeated lookups', function () {
    config(['opendata.dpe.rate_limit' => 1]);
    fakeAdemeLines([dpeRow()]);

    app(DpeClient::class)->forAddress('1 rue de la République', '69003', 'Lyon');

    expect(fn () => app(DpeClient::class)->forAddress('1 rue de la République', '69003', 'Lyon'))
        ->toThrow(DpeUnavailable::class, 'Trop de recherches');
});

// === Garde-fou : allowlist de docker-entrypoint.sh ===
// Couvert de façon générique par MarketValueEstimatorTest (toute variable env() de
// config/opendata.php), mais ancré ici explicitement pour les variables DPE_* : une
// variable oubliée dans cette boucle est ignorée en production, `-e` ou pas.

it('propagates every DPE variable through the docker entrypoint allowlist', function () {
    $entrypoint = file_get_contents(base_path('docker-entrypoint.sh'));

    foreach (['DPE_ENABLED', 'DPE_BASE_URL', 'DPE_GEOCODE_URL', 'DPE_TIMEOUT', 'DPE_CACHE_MINUTES', 'DPE_RATE_LIMIT'] as $variable) {
        expect($entrypoint)->toContain($variable);
    }
});
