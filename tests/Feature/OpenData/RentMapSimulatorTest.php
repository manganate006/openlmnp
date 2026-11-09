<?php

use App\Filament\Pages\Simulator;
use App\Models\Property;
use App\Models\RentMapIndicator;
use App\Models\User;
use App\Services\DepreciationService;
use App\Services\OpenData\RentMapSource;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

/**
 * Repère de loyer du marché dans le simulateur (« Carte des loyers », ANIL) et dans l'outil
 * MCP `get_simulation`.
 *
 * ⚠️ Le test qui compte ici n'est pas « la valeur est juste » (couvert par `RentMapSourceTest`
 * sur le parsing), c'est « aucun des trois états — repère présent, commune inconnue, commune
 * non couverte / table vide — ne fait planter l'écran, et aucun n'appelle le réseau ». Voir
 * `App\Models\RentMapIndicator::forProperty()` : c'est une lecture base, jamais HTTP.
 */
beforeEach(function () {
    $this->user = User::factory()->create(['mcp_enabled' => true]);
});

function makeRentMapProperty(array $overrides = []): Property
{
    $property = Property::forceCreate(array_merge([
        'user_id' => test()->user->id,
        'name' => 'Appartement Bordeaux',
        'address' => '1 rue Test',
        'city' => 'Bordeaux',
        'postal_code' => '33000',
        'insee_code' => '33063',
        'type' => 'apartment',
        'total_area' => 50,
        'rented_area' => 50,
        'acquisition_date' => '2020-01-01',
        'acquisition_price' => 20_000_000,
        'notary_fees' => 0,
        'land_percentage' => 15,
        'rental_start_date' => '2021-01-01',
        'rental_type' => 'seasonal',
        'is_primary_residence' => false,
    ], $overrides));

    app(DepreciationService::class)->generateDefaultComponents($property);

    return $property;
}

it('shows the rent benchmark when the commune is known and covered', function () {
    $property = makeRentMapProperty();

    RentMapIndicator::create([
        'insee_code' => '33063',
        'property_type' => 'apartment',
        'vintage' => 2025,
        'per_m2_cents' => 1_550,
        'low_per_m2_cents' => 1_200,
        'high_per_m2_cents' => 2_000,
        'observations' => 500,
        'mesh_observations' => 500,
        'prediction_level' => 'commune',
        'r2' => 0.8,
    ]);

    $this->actingAs($this->user);
    $component = Livewire::test(Simulator::class);

    $benchmarks = $component->instance()->rentBenchmarks();

    expect($benchmarks)->toHaveCount(1);
    $benchmark = array_values($benchmarks)[0];

    expect($benchmark['status'])->toBe('ok')
        ->and($benchmark['per_m2'])->toBe('15,5')
        ->and($benchmark['low_per_m2'])->toBe('12,0')
        ->and($benchmark['high_per_m2'])->toBe('20,0')
        // 1 550 c/m² × 50 m² = 77 500 c = 775 €.
        ->and($benchmark['estimated_monthly'])->toBe('775')
        ->and($benchmark['caution'])->toBeFalse();

    $component->assertOk()
        ->assertSee('Repère de loyer du marché')
        ->assertSee('NON meublée')
        ->assertSee('775');

    // Jamais injecté dans le calcul fiscal : le résultat de la simulation n'a pas bougé
    // du fait d'avoir seedé un repère de loyer.
    expect($component->instance()->simulationResults['empty'])->toBeFalse();
});

it('shows the vintage actually stored in the database, not the hard-coded fallback constant', function () {
    makeRentMapProperty();

    // Millésime volontairement DIFFÉRENT de RentMapSource::VINTAGE, pour prouver que
    // l'affichage lit bien la base et non la constante de repli.
    $realVintage = RentMapSource::VINTAGE + 1;
    RentMapIndicator::create([
        'insee_code' => '33063',
        'property_type' => 'apartment',
        'vintage' => $realVintage,
        'per_m2_cents' => 1_550,
        'low_per_m2_cents' => 1_200,
        'high_per_m2_cents' => 2_000,
        'observations' => 500,
        'mesh_observations' => 500,
        'prediction_level' => 'commune',
        'r2' => 0.8,
    ]);

    $this->actingAs($this->user);
    $component = Livewire::test(Simulator::class);

    expect($component->instance()->rentMapVintage())->toBe($realVintage)
        ->and($component->instance()->rentMapDatasetUrl())->toBe(
            "https://www.data.gouv.fr/datasets/carte-des-loyers-indicateurs-de-loyers-dannonce-par-commune-en-{$realVintage}"
        );

    $component->assertOk()
        ->assertSee("millésime {$realVintage}")
        ->assertDontSee('millésime '.RentMapSource::VINTAGE);
});

it('shows no vintage and no dataset link when the table is empty', function () {
    makeRentMapProperty();
    expect(RentMapIndicator::count())->toBe(0);

    $this->actingAs($this->user);
    $component = Livewire::test(Simulator::class);

    expect($component->instance()->rentMapVintage())->toBeNull()
        ->and($component->instance()->rentMapDatasetUrl())->toBeNull();

    $component->assertOk()->assertDontSee('data.gouv.fr/datasets/carte-des-loyers');
});

