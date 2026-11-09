<?php

use App\Enums\AccountKind;
use App\Enums\DossierMemberRole;
use App\Models\DossierEvent;
use App\Models\User;
use App\Services\LinkedDossierInvitationService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * Création d'un compte « membre » gratuit (App\Enums\AccountKind::Member) depuis un
 * lien d'invitation de dossier — lot « dossiers membre », décision de Jérémie du
 * 2026-10-15 : « un indivisaire sans biens à lui doit pouvoir accéder gratuitement
 * au dossier d'indivision partagé sur lequel il est invité, sans abonnement ».
 *
 * `inviterIndivisaire()` : fonction globale Pest, voir LinkedDossierAcceptanceTest.php.
 * `creerDossierLie()` : fonction globale Pest, voir LinkedDossierSwitchSecurityTest.php.
 */
beforeEach(function () {
    config(['dossiers.enabled' => true, 'services.provisioning.token' => null, 'dossiers.quota' => null]);
    RateLimiter::clear('dossier.invitation.show');
    RateLimiter::clear('dossier.invitation.create-member');
});

function soumettreCreationCompteMembre(string $token, array $overrides = []): \Illuminate\Testing\TestResponse
{
    return test()->post(route('dossier.invitation.create-member', ['token' => $token]), array_replace([
        'name' => 'Nouvel indivisaire',
        'password' => 'mot-de-passe-sur',
        'password_confirmation' => 'mot-de-passe-sur',
    ], $overrides));
}

// === Jeton : mêmes états que l'acceptation, même patron de refus ===

it('404 quand la fonctionnalité est désactivée', function () {
    config(['dossiers.enabled' => false]);
    [, , , $plain] = inviterIndivisaire();

    soumettreCreationCompteMembre($plain)->assertNotFound();
});

it('refuse un jeton inconnu', function () {
    soumettreCreationCompteMembre(Str::random(48))
        ->assertStatus(410)
        ->assertSee('n\'est plus valable', false);

    expect(User::count())->toBe(0);
});

it('refuse une invitation révoquée', function () {
    [, $owner, $invitation, $plain] = inviterIndivisaire();
    app(LinkedDossierInvitationService::class)->revoke($invitation, $owner);

    soumettreCreationCompteMembre($plain)->assertStatus(410);

    expect(User::where('email', 'indivisaire@exemple.fr')->exists())->toBeFalse();
});

it('refuse une invitation expirée', function () {
    [, , $invitation, $plain] = inviterIndivisaire();
    $invitation->update(['expires_at' => now()->subDay()]);

    soumettreCreationCompteMembre($plain)
        ->assertStatus(410)
        ->assertSee('a expiré', false);

    expect(User::where('email', 'indivisaire@exemple.fr')->exists())->toBeFalse();
});

it('refuse une invitation déjà acceptée', function () {
    [, , $invitation, $plain] = inviterIndivisaire();
    $invitation->update(['accepted_at' => now()]);

    soumettreCreationCompteMembre($plain)->assertStatus(410);

    expect(User::where('email', 'indivisaire@exemple.fr')->exists())->toBeFalse();
});

it('ne consomme le jeton qu\'une seule fois (deux tentatives, un seul compte)', function () {
    // Au niveau du SERVICE plutôt que de la route HTTP : la route redirige une
    // seconde tentative déjà authentifiée (couvert par un autre test) sans même
    // consulter l'invitation — ce test-ci vérifie la garde atomique elle-même.
    [, , $invitation] = inviterIndivisaire();
    $service = app(LinkedDossierInvitationService::class);

    $service->acceptAsNewMember($invitation, ['name' => 'Premier', 'password' => 'mot-de-passe-sur']);

    expect(fn () => $service->acceptAsNewMember($invitation, ['name' => 'Second', 'password' => 'mot-de-passe-sur']))
        ->toThrow(RuntimeException::class);

    expect(User::where('email', 'indivisaire@exemple.fr')->count())->toBe(1);
});

// === L'e-mail est IMPOSÉ, jamais un champ du formulaire ===

it('impose l\'e-mail de l\'invitation, même en forgeant le champ', function () {
    [, , $invitation, $plain] = inviterIndivisaire();

    soumettreCreationCompteMembre($plain, ['email' => 'attaquant@ailleurs.fr'])->assertOk();

    $user = User::where('email', 'indivisaire@exemple.fr')->first();

    expect($user)->not->toBeNull()
        ->and(User::where('email', 'attaquant@ailleurs.fr')->exists())->toBeFalse()
        ->and($invitation->refresh()->isAccepted())->toBeTrue();
});

it('refuse la création si un compte existe déjà pour cette adresse, sans rien créer de double', function () {
    [, , $invitation, $plain] = inviterIndivisaire();
    User::factory()->create(['email' => 'indivisaire@exemple.fr']);

    soumettreCreationCompteMembre($plain)->assertStatus(410);

    expect(User::where('email', 'indivisaire@exemple.fr')->count())->toBe(1)
        // Toute la transaction (consommation de l'invitation COMPRISE) est annulée
        // avec l'échec : DB::transaction() fait un rollback complet dès qu'une
        // exception en sort, l'invitation reste donc techniquement utilisable —
        // sans effet pratique tant que ce compte existe.
        ->and($invitation->refresh()->isAccepted())->toBeFalse();
});

