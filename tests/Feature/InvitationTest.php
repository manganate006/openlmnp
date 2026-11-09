<?php

use App\Filament\Auth\RegisterFromInvitation;
use App\Models\Invitation;
use App\Models\User;
use App\Notifications\AccountInvitation;
use App\Services\InvitationService;
use App\Support\RegistrationGate;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Livewire\Livewire;

beforeEach(function () {
    Notification::fake();
    config(['app.allow_registration' => RegistrationGate::MODE_INVITE]);
    // ⚠️ Sans un compte réel, le repli d'amorçage laisse l'inscription LIBRE ouverte et
    // `requiresInvitation()` rend false : tous les tests d'invitation mesureraient alors
    // le mode ouvert sans s'en apercevoir.
    $this->admin = User::factory()->create(['is_admin' => true]);
    RateLimiter::clear('invitation.show');
    $this->service = app(InvitationService::class);
});

/** @return array{0: Invitation, 1: string} */
function inviter(string $email = 'testeur@exemple.fr', array $attrs = []): array
{
    return app(InvitationService::class)->create(
        $email,
        $attrs['name'] ?? null,
        $attrs['by'] ?? null,
        $attrs['days'] ?? null,
        $attrs['note'] ?? null,
    );
}

// =============================================================================
// Création et service
// =============================================================================

it('crée une invitation et envoie le courriel à l\'adresse invitée', function () {
    [$invitation, $plain] = inviter();
    $this->service->notify($invitation, $plain);

    Notification::assertSentTo($invitation, AccountInvitation::class);
    expect($invitation->routeNotificationForMail())->toBe('testeur@exemple.fr');
});

it('ne stocke jamais le jeton en clair', function () {
    [$invitation, $plain] = inviter();

    // Le vol d'une sauvegarde ne doit pas livrer un jeu de liens encore valides.
    expect(Invitation::where('token_hash', $plain)->exists())->toBeFalse()
        ->and($invitation->token_hash)->toBe(hash('sha256', $plain))
        ->and($invitation->token_hash)->toHaveLength(64);
});

it('refuse d\'inviter une adresse qui a déjà un compte', function () {
    User::factory()->create(['email' => 'connu@exemple.fr']);

    expect(fn () => inviter('connu@exemple.fr'))->toThrow(RuntimeException::class, 'existe déjà');
});

it('refuse une deuxième invitation vivante, mais l\'accepte après révocation', function () {
    [$premiere] = inviter();

    expect(fn () => inviter())->toThrow(RuntimeException::class, 'déjà en attente');

    $this->service->revoke($premiere);

    expect(fn () => inviter())->not->toThrow(RuntimeException::class);
});

it('fige l\'expiration à la création', function () {
    config(['invitations.ttl_days' => 3]);
    [$invitation] = inviter();
    $avant = $invitation->expires_at;

    // Changer le réglage ne doit pas rallonger rétroactivement ce qui est parti.
    config(['invitations.ttl_days' => 90]);

    expect($invitation->refresh()->expires_at->timestamp)->toBe($avant->timestamp);
});

// =============================================================================
// Le lien
// =============================================================================

it('mène au formulaire d\'inscription avec un jeton valide', function () {
    [$invitation, $plain] = inviter();

    $this->get(route('invitation.show', ['token' => $plain]))
        ->assertRedirect()
        ->assertSessionHas('invitation.id', $invitation->id);
});

it('refuse un jeton inconnu', function () {
    $this->get(route('invitation.show', ['token' => Str::random(48)]))
        ->assertStatus(410)
        ->assertSee('n\'est plus valable', false);
});

it('traite un jeton révoqué EXACTEMENT comme un jeton inconnu', function () {
    // ⚠️ L'ancre de la décision anti-fuite : annoncer « on vous a retiré votre invitation »
    // expose une décision administrative à quelqu'un qu'on vient d'écarter, et n'aide
    // personne. Les deux réponses doivent être identiques, octet pour octet.
    [$invitation, $plain] = inviter();
    $this->service->revoke($invitation);

    $revoquee = $this->get(route('invitation.show', ['token' => $plain]));
    RateLimiter::clear('invitation.show');
    $inconnue = $this->get(route('invitation.show', ['token' => Str::random(48)]));

    expect($revoquee->getContent())->toBe($inconnue->getContent())
        ->and($revoquee->status())->toBe($inconnue->status());
});

it('annonce une expiration avec un message distinct', function () {
    [$invitation, $plain] = inviter();
    $invitation->update(['expires_at' => now()->subDay()]);

    // Distinct parce que ce n'est pas un jugement : c'est actionnable.
    $this->get(route('invitation.show', ['token' => $plain]))
        ->assertStatus(410)
        ->assertSee('a expiré', false);
});