it('flags a covered but fragile commune as needing caution', function () {
    makeRentMapProperty();

    RentMapIndicator::create([
        'insee_code' => '33063',
        'property_type' => 'apartment',
        'vintage' => 2025,
        'per_m2_cents' => 1_000,
        'low_per_m2_cents' => 500,
        'high_per_m2_cents' => 1_500,
        'observations' => 5,
        'mesh_observations' => 400,
        'prediction_level' => 'maille',
        'r2' => 0.3,
    ]);

    $this->actingAs($this->user);
    $benchmark = array_values(Livewire::test(Simulator::class)->instance()->rentBenchmarks())[0];

    expect($benchmark['caution'])->toBeTrue();
});

it('shows a clear message when the property has no known commune', function () {
    makeRentMapProperty(['insee_code' => null]);

    $this->actingAs($this->user);
    $component = Livewire::test(Simulator::class);

    $benchmark = array_values($component->instance()->rentBenchmarks())[0];
    expect($benchmark['status'])->toBe('unknown_commune');

    $component->assertOk()->assertSee('Commune non renseignée');
});

it('shows no crash and a clear message when the rent map table is empty', function () {
    // Commune connue, mais AUCUNE ligne `rent_map_indicators` — l'instance n'a jamais
    // lancé `openlmnp:refresh-rent-map`, ou n'a pas de réseau.
    makeRentMapProperty();
    expect(RentMapIndicator::count())->toBe(0);

    $this->actingAs($this->user);
    $component = Livewire::test(Simulator::class);

    $benchmark = array_values($component->instance()->rentBenchmarks())[0];
    expect($benchmark['status'])->toBe('not_covered');

    $component->assertOk()->assertSee('Aucun repère disponible');
});

it('never makes an HTTP request while rendering the simulator', function () {
    $property = makeRentMapProperty();
    RentMapIndicator::create([
        'insee_code' => '33063',
        'property_type' => 'apartment',
        'vintage' => 2025,
        'per_m2_cents' => 1_550,
        'low_per_m2_cents' => 1_200,
        'high_per_m2_cents' => 2_000,
        'observations' => 500,
        'mesh_observations' => 500,
        'prediction_level' => 'commune',
        'r2' => 0.8,
    ]);

    Http::fake();
    $this->actingAs($this->user);
    Livewire::test(Simulator::class)->assertOk();

    Http::assertNothingSent();
});

it('hides the benchmark entirely when RENT_MAP_ENABLED is false', function () {
    config(['opendata.rent_map.enabled' => false]);
    makeRentMapProperty();

    $this->actingAs($this->user);
    $component = Livewire::test(Simulator::class);

    expect($component->instance()->rentBenchmarks())->toBe([]);
    $component->assertOk()->assertDontSee('Repère de loyer du marché');
});

// === MCP get_simulation ===

it('includes the rent benchmark in the get_simulation MCP tool, as a separate optional field', function () {
    config(['mcp.enabled' => true]);
    $property = makeRentMapProperty();
    RentMapIndicator::create([
        'insee_code' => '33063',
        'property_type' => 'apartment',
        'vintage' => 2025,
        'per_m2_cents' => 1_550,
        'low_per_m2_cents' => 1_200,
        'high_per_m2_cents' => 2_000,
        'observations' => 500,
        'mesh_observations' => 500,
        'prediction_level' => 'commune',
        'r2' => 0.8,
    ]);

    $token = $this->user->createToken('test-token');

    $response = $this->withToken($token->plainTextToken)->postJson('/mcp', [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'tools/call',
        'params' => ['name' => 'get_simulation', 'arguments' => ['year' => (int) now()->year]],
    ]);

    $response->assertOk();
    $result = json_decode($response->json('result.content.0.text', '{}'), true);

    expect($result['rent_benchmarks'][$property->name]['per_m2_euros'])->toBe(15.5)
        // Décodé depuis le JSON-RPC : un nombre entier redevient un int PHP, pas un float.
        ->and($result['rent_benchmarks'][$property->name]['estimated_monthly_euros'])->toBe(775)
        ->and($result['rent_benchmarks'][$property->name]['note'])->toContain('NON meublée')
        // Millésime RÉELLEMENT en base (2025 ici) et son URL, calculée à partir du slug.
        ->and($result['rent_map_vintage'])->toBe(2025)
        ->and($result['rent_map_dataset_url'])->toBe(
            'https://www.data.gouv.fr/datasets/carte-des-loyers-indicateurs-de-loyers-dannonce-par-commune-en-2025'
        )
        // Le champ principal de l'outil n'a pas changé de forme pour autant.
        ->and($result)->toHaveKey('recommended');
});

it('returns an empty rent_benchmarks array from get_simulation when no commune is covered', function () {
    config(['mcp.enabled' => true]);
    makeRentMapProperty(['insee_code' => null]);

    $token = $this->user->createToken('test-token');

    $response = $this->withToken($token->plainTextToken)->postJson('/mcp', [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'tools/call',
        'params' => ['name' => 'get_simulation', 'arguments' => ['year' => (int) now()->year]],
    ]);

    $response->assertOk();
    $result = json_decode($response->json('result.content.0.text', '{}'), true);

    expect($result['rent_benchmarks'])->toBe([])
        ->and($result['rent_map_vintage'])->toBeNull()
        ->and($result['rent_map_dataset_url'])->toBeNull();
});
