<?php

use App\Enums\AccountKind;
use App\Enums\DossierMemberRole;
use App\Models\LinkedDossier;
use App\Models\User;
use App\Notifications\WelcomeSetPassword;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

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

// === Quota de dossiers liés (lot 4, provisioning) ===

function createActiveLinkedDossier(User $owner): LinkedDossier
{
    // SIREN unique à l'échelle de l'instance (archives comprises) : chaque appel
    // doit en forger un distinct, même au sein d'un seul test.
    static $sequence = 0;
    $sequence++;

    return LinkedDossier::create([
        'public_id' => (string) Str::ulid(),
        'user_id' => User::factory()->create(['is_linked_dossier' => true])->id,
        'owner_id' => $owner->id,
        'kind' => 'indivision',
        'siren' => str_pad((string) (100000000 + $sequence), 9, '0', STR_PAD_LEFT),
    ]);
}

it('poses the linked dossier quota on an existing account', function () {
    $user = User::factory()->create(['email' => 'client@example.com']);

    $this->postJson('/api/admin/users/entitlements', [
        'email' => 'client@example.com',
        'linked_dossier_quota' => 1,
    ], provisionHeaders())
        ->assertOk()
        ->assertJson(['status' => 'updated']);

    expect($user->fresh()->linked_dossier_quota)->toBe(1);
});

it('is idempotent when posing the same quota twice', function () {
    $user = User::factory()->create(['email' => 'client@example.com']);

    $payload = ['email' => 'client@example.com', 'linked_dossier_quota' => 1];
    $this->postJson('/api/admin/users/entitlements', $payload, provisionHeaders())->assertOk();
    $this->postJson('/api/admin/users/entitlements', $payload, provisionHeaders())->assertOk();

    expect($user->fresh()->linked_dossier_quota)->toBe(1);
});

it('rejects a negative or non integer quota', function () {
    $user = User::factory()->create(['email' => 'client@example.com']);

    $this->postJson('/api/admin/users/entitlements', [
        'email' => 'client@example.com',
        'linked_dossier_quota' => -1,
    ], provisionHeaders())->assertUnprocessable();

    $this->postJson('/api/admin/users/entitlements', [
        'email' => 'client@example.com',
        'linked_dossier_quota' => 'un',
    ], provisionHeaders())->assertUnprocessable();

    expect($user->fresh()->linked_dossier_quota)->toBeNull();
});

it('responds identically (no enumeration oracle) for an unknown email', function () {
    User::factory()->create(['email' => 'client@example.com']);

    $known = $this->postJson('/api/admin/users/entitlements', [
        'email' => 'client@example.com',
        'linked_dossier_quota' => 1,
    ], provisionHeaders());

    $unknown = $this->postJson('/api/admin/users/entitlements', [
        'email' => 'inconnu@example.com',
        'linked_dossier_quota' => 1,
    ], provisionHeaders());

    expect($known->status())->toBe($unknown->status());
    expect($known->json())->toBe($unknown->json());
    expect(User::query()->where('email', 'inconnu@example.com')->exists())->toBeFalse();
});

it('returns 404 for entitlements when no provisioning token is configured', function () {
    config(['services.provisioning.token' => null]);

    $this->postJson('/api/admin/users/entitlements', [
        'email' => 'client@example.com',
        'linked_dossier_quota' => 1,
    ])->assertNotFound();
});

it('returns 401 for entitlements with an invalid or missing token', function () {
    $payload = ['email' => 'client@example.com', 'linked_dossier_quota' => 1];

    $this->postJson('/api/admin/users/entitlements', $payload, [
        'Authorization' => 'Bearer wrong-token',
    ])->assertUnauthorized();

    $this->postJson('/api/admin/users/entitlements', $payload)->assertUnauthorized();
});

it('does not grant a quota to a demo account, with the same uniform response', function () {
    User::factory()->create(['email' => 'demo-abc@demo.local', 'is_demo' => true]);

    $response = $this->postJson('/api/admin/users/entitlements', [
        'email' => 'demo-abc@demo.local',
        'linked_dossier_quota' => 1,
    ], provisionHeaders());

    $response->assertOk()->assertJson(['status' => 'updated']);
    expect(User::where('email', 'demo-abc@demo.local')->first()->linked_dossier_quota)->toBeNull();
});

