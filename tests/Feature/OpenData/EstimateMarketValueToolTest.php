<?php

use App\Models\Property;
use App\Models\User;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

/**
 * Outil MCP `estimate_market_value`.
 *
 * ⚠️ C'est le **seul outil MCP qui interroge un service externe**. Deux propriétés à ne pas
 * perdre : il est absent de l'allowlist du token démo public (un token partagé ne doit pas
 * pouvoir faire télécharger notre serveur), et il refuse de trancher entre deux communes
 * homonymes — l'écart entre communes voisines se compte en dizaines de milliers d'euros de
 * base amortissable, un choix au hasard serait pire qu'une question.
 */
beforeEach(function () {
    config(['mcp.enabled' => true]);
    // Tout le dossier, pas seulement years.json : le cache des relevés par commune est un
    // FICHIER, et un vrai relevé laissé par une exécution manuelle ferait passer le test sur
    // des données réelles au lieu du faux réseau.
    File::deleteDirectory(storage_path('app/private/dvf'));

    $this->user = User::factory()->create(['mcp_enabled' => true]);
    $this->token = $this->user->createToken('test-token');
});

function dvfMcpFake(?array $communes = null, int $sales = 5): void
{
    $header = 'id_mutation,date_mutation,nature_mutation,valeur_fonciere,code_postal,code_commune,nom_commune,code_departement,id_parcelle,nombre_lots,code_type_local,type_local,surface_reelle_bati,nombre_pieces_principales,surface_terrain';
    $lines = [$header];
    foreach (array_slice([200000, 220000, 250000, 260000, 300000], 0, $sales) as $i => $price) {
        $lines[] = "A{$i},2025-03-14,Vente,{$price}.00,33000,33063,Bordeaux,33,33063000A000{$i},1,2,Appartement,50,3,";
    }

    Http::fake([
        'geo.api.gouv.fr/*' => Http::response($communes ?? [[
            'nom' => 'Bordeaux', 'code' => '33063', 'codesPostaux' => ['33000'],
            'departement' => ['nom' => 'Gironde'],
        ]]),
        'files.data.gouv.fr/*' => Http::response(implode("\n", $lines)."\n"),
    ]);
}

function callDvfTool(array $args): array
{
    return test()->withToken(test()->token->plainTextToken)->postJson('/mcp', [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'tools/call',
        'params' => ['name' => 'estimate_market_value', 'arguments' => $args],
    ])->json();
}

it('estimates from a commune, a type and an area', function () {
    dvfMcpFake();

    $payload = json_decode(callDvfTool([
        'commune' => '33000', 'property_type' => 'apartment', 'area_m2' => 40, 'year' => 2025,
    ])['result']['content'][0]['text'], true);

    // Médiane 5 000 €/m² × 40 m².
    expect($payload['estimated'])->toBeTrue()
        ->and($payload['market_value_eur'])->toBe('200000.00')
        ->and($payload['price_per_m2_eur'])->toBe('5000.00')
        ->and($payload['sample_size'])->toBe(5)
        ->and($payload['insee_code'])->toBe('33063')
        // L'attribution voyage avec la donnée : la Licence Ouverte l'impose, et un assistant
        // qui reprend le chiffre doit pouvoir citer sa source.
        ->and($payload['source'])->toContain('Licence Ouverte 2.0')
        ->and($payload['disclaimer'])->toContain("n'est pas une expertise");
});

it('takes the characteristics from an existing property', function () {
    dvfMcpFake();
    Property::forceCreate([
        'user_id' => $this->user->id, 'name' => 'Bien', 'address' => '1 rue', 'city' => 'Bordeaux',
        'postal_code' => '33000', 'type' => 'apartment', 'total_area' => 40, 'rented_area' => 40,
        'acquisition_date' => '2020-01-01', 'acquisition_price' => 20000000, 'notary_fees' => 0,
        'land_percentage' => 15, 'rental_start_date' => '2023-01-01', 'rental_type' => 'seasonal',
        'tva_regime' => 'exempt', 'is_primary_residence' => false,
    ]);

    $payload = json_decode(callDvfTool(['property_id' => 1, 'year' => 2025])['result']['content'][0]['text'], true);

    expect($payload['estimated'])->toBeTrue()
        ->and($payload['area_m2'])->toBe(40)
        ->and($payload['market_value_eur'])->toBe('200000.00');
});

it('refuses to choose between two matching communes', function () {
    dvfMcpFake([
        ['nom' => 'Sainte-Marie', 'code' => '97418', 'codesPostaux' => ['97438'], 'departement' => ['nom' => 'La Réunion']],
        ['nom' => 'Sainte-Marie', 'code' => '35306', 'codesPostaux' => ['35600'], 'departement' => ['nom' => 'Ille-et-Vilaine']],
    ]);

    $payload = json_decode(callDvfTool([
        'commune' => 'Sainte-Marie', 'property_type' => 'house', 'area_m2' => 90,
    ])['result']['content'][0]['text'], true);

    expect($payload['ambiguous'])->toBeTrue()
        ->and($payload['candidates'])->toHaveCount(2)
        ->and(array_column($payload['candidates'], 'insee_code'))->toContain('97418', '35306');
});

it('proposes nothing when the sample is too thin', function () {
    dvfMcpFake(sales: 1);

    $payload = json_decode(callDvfTool([
        'commune' => '33000', 'property_type' => 'apartment', 'area_m2' => 40, 'year' => 2025,
    ])['result']['content'][0]['text'], true);

    expect($payload['estimated'])->toBeFalse()
        ->and($payload['reason'])->toContain('Pas assez de ventes comparables')
        ->and($payload)->not->toHaveKey('market_value_eur');
});

it('is not exposed to the public demo token', function () {
    // Lecture seule côté comptabilité, mais c'est le seul outil qui fait sortir une requête :
    // un token public et partagé ne doit pas pouvoir s'en servir.
    expect(config('mcp.demo.tools'))->not->toContain('estimate_market_value');
});

// === Garde-fou : un outil non enregistré est invisible, en silence ===

it('registers every tool present in app/Mcp/Tools', function () {
    $onDisk = collect(File::files(app_path('Mcp/Tools')))
        ->map(fn ($f) => $f->getFilenameWithoutExtension())
        ->sort()->values();

    $registered = collect(file(app_path('Mcp/OpenLmnpServer.php')))
        ->filter(fn ($line) => str_contains($line, 'Tools\\'))
        ->map(fn ($line) => trim(str_replace(['Tools\\', '::class', ','], '', $line)))
        ->sort()->values();

    expect($registered->all())->toBe($onDisk->all());
});
