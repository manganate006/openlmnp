<?php

use App\Models\User;
use App\Notifications\WelcomeSetPassword;
use Illuminate\Support\Facades\Notification;

const PROVISION_TOKEN = 'test-provision-token-0123456789abcdef';

function provisionHeaders(): array
{
    return ['Authorization' => 'Bearer '.PROVISION_TOKEN];
}

beforeEach(function () {
    config(['services.provisioning.token' => PROVISION_TOKEN]);
});

// === Sécurité ===

it('returns 404 when no provisioning token is configured', function () {
    config(['services.provisioning.token' => null]);

    $this->postJson('/api/admin/users', ['email' => 'client@example.com'])
        ->assertNotFound();
});

it('returns 401 with an invalid token', function () {
    $this->postJson('/api/admin/users', ['email' => 'client@example.com'], [
        'Authorization' => 'Bearer wrong-token',
    ])->assertUnauthorized();
});

it('returns 401 without any token', function () {
    $this->postJson('/api/admin/users', ['email' => 'client@example.com'])
        ->assertUnauthorized();
});

// === Création de compte ===

it('creates a user and sends the welcome notification', function () {
    Notification::fake();

    $response = $this->postJson('/api/admin/users', [
        'email' => 'client@example.com',
        'name' => 'Client Test',
    ], provisionHeaders());

    $response->assertCreated()->assertJson(['status' => 'created']);

    $user = User::query()->where('email', 'client@example.com')->first();
    expect($user)->not->toBeNull();
    expect($user->name)->toBe('Client Test');
    expect($user->suspended_at)->toBeNull();

    Notification::assertSentTo($user, WelcomeSetPassword::class);
});

it('defaults the name to the email local part', function () {
    Notification::fake();

    $this->postJson('/api/admin/users', ['email' => 'jeanne.dupont@example.com'], provisionHeaders())
        ->assertCreated();

    expect(User::query()->where('email', 'jeanne.dupont@example.com')->first()->name)
        ->toBe('jeanne.dupont');
});

it('is idempotent for an existing user', function () {
    Notification::fake();
    $existing = User::factory()->create(['email' => 'client@example.com']);

    $this->postJson('/api/admin/users', ['email' => 'client@example.com'], provisionHeaders())
        ->assertOk()
        ->assertJson(['status' => 'exists', 'id' => $existing->id]);

    expect(User::query()->where('email', 'client@example.com')->count())->toBe(1);
    Notification::assertNothingSent();
});

it('rejects an invalid email', function () {
    $this->postJson('/api/admin/users', ['email' => 'pas-un-email'], provisionHeaders())
        ->assertUnprocessable();
});

// === Suspension / réactivation ===

it('suspends then unsuspends a user', function () {
    $user = User::factory()->create(['email' => 'client@example.com']);

    $this->postJson('/api/admin/users/suspend', ['email' => 'client@example.com'], provisionHeaders())
        ->assertOk()
        ->assertJson(['status' => 'suspended']);

    expect($user->fresh()->suspended_at)->not->toBeNull();
    expect($user->fresh()->canAccessPanel(filament()->getDefaultPanel()))->toBeFalse();

    $this->postJson('/api/admin/users/unsuspend', ['email' => 'client@example.com'], provisionHeaders())
        ->assertOk()
        ->assertJson(['status' => 'active']);

    expect($user->fresh()->suspended_at)->toBeNull();
    expect($user->fresh()->canAccessPanel(filament()->getDefaultPanel()))->toBeTrue();
});

it('returns 404 when suspending an unknown user', function () {
    $this->postJson('/api/admin/users/suspend', ['email' => 'inconnu@example.com'], provisionHeaders())
        ->assertNotFound();
});

// === Accès panel d'un compte suspendu ===

it('denies panel access to a suspended user', function () {
    $user = User::factory()->create(['suspended_at' => now()]);

    $this->actingAs($user)->get('/')->assertForbidden();
});
