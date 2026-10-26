<?php

use App\Models\Property;
use App\Models\User;

/**
 * `create_property`/`update_property` laissaient `total_area`/`rented_area` retomber sur 0
 * quand l'appelant MCP (typiquement un assistant IA) les omettait. `Property::quota_share`
 * multiplie la base amortissable ET quasiment toutes les charges au prorata : un bien créé
 * sans surface amortissait silencieusement à zéro, sans qu'aucun montant n'ait l'air faux.
 */
beforeEach(function () {
    config(['mcp.enabled' => true]);

    $this->user = User::factory()->create(['mcp_enabled' => true]);
    $this->token = $this->user->createToken('test-token');
});

function callPropertyTool(string $name, array $arguments = []): \Illuminate\Testing\TestResponse
{
    return test()->withToken(test()->token->plainTextToken)->postJson('/mcp', [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'tools/call',
        'params' => ['name' => $name, 'arguments' => $arguments],
    ]);
}

it('refuses to create a property without a total_area, rather than defaulting it to zero', function () {
    $response = callPropertyTool('create_property', [
        'name' => 'Bien sans surface',
        'acquisition_date' => '2024-01-01',
        'acquisition_price' => 200000,
        'rented_area' => 35,
        // total_area délibérément omis.
    ]);

    expect($response->json('result.isError'))->toBeTrue()
        ->and($response->json('result.content.0.text'))->toContain('total_area');

    expect(Property::withoutGlobalScopes()->where('name', 'Bien sans surface')->exists())->toBeFalse();
});

it('refuses to create a property without a rented_area', function () {
    $response = callPropertyTool('create_property', [
        'name' => 'Bien sans surface louée',
        'acquisition_date' => '2024-01-01',
        'acquisition_price' => 200000,
        'total_area' => 100,
    ]);

    expect($response->json('result.isError'))->toBeTrue()
        ->and($response->json('result.content.0.text'))->toContain('rented_area');
});

it('refuses a rented_area larger than the total_area', function () {
    $response = callPropertyTool('create_property', [
        'name' => 'Bien incohérent',
        'acquisition_date' => '2024-01-01',
        'acquisition_price' => 200000,
        'total_area' => 30,
        'rented_area' => 35,
    ]);

    expect($response->json('result.isError'))->toBeTrue()
        ->and($response->json('result.content.0.text'))->toContain('rented_area');
});

it('creates a property normally once both surfaces are provided', function () {
    $response = callPropertyTool('create_property', [
        'name' => 'Bien complet',
        'acquisition_date' => '2024-01-01',
        'acquisition_price' => 200000,
        'total_area' => 118,
        'rented_area' => 31,
    ]);

    expect($response->json('result.isError'))->not->toBeTrue();

    $property = Property::withoutGlobalScopes()->where('name', 'Bien complet')->firstOrFail();
    expect($property->total_area)->toBe(118)
        ->and($property->rented_area)->toBe(31);
});

it('refuses to zero out total_area on an existing property through update_property', function () {
    $property = Property::forceCreate([
        'user_id' => $this->user->id, 'name' => 'Bien Existant', 'address' => '1 rue Test',
        'city' => 'Paris', 'postal_code' => '75001', 'type' => 'apartment',
        'total_area' => 100, 'rented_area' => 50, 'acquisition_date' => '2023-01-01',
        'acquisition_price' => 20_000_000, 'notary_fees' => 0, 'land_percentage' => 15,
        'rental_start_date' => '2023-01-01', 'rental_type' => 'seasonal',
        'is_primary_residence' => false,
    ]);

    $response = callPropertyTool('update_property', [
        'property_id' => $property->id,
        'total_area' => 0,
    ]);

    expect($response->json('result.isError'))->toBeTrue();
    expect($property->fresh()->total_area)->toBe(100);
});

it('refuses to shrink total_area below the already rented area through update_property', function () {
    $property = Property::forceCreate([
        'user_id' => $this->user->id, 'name' => 'Bien Existant', 'address' => '1 rue Test',
        'city' => 'Paris', 'postal_code' => '75001', 'type' => 'apartment',
        'total_area' => 100, 'rented_area' => 50, 'acquisition_date' => '2023-01-01',
        'acquisition_price' => 20_000_000, 'notary_fees' => 0, 'land_percentage' => 15,
        'rental_start_date' => '2023-01-01', 'rental_type' => 'seasonal',
        'is_primary_residence' => false,
    ]);

    $response = callPropertyTool('update_property', [
        'property_id' => $property->id,
        'total_area' => 40,
    ]);

    expect($response->json('result.isError'))->toBeTrue();
    expect($property->fresh()->total_area)->toBe(100);
});
