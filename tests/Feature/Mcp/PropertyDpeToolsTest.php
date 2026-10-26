<?php

use App\Mcp\Tools\CreateProperty;
use App\Mcp\Tools\GetProperty;
use App\Mcp\Tools\UpdateProperty;
use App\Models\Property;
use App\Models\User;
use Laravel\Mcp\Request as McpRequest;

/**
 * Exposition des champs DPE par les outils MCP du bien — décision prise plutôt que de créer
 * un nouvel outil (voir TODO-OPENDATA-DPE.md) : `get_property`/`update_property`/
 * `create_property` gagnent les quatre champs, comme ils l'ont déjà fait pour `market_value`.
 */
beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

it('exposes the DPE fields through get_property', function () {
    $property = Property::create([
        'user_id' => $this->user->id,
        'name' => 'Bien MCP',
        'address' => '1 rue de la République',
        'city' => 'Lyon',
        'postal_code' => '69003',
        'type' => 'apartment',
        'total_area' => 50,
        'rented_area' => 50,
        'acquisition_date' => '2022-01-01',
        'acquisition_price' => 20_000_000,
        'land_percentage' => 15,
        'rental_start_date' => '2022-03-01',
        'rental_type' => 'seasonal',
        'is_primary_residence' => false,
        'dpe_number' => '2521E1234567A',
        'dpe_class' => 'D',
        'dpe_established_at' => '2025-01-15',
        'dpe_valid_until' => '2035-01-15',
    ]);

    $response = (new GetProperty)->handle(new McpRequest(['property_id' => $property->id]));
    $data = json_decode((string) $response->content(), true);

    expect($data['dpe_number'])->toBe('2521E1234567A')
        ->and($data['dpe_class'])->toBe('D')
        ->and($data['dpe_established_at'])->toBe('2025-01-15')
        ->and($data['dpe_valid_until'])->toBe('2035-01-15');
});

it('updates the DPE fields through update_property', function () {
    $property = Property::create([
        'user_id' => $this->user->id,
        'name' => 'Bien MCP',
        'address' => '1 rue de la République',
        'city' => 'Lyon',
        'postal_code' => '69003',
        'type' => 'apartment',
        'total_area' => 50,
        'rented_area' => 50,
        'acquisition_date' => '2022-01-01',
        'acquisition_price' => 20_000_000,
        'land_percentage' => 15,
        'rental_start_date' => '2022-03-01',
        'rental_type' => 'seasonal',
        'is_primary_residence' => false,
    ]);

    $response = (new UpdateProperty)->handle(new McpRequest([
        'property_id' => $property->id,
        'dpe_number' => '2521E1234567A',
        'dpe_class' => 'C',
        'dpe_established_at' => '2025-02-01',
        'dpe_valid_until' => '2035-02-01',
    ]));

    expect($response->isError())->toBeFalse();

    $property->refresh();
    expect($property->dpe_number)->toBe('2521E1234567A')
        ->and($property->dpe_class)->toBe('C')
        ->and($property->dpe_established_at->toDateString())->toBe('2025-02-01')
        ->and($property->dpe_valid_until->toDateString())->toBe('2035-02-01');
});

it('accepts the DPE fields at creation through create_property', function () {
    $response = (new CreateProperty)->handle(new McpRequest([
        'name' => 'Bien créé par l\'assistant',
        'acquisition_date' => '2022-01-01',
        'acquisition_price' => 200000,
        // Surfaces obligatoires depuis « la surface totale devient obligatoire » (lignée bêta).
        'total_area' => 45,
        'rented_area' => 45,
        'dpe_number' => '2521E1234567A',
        'dpe_class' => 'E',
        'dpe_established_at' => '2025-01-15',
        'dpe_valid_until' => '2035-01-15',
    ]));

    $data = json_decode((string) $response->content(), true);

    expect($data['success'])->toBeTrue();

    $property = Property::find($data['property']['id']);
    expect($property->dpe_class)->toBe('E')
        ->and($property->dpe_number)->toBe('2521E1234567A');
});
