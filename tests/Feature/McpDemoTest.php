<?php

use App\Console\Commands\McpDemoTokenCommand;
use App\Models\Expense;
use App\Models\Property;
use App\Models\User;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Sanctum\PersonalAccessToken;

const DEMO_EMAIL = 'demo@openlmnp.fr';

/** Crée un bien pour un utilisateur (données lisibles par les outils de lecture). */
function makeProperty(User $user, string $name): Property
{
    return Property::forceCreate([
        'user_id' => $user->id,
        'name' => $name,
        'address' => '1 chemin des Oliviers',
        'city' => 'Nice',
        'postal_code' => '06000',
        'type' => 'apartment',
        'total_area' => 100,
        'rented_area' => 60,
        'acquisition_date' => '2020-01-01',
        'acquisition_price' => 30000000,
        'notary_fees' => 1500000,
        'market_value' => null,
        'land_percentage' => 20,
        'rental_start_date' => '2022-06-01',
        'rental_type' => 'seasonal',
        'tva_regime' => 'exempt',
        'is_primary_residence' => false,
    ]);
}

beforeEach(function () {
    config([
        'mcp.enabled' => true,
        'mcp.demo.enabled' => true,
        'mcp.demo.email' => DEMO_EMAIL,
        'mcp.demo.rate_limit_per_minute' => 1000, // large par défaut ; le test throttle l'abaisse
    ]);

    $this->demoUser = User::factory()->create(['email' => DEMO_EMAIL, 'mcp_enabled' => true]);
    $this->demoToken = $this->demoUser->createToken('demo-public-readonly')->plainTextToken;
    makeProperty($this->demoUser, 'Villa Les Oliviers');

    RateLimiter::clear('mcp-demo:127.0.0.1');
});

function demoCall(string $token, string $tool, array $args = [])
{
    return test()->withToken($token)->postJson('/mcp', [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'tools/call',
        'params' => ['name' => $tool, 'arguments' => $args],
    ]);
}

// === VISIBILITÉ : les 44 outils restent visibles ===

it('keeps write tools visible in tools/list for the demo token', function () {
    $response = $this->withToken($this->demoToken)->postJson('/mcp', [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'tools/list',
    ]);

    $response->assertOk();
    $names = collect($response->json('result.tools'))->pluck('name');

    // Outils phares d'écriture bien VISIBLES (marketing)...
    expect($names)->toContain('create_expense')
        ->toContain('generate_tax_return')
        ->toContain('import_airbnb_csv')
    // ...et outils de lecture aussi.
        ->toContain('list_properties');
});

// === EXÉCUTION : lecture autorisée, écriture bloquée ===

it('executes an allowed read tool for the demo token', function () {
    $response = demoCall($this->demoToken, 'list_properties');

    $response->assertOk();
    expect($response->json('result.isError'))->not->toBeTrue();

    $result = json_decode($response->json('result.content.0.text', '{}'), true);
    expect($result['count'])->toBe(1);
    expect($result['properties'][0]['name'])->toBe('Villa Les Oliviers');
});

it('blocks a write tool for the demo token with an upsell message', function () {
    $propertyId = Property::withoutGlobalScopes()->where('user_id', $this->demoUser->id)->value('id');

    $response = demoCall($this->demoToken, 'create_expense', [
        'property_id' => $propertyId,
        'expense_date' => '2025-03-01',
        'amount' => 250.00,
        'category' => 'insurance',
        'description' => 'Tentative en démo',
    ]);

    $response->assertOk();
    expect($response->json('result.isError'))->toBeTrue();
    expect($response->json('result.content.0.text'))->toContain('openlmnp.fr');

    // Rien n'a été écrit.
    expect(Expense::withoutGlobalScopes()->count())->toBe(0);
});

it('blocks generate_tax_return for the demo token', function () {
    $response = demoCall($this->demoToken, 'generate_tax_return', ['year' => 2024]);

    $response->assertOk();
    expect($response->json('result.isError'))->toBeTrue();
    expect($response->json('result.content.0.text'))->toContain('démo');
});

// === RATE LIMITING par IP ===

it('rate-limits the demo token per IP', function () {
    config(['mcp.demo.rate_limit_per_minute' => 2]);
    RateLimiter::clear('mcp-demo:127.0.0.1');

    demoCall($this->demoToken, 'list_properties')->assertOk();
    demoCall($this->demoToken, 'list_properties')->assertOk();
    demoCall($this->demoToken, 'list_properties')->assertStatus(429);
});

// === LES VRAIS COMPTES NE SONT PAS AFFECTÉS ===

