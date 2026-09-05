<?php

use App\Models\Property;
use App\Models\User;
use App\Notifications\WelcomeSetPassword;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    config()->set('services.provisioning.token', 'jeton-de-test');
    Notification::fake();
});

function sandboxToClaim(array $attrs = []): User
{
    $user = User::factory()->create(array_merge([
        'name' => 'demo-abc123',
        'email' => 'demo-abc123@demo.local',
        'is_demo' => true,
        'demo_expires_at' => Carbon::now()->addDays(7),
        'demo_claim_token' => 'jeton-de-reprise-valide',
    ], $attrs));

    Property::create([
        'user_id' => $user->id,
        'name' => 'Studio du visiteur',
        'address' => '1 rue du Test', 'city' => 'Lyon', 'postal_code' => '69003',
        'type' => 'apartment', 'total_area' => 45, 'rented_area' => 45,
        'acquisition_date' => '2022-01-01', 'acquisition_price' => 20000000,
        'land_percentage' => 15, 'rental_start_date' => '2022-03-01',
        'rental_type' => 'seasonal', 'is_primary_residence' => false,
    ]);

    return $user;
}

function provision(array $payload)
{
    return test()->withToken('jeton-de-test')
        ->postJson('/api/admin/users', $payload);
}

it('promotes the sandbox in place, without moving a single row', function () {
    $sandbox = sandboxToClaim();
    $propertyId = $sandbox->properties()->sole()->id;

    provision([
        'email' => 'client@exemple.fr',
        'name' => 'Client',
        'claim' => 'jeton-de-reprise-valide',
    ])->assertCreated()->assertJson(['status' => 'promoted', 'id' => $sandbox->id]);

    $sandbox->refresh();

    expect($sandbox->email)->toBe('client@exemple.fr')
        ->and($sandbox->is_demo)->toBeFalse()
        ->and($sandbox->demo_expires_at)->toBeNull()
        ->and($sandbox->demo_claim_token)->toBeNull()
        ->and($sandbox->demo_promoted_at)->not->toBeNull()
        // Le travail du visiteur est là, au même identifiant : rien n'a été recopié.
        ->and($sandbox->properties()->sole()->id)->toBe($propertyId);

    // Le mot de passe du compte de démonstration est aléatoire : personne ne le connaît.
    Notification::assertSentTo($sandbox, WelcomeSetPassword::class);
});

it('creates a normal account when the claim token is unknown', function () {
    // Le client a payé : il obtient un compte quoi qu'il arrive.
    provision([
        'email' => 'client@exemple.fr',
        'claim' => 'jeton-qui-n-existe-pas',
    ])->assertCreated()->assertJson(['status' => 'created']);

    expect(User::where('email', 'client@exemple.fr')->exists())->toBeTrue();
});

it('creates a normal account when the sandbox was already purged', function () {
    provision(['email' => 'client@exemple.fr'])
        ->assertCreated()
        ->assertJson(['status' => 'created']);

    expect(User::where('email', 'client@exemple.fr')->first()->demo_promoted_at)->toBeNull();
});

it('refuses to promote an expired sandbox', function () {
    // La purge passe toutes les heures : ressusciter un compte qu'elle s'apprête à
    // supprimer créerait une course, et le client se retrouverait sans rien.
    $sandbox = sandboxToClaim(['demo_expires_at' => Carbon::now()->subMinute()]);

    provision([
        'email' => 'client@exemple.fr',
        'claim' => 'jeton-de-reprise-valide',
    ])->assertCreated()->assertJson(['status' => 'created']);

    expect($sandbox->fresh()->email)->toBe('demo-abc123@demo.local')
        ->and(User::where('email', 'client@exemple.fr')->exists())->toBeTrue();
});

it('never merges two accounts when the address already belongs to someone', function () {
    // Le cas dangereux : l'adresse est déjà celle d'un compte réel. On ne fusionne
    // JAMAIS en silence — le comportement idempotent existant l'emporte, et le bac à
    // sable est laissé tel quel, il expirera normalement.
    $existing = User::factory()->create(['email' => 'client@exemple.fr', 'is_demo' => false]);
    $sandbox = sandboxToClaim();

    provision([
        'email' => 'client@exemple.fr',
        'claim' => 'jeton-de-reprise-valide',
    ])->assertOk()->assertJson(['status' => 'exists', 'id' => $existing->id]);

    $sandbox->refresh();

    expect($sandbox->is_demo)->toBeTrue()
        ->and($sandbox->email)->toBe('demo-abc123@demo.local')
        ->and($sandbox->demo_promoted_at)->toBeNull();
});

it('refuses a claim token that points at an account which is no longer a sandbox', function () {
    $promoted = sandboxToClaim([
        'is_demo' => false,
        'demo_claim_token' => 'jeton-deja-utilise',
        'demo_promoted_at' => Carbon::now()->subDay(),
    ]);

    provision([
        'email' => 'autre@exemple.fr',
        'claim' => 'jeton-deja-utilise',
    ])->assertCreated()->assertJson(['status' => 'created']);

    expect($promoted->fresh()->email)->toBe('demo-abc123@demo.local');
});