// === Pas d'oracle d'énumération ===

it('répond de façon identique à un jeton inconnu qu\'à un jeton révoqué', function () {
    [, $owner, $invitation, $plainRevoque] = inviterIndivisaire('revoque@exemple.fr');
    app(LinkedDossierInvitationService::class)->revoke($invitation, $owner);

    $revoque = soumettreCreationCompteMembre($plainRevoque);
    RateLimiter::clear('dossier.invitation.create-member');
    $inconnu = soumettreCreationCompteMembre(Str::random(48));

    expect($revoque->status())->toBe($inconnu->status())
        ->and($revoque->getContent())->toBe($inconnu->getContent());
});

// === Inscription fermée : CONTOURNÉE par un jeton d'invitation de dossier valide ===

it('crée le compte membre même quand ALLOW_REGISTRATION vaut false', function () {
    config(['app.allow_registration' => false]);
    [, , , $plain] = inviterIndivisaire();

    // ⚠️ La route /register est enregistrée AU BOOT du panel (voir
    // tests/Feature/RegistrationFlagTest.php) : un config() posé ICI, en cours de
    // test, ne peut plus la faire disparaître. On vérifie donc la PORTE elle-même
    // (RegistrationGate::allows(), la seule chose que ce lot peut faire varier à cet
    // instant) plutôt qu'un comportement HTTP déjà figé par le boot.
    expect(\App\Support\RegistrationGate::allows())->toBeFalse();

    soumettreCreationCompteMembre($plain)->assertOk();

    $user = User::where('email', 'indivisaire@exemple.fr')->first();
    expect($user)->not->toBeNull()
        ->and($user->isMemberAccount())->toBeTrue();
});

it('crée le compte membre même en mode invite sans jeton d\'inscription', function () {
    config(['app.allow_registration' => 'invite']);
    // Amorçage : un compte réel existe déjà, donc le mode « invite » est actif (plus
    // de porte « aucun compte encore »).
    User::factory()->create();
    [, , , $plain] = inviterIndivisaire();

    soumettreCreationCompteMembre($plain)->assertOk();

    expect(User::where('email', 'indivisaire@exemple.fr')->exists())->toBeTrue();
});

// === Création réussie : compte, connexion, rattachement, en une étape ===

it('crée le compte, le connecte et le rattache au dossier en une seule étape', function () {
    [$dossier, $owner, $invitation, $plain] = inviterIndivisaire();

    soumettreCreationCompteMembre($plain)
        ->assertOk()
        ->assertSee('Vous avez rejoint', false)
        ->assertSee($dossier->user->name, false);

    $user = User::where('email', 'indivisaire@exemple.fr')->first();

    expect($user)->not->toBeNull()
        ->and($user->name)->toBe('Nouvel indivisaire')
        ->and($user->account_kind)->toBe(AccountKind::Member)
        ->and($user->isMemberAccount())->toBeTrue()
        ->and(Auth::id())->toBe($user->id)
        ->and($invitation->refresh()->isAccepted())->toBeTrue();

    $membership = $dossier->members()->where('user_id', $user->id)->first();

    expect($membership)->not->toBeNull()
        ->and($membership->role)->toBe(DossierMemberRole::Member)
        ->and($membership->revoked_at)->toBeNull()
        ->and(DossierEvent::where('linked_dossier_id', $dossier->id)
            ->where('type', DossierEvent::TYPE_MEMBER_ACCEPTED)
            ->where('user_id', $user->id)
            ->exists())->toBeTrue()
        ->and(collect((array) session('analytics'))->firstWhere('event', 'linked_dossier_joined'))->not->toBeNull();
});

it('rejette un mot de passe ou un nom manquant, sans créer de compte', function () {
    [, , , $plain] = inviterIndivisaire();

    soumettreCreationCompteMembre($plain, ['name' => '', 'password' => '', 'password_confirmation' => ''])
        ->assertSessionHasErrors(['name', 'password']);

    expect(User::where('email', 'indivisaire@exemple.fr')->exists())->toBeFalse();
});

it('rejette une confirmation de mot de passe qui ne correspond pas', function () {
    [, , , $plain] = inviterIndivisaire();

    soumettreCreationCompteMembre($plain, ['password_confirmation' => 'autre-chose'])
        ->assertSessionHasErrors(['password']);

    expect(User::where('email', 'indivisaire@exemple.fr')->exists())->toBeFalse();
});

it('redirige un visiteur déjà connecté vers le parcours d\'acceptation normal, sans créer de second compte', function () {
    [, , , $plain] = inviterIndivisaire();
    $dejaConnecte = User::factory()->create(['email' => 'indivisaire@exemple.fr']);

    $this->actingAs($dejaConnecte);

    soumettreCreationCompteMembre($plain)->assertRedirect(route('dossier.invitation.show', $plain));

    expect(User::where('email', 'indivisaire@exemple.fr')->count())->toBe(1);
});

it('limite les tentatives par minute', function () {
    foreach (range(1, 10) as $i) {
        soumettreCreationCompteMembre(Str::random(48));
    }

    soumettreCreationCompteMembre(Str::random(48))->assertStatus(429);
});
