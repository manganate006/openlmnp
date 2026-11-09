<?php

use App\Services\OpenData\RentMapDiscovery;
use Illuminate\Support\Facades\Http;

/**
 * Découverte automatique des 4 URLs d'un millésime sur l'API data.gouv.fr
 * (`GET /api/1/datasets/{slug}/`) — aucune base, aucune E/S fichier.
 */
function rentMapDiscoveryApiUrl(int $vintage): string
{
    return rtrim((string) config('opendata.rent_map.api_base_url'), '/').'/'.RentMapDiscovery::slug($vintage).'/';
}

/** @param  array<int, array{url: string, title: string}>  $resources */
function fakeRentMapDataset(int $vintage, array $resources, int $status = 200): void
{
    Http::fake([
        rentMapDiscoveryApiUrl($vintage) => Http::response(['resources' => $resources], $status),
    ]);
}

/** Les 4 fichiers réels, titres volontairement au pluriel pour vérifier qu'ils sont ignorés. */
function completeRentMapResources(string $timestamp = '20261211-100000'): array
{
    return [
        ['url' => "https://static.data.gouv.fr/resources/carte-des-loyers/{$timestamp}/pred-app-mef-dhup.csv", 'title' => 'Loyers appartements'],
        ['url' => "https://static.data.gouv.fr/resources/carte-des-loyers/{$timestamp}/pred-app12-mef-dhup.csv", 'title' => 'Loyers appartements 1-2 pièces'],
        ['url' => "https://static.data.gouv.fr/resources/carte-des-loyers/{$timestamp}/pred-app3-mef-dhup.csv", 'title' => 'Loyers appartements 3 pièces et plus'],
        ['url' => "https://static.data.gouv.fr/resources/carte-des-loyers/{$timestamp}/pred-mai-mef-dhup.csv", 'title' => 'Loyers maisons'],
    ];
}

it('builds the slug and the dataset page URL the same way every year', function () {
    expect(RentMapDiscovery::slug(2026))->toBe('carte-des-loyers-indicateurs-de-loyers-dannonce-par-commune-en-2026')
        ->and(RentMapDiscovery::datasetPageUrl(2026))->toBe('https://www.data.gouv.fr/datasets/carte-des-loyers-indicateurs-de-loyers-dannonce-par-commune-en-2026');
});

it('discovers the four URLs by matching the end of the filename, never the title', function () {
    fakeRentMapDataset(2026, completeRentMapResources());

    $urls = RentMapDiscovery::discoverUrls(2026);

    expect($urls)->not->toBeNull()
        ->and($urls)->toHaveCount(4)
        ->and($urls['apartment'])->toEndWith('pred-app-mef-dhup.csv')
        ->and($urls['apartment_1_2'])->toEndWith('pred-app12-mef-dhup.csv')
        ->and($urls['apartment_3_plus'])->toEndWith('pred-app3-mef-dhup.csv')
        ->and($urls['house'])->toEndWith('pred-mai-mef-dhup.csv');
});

it('returns null when the dataset does not exist yet (404)', function () {
    fakeRentMapDataset(2026, [], 404);

    expect(RentMapDiscovery::discoverUrls(2026))->toBeNull();
});

it('returns null when fewer than four recognized files are published', function () {
    $resources = completeRentMapResources();
    array_pop($resources); // Il ne reste plus que 3 fichiers reconnus (maison manquant).

    fakeRentMapDataset(2026, $resources);

    expect(RentMapDiscovery::discoverUrls(2026))->toBeNull();
});

it('returns null when the API is unreachable', function () {
    Http::fake([
        rentMapDiscoveryApiUrl(2026) => fn () => throw new \Illuminate\Http\Client\ConnectionException('Connection refused'),
    ]);

    expect(RentMapDiscovery::discoverUrls(2026))->toBeNull();
});
