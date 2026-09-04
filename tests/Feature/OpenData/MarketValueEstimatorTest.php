<?php

use App\Services\OpenData\DvfUnavailable;
use App\Services\OpenData\MarketValueEstimator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Estimation de la valeur vénale — aucun appel réseau réel : `Http::fake()` couvre les
 * fichiers DVF et l'API Découpage administratif.
 */
beforeEach(function () {
    Cache::flush();
    RateLimiter::clear('dvf:guest');
    File::deleteDirectory(storage_path('app/private/dvf'));
});

function dvfSales(int $count = 5): string
{
    $header = 'id_mutation,date_mutation,nature_mutation,valeur_fonciere,code_postal,code_commune,nom_commune,code_departement,id_parcelle,nombre_lots,code_type_local,type_local,surface_reelle_bati,nombre_pieces_principales,surface_terrain';
    $lines = [$header];

    // 5 ventes de 50 m² de 200 000 à 300 000 € → médiane 5 000 €/m².
    foreach ([200000, 220000, 250000, 260000, 300000] as $i => $price) {
        if ($i >= $count) {
            break;
        }
        $lines[] = "A{$i},2025-03-14,Vente,{$price}.00,33000,33063,Bordeaux,33,33063000A000{$i},1,2,Appartement,50,3,";
    }

    return implode("\n", $lines)."\n";
}

function fakeDvf(array $extra = []): void
{
    Http::fake($extra + [
        'geo.api.gouv.fr/*' => Http::response([[
            'nom' => 'Bordeaux',
            'code' => '33063',
            'codesPostaux' => ['33000'],
            'departement' => ['nom' => 'Gironde'],
        ]]),
        'files.data.gouv.fr/*' => Http::response(dvfSales()),
    ]);
}

it('estimates a market value from the commune sales', function () {
    fakeDvf();

    $result = app(MarketValueEstimator::class)->estimate('33063', 'apartment', 40, 2025);

    expect($result['enough'])->toBeTrue()
        ->and($result['price_per_m2_cents'])->toBe(500_000)
        ->and($result['value_cents'])->toBe(20_000_000)   // 5 000 € × 40 m²
        ->and($result['sample_size'])->toBe(5);
});

it('compares a studio and a room to apartments, not to houses', function () {
    fakeDvf();

    foreach (['studio', 'room', 'other'] as $type) {
        expect(app(MarketValueEstimator::class)->estimate('33063', $type, 40, 2025)['type'])
            ->toBe('appartement');
    }

    expect(app(MarketValueEstimator::class)->estimate('33063', 'house', 40, 2025)['type'])
        ->toBe('maison');
});

it('refuses to publish a median under the minimum sample', function () {
    fakeDvf(['files.data.gouv.fr/*' => Http::response(dvfSales(1))]);

    $result = app(MarketValueEstimator::class)->estimate('33063', 'apartment', 40, 2025);

    // Une vente par millésime : l'élargissement en cumule trois, ce qui reste sous le seuil.
    expect($result['enough'])->toBeFalse()
        ->and($result['years'])->toHaveCount(3)
        ->and($result['sample_size'])->toBe(3)
        ->and($result['minimum'])->toBe(5);
});

it('widens to neighbouring vintages before giving up', function () {
    // Chaque millésime n'apporte que deux ventes : il en faut trois pour atteindre le seuil.
    config(['opendata.dvf.min_sample' => 5]);
    fakeDvf(['files.data.gouv.fr/*' => Http::response(dvfSales(2))]);

    $result = app(MarketValueEstimator::class)->estimate('33063', 'apartment', 40, 2025);

    expect($result['years'])->toHaveCount(3)
        ->and($result['sample_size'])->toBe(6)
        ->and($result['enough'])->toBeTrue();
});

it('says explicitly that DVF does not cover Alsace-Moselle or Mayotte', function () {
    fakeDvf();

    // 67482 = Strasbourg. Ce n'est pas une panne, c'est une limite de la source.
    expect(fn () => app(MarketValueEstimator::class)->estimate('67482', 'apartment', 40, 2025))
        ->toThrow(DvfUnavailable::class, 'Alsace-Moselle');

    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'files.data.gouv.fr'));
});

it('keeps working offline once a commune has been consulted', function () {
    fakeDvf();
    app(MarketValueEstimator::class)->estimate('33063', 'apartment', 40, 2025);

    // Le cache est un FICHIER : il survit à `cache:clear`, contrairement au cache Laravel.
    Cache::flush();
    Http::fake(['files.data.gouv.fr/*' => Http::response('', 500)]);

    $result = app(MarketValueEstimator::class)->estimate('33063', 'apartment', 40, 2025);

    expect($result['price_per_m2_cents'])->toBe(500_000)
        ->and($result['enough'])->toBeTrue();
});

it('can be switched off entirely, without any outbound call', function () {
    config(['opendata.dvf.enabled' => false]);
    Http::fake();

    expect(fn () => app(MarketValueEstimator::class)->estimate('33063', 'apartment', 40, 2025))
        ->toThrow(DvfUnavailable::class, 'désactivée');

    Http::assertNothingSent();
});

it('throttles repeated lookups', function () {
    config(['opendata.dvf.rate_limit' => 1]);
    fakeDvf();

    app(MarketValueEstimator::class)->estimate('33063', 'apartment', 40, 2025);

    expect(fn () => app(MarketValueEstimator::class)->estimate('33063', 'apartment', 40, 2025))
        ->toThrow(DvfUnavailable::class, 'Trop de recherches');
});

it('expands Paris, Lyon and Marseille into their boroughs', function () {
    // DVF ne publie que les arrondissements, et l'API Géo ne connaît que la commune :
    // sans expansion, « Lyon » n'aurait aucun fichier.
    Http::fake([
        'geo.api.gouv.fr/*' => Http::response([[
            'nom' => 'Lyon', 'code' => '69123', 'codesPostaux' => ['69001'], 'departement' => ['nom' => 'Rhône'],
        ]]),
    ]);

    $communes = app(MarketValueEstimator::class)->communes('Lyon');

    expect($communes)->toHaveCount(9)
        ->and(array_column($communes, 'code'))->toContain('69381', '69389')
        ->and($communes[0]['nom'])->toBe('Lyon 1er');
});

// === Garde-fou : allowlist de docker-entrypoint.sh ===
// L'entrypoint ne recopie vers .env qu'une liste fixe de variables : toute variable
// oubliée est silencieusement ignorée en production, `-e` ou pas.

it('propagates every DVF variable through the docker entrypoint allowlist', function () {
    $entrypoint = file_get_contents(base_path('docker-entrypoint.sh'));
    $config = file_get_contents(config_path('opendata.php'));

    preg_match_all("/env\('([A-Z0-9_]+)'/", $config, $matches);

    expect($matches[1])->not->toBeEmpty();

    foreach (array_unique($matches[1]) as $variable) {
        expect($entrypoint)->toContain($variable);
    }
});