it('never lowers the quota below the number of dossiers already created', function () {
    $user = User::factory()->create(['email' => 'client@example.com', 'linked_dossier_quota' => 3]);
    createActiveLinkedDossier($user);
    createActiveLinkedDossier($user);

    $this->postJson('/api/admin/users/entitlements', [
        'email' => 'client@example.com',
        'linked_dossier_quota' => 0,
    ], provisionHeaders())->assertOk()->assertJson(['status' => 'updated']);

    // Deux dossiers actifs déjà créés : le plancher les protège, même si la
    // vitrine redemande un quota plus bas.
    expect($user->fresh()->linked_dossier_quota)->toBe(2);
});

it('still raises the quota normally when above the floor of already created dossiers', function () {
    $user = User::factory()->create(['email' => 'client@example.com', 'linked_dossier_quota' => 1]);
    createActiveLinkedDossier($user);

    $this->postJson('/api/admin/users/entitlements', [
        'email' => 'client@example.com',
        'linked_dossier_quota' => 5,
    ], provisionHeaders())->assertOk();

    expect($user->fresh()->linked_dossier_quota)->toBe(5);
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

// === Promotion d'un compte membre au paiement (lot « dossiers membre », décision du 2026-10-15) ===

it('promotes a member account to standard on payment, WITHOUT losing its dossier membership', function () {
    Notification::fake();

    $owner = User::factory()->create();
    $dossierUser = User::factory()->create(['is_linked_dossier' => true]);
    $dossier = LinkedDossier::create([
        'public_id' => (string) Str::ulid(),
        'user_id' => $dossierUser->id,
        'owner_id' => $owner->id,
        'kind' => 'indivision',
        'siren' => '100000033',
    ]);

    $membre = User::factory()->create([
        'email' => 'membre@example.com',
        'account_kind' => AccountKind::Member,
    ]);
    $dossier->members()->create([
        'user_id' => $membre->id,
        'role' => DossierMemberRole::Member,
        'accepted_at' => now(),
    ]);

    $this->postJson('/api/admin/users', ['email' => 'membre@example.com'], provisionHeaders())
        ->assertOk()
        ->assertJson(['status' => 'exists', 'id' => $membre->id]);

    $membre->refresh();

    expect($membre->account_kind)->toBe(AccountKind::Standard)
        ->and($membre->isMemberAccount())->toBeFalse();

    // L'appartenance au dossier n'a PAS bougé : même ligne, toujours acceptée.
    $membership = $dossier->members()->where('user_id', $membre->id)->first();
    expect($membership)->not->toBeNull()
        ->and($membership->revoked_at)->toBeNull()
        ->and(User::where('email', 'membre@example.com')->count())->toBe(1);

    // `store()` sur un compte déjà existant n'envoie JAMAIS le courriel de
    // bienvenue (comportement inchangé : c'est la branche `exists`).
    Notification::assertNothingSent();
});

it('does not touch an ordinary (standard) existing account', function () {
    $standard = User::factory()->create(['email' => 'standard@example.com']);

    $this->postJson('/api/admin/users', ['email' => 'standard@example.com'], provisionHeaders())
        ->assertOk()
        ->assertJson(['status' => 'exists', 'id' => $standard->id]);

    expect($standard->fresh()->account_kind)->toBe(AccountKind::Standard);
});

it('refuses to grant a quota to a member account, with the same uniform response', function () {
    $membre = User::factory()->create([
        'email' => 'membre-quota@example.com',
        'account_kind' => AccountKind::Member,
    ]);

    $this->postJson('/api/admin/users/entitlements', [
        'email' => 'membre-quota@example.com',
        'linked_dossier_quota' => 3,
    ], provisionHeaders())
        ->assertOk()
        ->assertJson(['status' => 'updated']);

    expect($membre->fresh()->linked_dossier_quota)->toBeNull();
});
