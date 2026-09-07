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

it('responds idempotently (uniform) when suspending an unknown user', function () {
    // F10 : pas d'oracle d'énumération — réponse identique à un compte existant.
    $this->postJson('/api/admin/users/suspend', ['email' => 'inconnu@example.com'], provisionHeaders())
        ->assertOk()
        ->assertJson(['status' => 'suspended']);

    // Aucun compte n'est créé au passage.
    expect(User::query()->where('email', 'inconnu@example.com')->exists())->toBeFalse();
});

it('does not leak account existence via suspend response shape', function () {
    User::factory()->create(['email' => 'client@example.com']);

    $known = $this->postJson('/api/admin/users/suspend', ['email' => 'client@example.com'], provisionHeaders());
    $unknown = $this->postJson('/api/admin/users/suspend', ['email' => 'inconnu@example.com'], provisionHeaders());

    // Même statut HTTP et même corps (pas d'id qui trahirait l'existence).
    expect($known->status())->toBe($unknown->status());
    expect($known->json())->toBe($unknown->json());
});

// === Accès panel d'un compte suspendu ===

it('denies panel access to a suspended user', function () {
    $user = User::factory()->create(['suspended_at' => now()]);

    $this->actingAs($user)->get('/')->assertForbidden();
});

// === Routage du courrier après promotion ===

it('sends the welcome link to the paying address, not to the one left during the demo', function () {
    /*
     * ⚠️ LE PIÈGE DE `routeNotificationForMail()`, SUR LE CHEMIN DE PAIEMENT.
     *
     * Le routage vers `demo_email` existe parce qu'un bac à sable porte une adresse technique
     * en `@demo.local`. Mais `promoteSandbox()` écrit `email` = l'adresse donnée à Stripe et
     * `is_demo = false` SANS jamais vider `demo_email` — aucun point du code ne l'efface.
     *
     * Router sur la seule présence de `demo_email` enverrait donc le lien de création de mot
     * de passe du client qui vient de payer à l'adresse qu'il avait laissée en essayant la
     * démo. Deux adresses différentes, et il ne reçoit jamais son accès.
     *
     * D'où la condition sur `is_demo`, que ce test est seul à exercer.
     */
    Notification::fake();

    $sandbox = User::factory()->create([
        'is_demo' => true,
        'email' => 'demo-abc123@demo.local',
        'demo_email' => 'adresse-de-la-demo@exemple.fr',
        'demo_claim_token' => 'jeton-de-reprise',
        'demo_expires_at' => now()->addHours(6),
    ]);

    $this->postJson('/api/admin/users', [
        'email' => 'adresse-de-paiement@exemple.fr',
        'claim' => 'jeton-de-reprise',
    ], provisionHeaders())->assertSuccessful();

    $sandbox->refresh();

    // Le préalable du piège : la promotion laisse bien l'adresse de démo derrière elle.
    expect($sandbox->is_demo)->toBeFalse()
        ->and($sandbox->demo_email)->toBe('adresse-de-la-demo@exemple.fr');

    // L'ancre unique : c'est l'adresse de paiement qui reçoit.
    expect($sandbox->routeNotificationForMail())->toBe('adresse-de-paiement@exemple.fr');

    Notification::assertSentTo($sandbox, WelcomeSetPassword::class);
});

it('still routes a live sandbox to the address its visitor left', function () {
    // Le pendant du test précédent : sans lui, supprimer tout le routage passerait.
    $sandbox = User::factory()->create([
        'is_demo' => true,
        'email' => 'demo-def456@demo.local',
        'demo_email' => 'visiteur@exemple.fr',
    ]);

    expect($sandbox->routeNotificationForMail())->toBe('visiteur@exemple.fr');
});