it('renvoie vers la connexion quand l\'invitation a déjà servi', function () {
    [$invitation, $plain] = inviter();
    $invitation->update(['accepted_at' => now()]);

    $this->get(route('invitation.show', ['token' => $plain]))
        ->assertStatus(410)
        ->assertSee('compte existe déjà', false);
});

it('demande de se déconnecter quand quelqu\'un d\'autre est connecté', function () {
    // Cas réel : l'administrateur teste son propre lien en étant connecté. Filament
    // redirige silencieusement un utilisateur authentifié — il croirait le lien cassé.
    [, $plain] = inviter();

    $this->actingAs($this->admin)
        ->get(route('invitation.show', ['token' => $plain]))
        ->assertStatus(409)
        ->assertSee('déjà connecté', false);
});

it('limite les tentatives par minute', function () {
    foreach (range(1, 10) as $i) {
        $this->get(route('invitation.show', ['token' => Str::random(48)]));
    }

    $this->get(route('invitation.show', ['token' => Str::random(48)]))->assertStatus(429);
});

// =============================================================================
// L'inscription
// =============================================================================

it('crée le compte avec l\'adresse de l\'invitation, pas celle du formulaire', function () {
    [$invitation, $plain] = inviter('invite@exemple.fr');
    session()->put('invitation', ['id' => $invitation->id, 'hash' => hash('sha256', $plain)]);

    Livewire::test(RegisterFromInvitation::class)
        ->fillForm([
            'name' => 'Invité',
            // Une adresse forgée : elle ne doit avoir AUCUN effet.
            'email' => 'attaquant@exemple.fr',
            'password' => 'mot-de-passe-solide',
            'passwordConfirmation' => 'mot-de-passe-solide',
        ])
        ->call('register')
        ->assertHasNoFormErrors();

    expect(User::where('email', 'invite@exemple.fr')->exists())->toBeTrue()
        ->and(User::where('email', 'attaquant@exemple.fr')->exists())->toBeFalse();
});

it('ne consomme l\'invitation qu\'une seule fois', function () {
    [$invitation, $plain] = inviter();

    $this->service->claim($invitation, ['name' => 'Un', 'password' => 'mot-de-passe-solide']);

    expect(fn () => $this->service->claim($invitation->refresh(), ['name' => 'Deux', 'password' => 'autre-mot-de-passe']))
        ->toThrow(RuntimeException::class)
        ->and(User::where('email', $invitation->email)->count())->toBe(1);
});

it('relie le compte à son invitation', function () {
    [$invitation, $plain] = inviter();
    $user = $this->service->claim($invitation, ['name' => 'Invité', 'password' => 'mot-de-passe-solide']);

    $invitation->refresh();

    expect($invitation->user_id)->toBe($user->id)
        ->and($invitation->accepted_at)->not->toBeNull()
        ->and($invitation->status()->value)->toBe('accepted');
});

it('renvoie vers la connexion quand le formulaire est ouvert sans invitation', function () {
    // ⚠️ Le refus se fait au MONTAGE, pas à la soumission : sans invitation en session, la
    // page ne construit même pas son formulaire et renvoie vers la connexion en le disant.
    // C'est plus sûr que de laisser saisir un mot de passe pour le rejeter ensuite — et
    // c'est ce que mesure ce test, la première version essayait de remplir un formulaire
    // qui n'existe pas (« getDefaultTestingSchemaName() on null »).
    Livewire::test(RegisterFromInvitation::class)
        ->assertRedirect(\Filament\Facades\Filament::getLoginUrl());

    expect(User::where('name', 'Sans invitation')->exists())->toBeFalse();
});

it('refuse la création si l\'invitation disparaît entre l\'ouverture et l\'envoi', function () {
    // Relance, révocation ou expiration pendant que le formulaire est ouvert : le hash en
    // session ne correspond plus, et rien ne doit être créé.
    [$invitation, $plain] = inviter('volatile@exemple.fr');
    session()->put('invitation', ['id' => $invitation->id, 'hash' => hash('sha256', $plain)]);

    $page = Livewire::test(RegisterFromInvitation::class);

    app(InvitationService::class)->resend($invitation);

    $page->fillForm([
        'name' => 'Trop tard',
        'password' => 'mot-de-passe-solide',
        'passwordConfirmation' => 'mot-de-passe-solide',
    ])->call('register')->assertHasFormErrors();

    expect(User::where('email', 'volatile@exemple.fr')->exists())->toBeFalse();
});