it('does not restrict a real (non-demo) user when demo mode is on', function () {
    $real = User::factory()->create(['email' => 'real@example.com', 'mcp_enabled' => true]);
    $realToken = $real->createToken('perso')->plainTextToken;
    $property = makeProperty($real, 'Bien réel');

    $response = demoCall($realToken, 'create_expense', [
        'property_id' => $property->id,
        'expense_date' => '2025-03-01',
        'amount' => 250.00,
        'category' => 'insurance',
        'description' => 'Charge réelle',
    ]);

    $response->assertOk();
    $result = json_decode($response->json('result.content.0.text', '{}'), true);
    expect($result['success'])->toBeTrue();
    expect(Expense::withoutGlobalScopes()->where('property_id', $property->id)->count())->toBe(1);
});

// === VERROU ANTI-FUITE : l'allowlist reste exactement les 23 outils lecture/calcul ===

it('locks the demo allowlist to exactly the 23 read/compute tools', function () {
    $expected = [
        'list_properties', 'get_property', 'list_incomes', 'get_income',
        'list_expenses', 'get_expense', 'list_loans', 'get_loan', 'get_loan_schedule',
        'list_fiscal_years', 'get_fiscal_year', 'list_furniture', 'list_property_works',
        'list_property_components', 'get_onboarding_status', 'get_dashboard_summary',
        'list_categories', 'compute_depreciation', 'compute_fiscal_year', 'compute_tva',
        'compare_micro_bic', 'get_projection', 'get_simulation',
    ];

    $actual = config('mcp.demo.tools');

    sort($expected);
    sort($actual);

    expect($actual)->toBe($expected);
    expect($actual)->toHaveCount(23);
});

// === COMMANDE DE PROVISIONING : token déterministe & stable ===

it('provisions a stable deterministic demo token via the artisan command', function () {
    // Compte + données déjà présents → la commande ne reseede pas.
    config(['mcp.demo.token' => 'openlmnp_demo_public_readonly_test']);

    $this->artisan('openlmnp:mcp-demo-token')->assertSuccessful();

    $this->demoUser->refresh();
    expect($this->demoUser->mcp_enabled)->toBeTrue();
    expect($this->demoUser->is_demo)->toBeFalse();

    // Le PAT stocke bien le hash sha256 de la valeur brute.
    $pat = PersonalAccessToken::where('name', McpDemoTokenCommand::TOKEN_NAME)->first();
    expect($pat)->not->toBeNull();
    expect($pat->token)->toBe(hash('sha256', 'openlmnp_demo_public_readonly_test'));

    // Et Sanctum accepte la valeur brute (sans préfixe id|) → la démo lecture marche.
    $response = demoCall('openlmnp_demo_public_readonly_test', 'list_properties');
    $response->assertOk();
    expect($response->json('result.isError'))->not->toBeTrue();
});

// === AUTH DÉMO PAR QUERY PARAM (?demo_token=) — pour la gateway Smithery ===

it('authenticates the demo token via the ?demo_token query param (read allowed, write blocked)', function () {
    config(['mcp.demo.token' => 'openlmnp_demo_ro_qtest']);
    // PAT déterministe (comme la commande openlmnp:mcp-demo-token).
    $this->demoUser->tokens()->create([
        'name' => 'demo-public-readonly',
        'token' => hash('sha256', 'openlmnp_demo_ro_qtest'),
        'abilities' => ['*'],
    ]);
    $propertyId = Property::withoutGlobalScopes()->where('user_id', $this->demoUser->id)->value('id');

    // Lecture via query param, SANS en-tête Authorization → autorisée.
    $read = $this->postJson('/mcp?demo_token=openlmnp_demo_ro_qtest', [
        'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
        'params' => ['name' => 'list_properties', 'arguments' => []],
    ]);
    $read->assertOk();
    expect($read->json('result.isError'))->not->toBeTrue();

    // Écriture via query param → bloquée (même barrière lecture seule).
    $write = $this->postJson('/mcp?demo_token=openlmnp_demo_ro_qtest', [
        'jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/call',
        'params' => ['name' => 'create_expense', 'arguments' => [
            'property_id' => $propertyId, 'expense_date' => '2025-01-01',
            'amount' => 10, 'category' => 'other', 'description' => 'x',
        ]],
    ]);
    $write->assertOk();
    expect($write->json('result.isError'))->toBeTrue();
});

it('rejects a wrong ?demo_token (no header promotion)', function () {
    config(['mcp.demo.token' => 'openlmnp_demo_ro_qtest']);
    // Test isolé : aucune requête authentifiée préalable (pas d'auth en cache).
    $this->postJson('/mcp?demo_token=mauvais', [
        'jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => [],
    ])->assertUnauthorized();
});
