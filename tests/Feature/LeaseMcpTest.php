<?php

use App\Models\Income;
use App\Models\Lease;
use App\Models\Property;
use App\Models\User;
use App\Services\LeaseRentService;

/**
 * Les baux et leurs loyers vus par le serveur MCP (issue #24).
 *
 * C'est la porte par laquelle un outil tiers (une quittance, par exemple) lit un loyer :
 * le locataire, la part de charges et la date d'encaissement doivent y arriver, et le
 * bail d'un autre utilisateur jamais.
 *
 * ⚠️ Aucun `actingAs` ici : une session passerait avant le jeton sur `/mcp`.
 */
function leaseMcpProperty(User $user): Property
{
    return Property::withoutGlobalScopes()->forceCreate([
        'user_id'              => $user->id,
        'name'                 => 'Appartement',
        'address'              => '1 rue Test',
        'city'                 => 'Lyon',
        'postal_code'          => '69001',
        'type'                 => 'apartment',
        'total_area'           => 40,
        'rented_area'          => 40,
        'acquisition_date'     => '2020-01-01',
        'acquisition_price'    => 15000000,
        'notary_fees'          => 0,
        'land_percentage'      => 15,
        'rental_start_date'    => '2025-01-01',
        'rental_type'          => Property::RENTAL_LONG_TERM,
        'is_primary_residence' => false,
    ]);
}

function leaseMcpLease(Property $property): Lease
{
    $lease = Lease::create([
        'property_id'    => $property->id,
        'tenant_name'    => 'Camille Martin',
        'start_date'     => '2025-01-01',
        'rent_amount'    => 65000,
        'charges_amount' => 5000,
        'charges_mode'   => Lease::CHARGES_PROVISIONS,
        'due_day'        => 5,
    ]);

    app(LeaseRentService::class)->generate($lease, 2025);

    return $lease;
}

beforeEach(function () {
    $this->travelTo('2026-11-16 10:00:00');
    config(['mcp.enabled' => true]);

    $this->user = User::factory()->create(['mcp_enabled' => true]);
    $this->token = $this->user->createToken('test-token')->plainTextToken;
    $this->property = leaseMcpProperty($this->user);

    $this->send = fn (string $name, array $arguments = []) => $this->withToken($this->token)
        ->postJson('/mcp', [
            'jsonrpc' => '2.0',
            'id'      => 1,
            'method'  => 'tools/call',
            'params'  => ['name' => $name, 'arguments' => $arguments],
        ]);

    $this->call = fn (string $name, array $arguments = []) => ($this->send)($name, $arguments)->assertOk();
});

it('lists the user\'s leases with their amounts in euros, and never another user\'s', function () {
    $mine = leaseMcpLease($this->property);
    leaseMcpLease(leaseMcpProperty(User::factory()->create()));

    $result = json_decode(($this->call)('list_leases')->json('result.content.0.text'), true);

    expect($result['count'])->toBe(1)
        ->and($result['leases'][0])->toMatchArray([
            'id'                 => $mine->id,
            'tenant_name'        => 'Camille Martin',
            'start_date'         => '2025-01-01',
            'end_date'           => null,
            'is_active'          => true,
            'rent_amount_eur'    => '650.00',
            'charges_amount_eur' => '50.00',
            'monthly_total_eur'  => '700.00',
            'charges_mode_label' => 'Provisions sur charges',
            'due_day'            => 5,
            'rents_count'        => 12,
        ]);
});

it('filters the rents of one lease, with their charges and payment date', function () {
    $lease = leaseMcpLease($this->property);
    Income::create([
        'property_id' => $this->property->id, 'income_date' => '2025-06-15', 'amount' => 30000,
        'platform_fee' => 0, 'tourist_tax' => 0, 'source' => Income::SOURCE_DIRECT,
    ]);
    Income::withoutGlobalScopes()->where('lease_id', $lease->id)->orderBy('income_date')->first()
        ->forceFill(['paid_date' => '2025-01-09'])->save();

    $result = json_decode(($this->call)('list_incomes', ['lease_id' => $lease->id])->json('result.content.0.text'), true);
    $january = collect($result['incomes'])->firstWhere('income_date', '2025-01-05');

    expect($result['count'])->toBe(12)
        ->and($january)->toMatchArray([
            'lease_id'           => $lease->id,
            'paid_date'          => '2025-01-09',
            'amount_eur'         => '700.00',
            'charges_amount_eur' => '50.00',
            'guest_name'         => 'Camille Martin',
        ]);
});

it('refuses to list the rents of another user\'s lease', function () {
    $foreign = leaseMcpLease(leaseMcpProperty(User::factory()->create()));

    // Même réponse qu'un bien d'un autre utilisateur passé en `property_id` : le bail
    // n'existe pas pour ce jeton, et aucune ligne ne sort.
    ($this->send)('list_incomes', ['lease_id' => $foreign->id])
        ->assertNotFound()
        ->assertDontSee('Camille Martin');
});

it('records a payment date through update_income and clears it with null', function () {
    $lease = leaseMcpLease($this->property);
    $rent = Income::withoutGlobalScopes()->where('lease_id', $lease->id)->orderBy('income_date')->first();

    ($this->call)('update_income', ['income_id' => $rent->id, 'paid_date' => '2025-01-09']);

    $detail = json_decode(($this->call)('get_income', ['income_id' => $rent->id])->json('result.content.0.text'), true);

    expect($detail)->toMatchArray([
        'lease_id'           => $lease->id,
        'income_date'        => '2025-01-05',
        'paid_date'          => '2025-01-09',
        'charges_amount_eur' => '50.00',
    ]);

    ($this->call)('update_income', ['income_id' => $rent->id, 'paid_date' => null]);

    expect($rent->fresh()->paid_date)->toBeNull()
        ->and($rent->fresh()->income_date->toDateString())->toBe('2025-01-05');
});