it('laisse l\'inscription libre intacte quand ALLOW_REGISTRATION vaut true', function () {
    // Non-régression : la page custom sert les QUATRE modes.
    config(['app.allow_registration' => true]);

    Livewire::test(RegisterFromInvitation::class)
        ->fillForm([
            'name' => 'Libre',
            'email' => 'libre@exemple.fr',
            'password' => 'mot-de-passe-solide',
            'passwordConfirmation' => 'mot-de-passe-solide',
        ])
        ->call('register')
        ->assertHasNoFormErrors();

    expect(User::where('email', 'libre@exemple.fr')->exists())->toBeTrue();
});

// =============================================================================
// Relance et révocation
// =============================================================================

it('la relance régénère le jeton et tue le précédent', function () {
    [$invitation, $ancien] = inviter();
    $nouveau = $this->service->resend($invitation);

    expect($nouveau)->not->toBe($ancien)
        ->and($this->service->resolve($ancien))->toBeNull()
        ->and($this->service->resolve($nouveau)?->id)->toBe($invitation->id)
        ->and($invitation->refresh()->sent_count)->toBe(2);
});

it('refuse de relancer une invitation acceptée', function () {
    [$invitation] = inviter();
    $invitation->update(['accepted_at' => now()]);

    expect(fn () => $this->service->resend($invitation))->toThrow(RuntimeException::class, 'déjà été acceptée');
});

it('la révocation ne touche pas au compte déjà créé', function () {
    // ⚠️ Révoquer n'est PAS suspendre. Le croire, c'est croire avoir coupé un accès
    // qu'on n'a pas coupé.
    [$invitation, $plain] = inviter();
    $user = $this->service->claim($invitation, ['name' => 'Invité', 'password' => 'mot-de-passe-solide']);

    expect(fn () => $this->service->revoke($invitation->refresh()))->toThrow(RuntimeException::class)
        ->and($user->refresh()->suspended_at)->toBeNull();
});

// =============================================================================
// Commande
// =============================================================================

it('openlmnp:invite imprime le lien même sans SMTP', function () {
    $this->artisan('openlmnp:invite', ['email' => 'cli@exemple.fr', '--no-mail' => true])
        ->expectsOutputToContain('/invitation/')
        ->assertSuccessful();
});

it('openlmnp:invite bâtit le lien sur APP_URL, même hors requête HTTP', function () {
    // ⚠️ L'ancre du couple forceRootUrl/forceScheme : sans lui le lien sort sur l'hôte
    // interne du conteneur, ou en http:// derrière un proxy.
    config(['app.url' => 'https://exemple.fr']);

    $this->artisan('openlmnp:invite', ['email' => 'url@exemple.fr', '--no-mail' => true])
        ->expectsOutputToContain('https://exemple.fr/invitation/')
        ->assertSuccessful();
});

it('openlmnp:invite avertit quand ALLOW_REGISTRATION n\'est pas « invite »', function () {
    config(['app.allow_registration' => false]);

    $this->artisan('openlmnp:invite', ['email' => 'avert@exemple.fr', '--no-mail' => true])
        ->expectsOutputToContain('ALLOW_REGISTRATION')
        ->assertSuccessful();
});

it('openlmnp:invite --revoke, --resend et --list', function () {
    [$invitation] = inviter('gestion@exemple.fr');

    $this->artisan('openlmnp:invite', ['email' => 'gestion@exemple.fr', '--resend' => true, '--no-mail' => true])
        ->assertSuccessful();

    expect($invitation->refresh()->sent_count)->toBe(2);

    $this->artisan('openlmnp:invite', ['email' => 'gestion@exemple.fr', '--revoke' => true])->assertSuccessful();

    expect($invitation->refresh()->isRevoked())->toBeTrue();

    $this->artisan('openlmnp:invite', ['--list' => true])
        ->expectsOutputToContain('gestion@exemple.fr')
        ->assertSuccessful();
});

// =============================================================================
// Courriel
// =============================================================================

it('le courriel annonce la date d\'expiration et ne lève rien au rendu', function () {
    // ⚠️ `Notification::fake()` n'appelle JAMAIS toMail() : tout ce qui peut échouer au
    // rendu reste hors de portée de la suite. On l'appelle donc nous-mêmes — c'est
    // exactement ce qui avait laissé passer la fatale `MailMessage::to()` du 2026-09-06.
    [$invitation, $plain] = inviter();

    $mail = (new AccountInvitation(
        AccountInvitation::urlFor($plain),
        $invitation->expires_at,
        'Jérémie',
    ))->toMail($invitation);

    $rendu = implode(' ', array_merge($mail->introLines, $mail->outroLines));

    expect($rendu)->toContain('ne sert qu\'une seule fois')
        ->and($rendu)->toContain('Jérémie')
        ->and($mail->actionUrl)->toContain('/invitation/');
});
