<?php

use App\Enums\DossierMemberRole;
use App\Models\LinkedDossierInvitation;
use App\Models\User;
use App\Notifications\LinkedDossierInvitation as LinkedDossierInvitationMail;
use App\Services\LinkedDossierInvitationService;
use App\Support\DossierQuota;
use Illuminate\Support\Facades\Notification;

/**
 * Invitation du second indivisaire (lot 3, plan § 6) : création, relance,
 * révocation, et — surtout — absence de toute fuite sur l'existence d'un
 * compte à l'adresse invitée. `creerDossierLie()` est définie globalement par
 * tests/Feature/Dossiers/LinkedDossierSwitchSecurityTest.php (Pest charge tous
 * les fichiers de test avant d'en exécuter aucun, la fonction est donc
 * disponible ici sans la redéfinir).
 */
beforeEach(function () {
    config(['dossiers.enabled' => true, 'services.provisioning.token' => null, 'dossiers.quota' => null]);
    Notification::fake();
    $this->service = app(LinkedDossierInvitationService::class);
});

it('invite sans consommer d\'offre et envoie le courriel à l\'adresse invitée', function () {
    $owner = User::factory()->create();
    $dossier = creerDossierLie($owner);
    $quotaAvant = DossierQuota::used($owner);

    [$invitation, $plain] = $this->service->create($dossier, 'indivisaire@exemple.fr', $owner);
    $this->service->notify($invitation->refresh(), $plain);

    Notification::assertSentTo($invitation, LinkedDossierInvitationMail::class);
    expect($invitation->routeNotificationForMail())->toBe('indivisaire@exemple.fr')
        // L'invitation ne touche à AUCUNE offre : seule la CRÉATION d'un dossier en
        // consomme une (plan § 6).
        ->and(DossierQuota::used($owner))->toBe($quotaAvant);
});

it('ne stocke jamais le jeton en clair', function () {
    $owner = User::factory()->create();
    $dossier = creerDossierLie($owner);

    [$invitation, $plain] = $this->service->create($dossier, 'indivisaire@exemple.fr', $owner);

    expect(LinkedDossierInvitation::where('token_hash', $plain)->exists())->toBeFalse()
        ->and($invitation->token_hash)->toBe(hash('sha256', $plain))
        ->and($invitation->token_hash)->toHaveLength(64);
});

it('refuse d\'inviter l\'adresse technique d\'un dossier lié (@dossiers.invalid)', function () {
    $owner = User::factory()->create();
    $dossier = creerDossierLie($owner);

    expect(fn () => $this->service->create($dossier, $dossier->user->email, $owner))
        ->toThrow(RuntimeException::class);

    Notification::assertNothingSent();
});

it('ne fuit JAMAIS si l\'adresse invitée correspond à un compte existant', function () {
    // ⚠️ L'ancre anti-énumération : contrairement à InvitationService (réservé aux
    // administrateurs), cette création est ouverte à n'importe quel propriétaire de
    // dossier. Elle doit réussir IDENTIQUEMENT, que l'adresse soit déjà inscrite ou non.
    $owner = User::factory()->create();
    $dossier = creerDossierLie($owner);
    User::factory()->create(['email' => 'connu@exemple.fr']);

    [$connu] = $this->service->create($dossier, 'connu@exemple.fr', $owner);
    [$inconnu] = $this->service->create($dossier, 'inconnu@exemple.fr', $owner);

    expect($connu->email)->toBe('connu@exemple.fr')
        ->and($inconnu->email)->toBe('inconnu@exemple.fr')
        ->and($connu->expires_at->timestamp)->toBeGreaterThan(0)
        ->and($inconnu->expires_at->timestamp)->toBeGreaterThan(0);
});

it('refuse d\'inviter quelqu\'un déjà membre actif du dossier', function () {
    $owner = User::factory()->create();
    $dossier = creerDossierLie($owner);
    $membre = User::factory()->create(['email' => 'deja-membre@exemple.fr']);
    $dossier->members()->create([
        'user_id' => $membre->id,
        'role' => DossierMemberRole::Member,
        'accepted_at' => now(),
    ]);

    expect(fn () => $this->service->create($dossier, 'deja-membre@exemple.fr', $owner))
        ->toThrow(RuntimeException::class, 'déjà membre');
});

it('refuse une deuxième invitation vivante pour la même adresse, accepte après révocation', function () {
    $owner = User::factory()->create();
    $dossier = creerDossierLie($owner);

    [$premiere] = $this->service->create($dossier, 'indivisaire@exemple.fr', $owner);

    expect(fn () => $this->service->create($dossier, 'indivisaire@exemple.fr', $owner))
        ->toThrow(RuntimeException::class, 'déjà en attente');

    $this->service->revoke($premiere, $owner);

    expect(fn () => $this->service->create($dossier, 'indivisaire@exemple.fr', $owner))
        ->not->toThrow(RuntimeException::class);
});

it('seul le responsable actuel du dossier peut inviter', function () {
    $owner = User::factory()->create();
    $dossier = creerDossierLie($owner);
    $etranger = User::factory()->create();

    expect(fn () => $this->service->create($dossier, 'indivisaire@exemple.fr', $etranger))
        ->toThrow(RuntimeException::class, 'responsable actuel');
});

it('un membre actif peut inviter dès que le créateur suspendu n\'est plus le responsable actuel', function () {
    $owner = User::factory()->create(['suspended_at' => now()]);
    $dossier = creerDossierLie($owner);
    $membre = User::factory()->create();
    $dossier->members()->create([
        'user_id' => $membre->id,
        'role' => DossierMemberRole::Member,
        'accepted_at' => now()->subDay(),
    ]);

    expect($dossier->actingOwner()->id)->toBe($membre->id);

    [$invitation] = $this->service->create($dossier, 'indivisaire@exemple.fr', $membre);

    expect($invitation->email)->toBe('indivisaire@exemple.fr');
});

it('fige l\'expiration à la création', function () {
    config(['dossiers.invitation_ttl_days' => 3]);
    $owner = User::factory()->create();
    $dossier = creerDossierLie($owner);

    [$invitation] = $this->service->create($dossier, 'indivisaire@exemple.fr', $owner);
    $avant = $invitation->expires_at;

    config(['dossiers.invitation_ttl_days' => 90]);

    expect($invitation->refresh()->expires_at->timestamp)->toBe($avant->timestamp);
});

it('la relance régénère le jeton et tue le précédent', function () {
    $owner = User::factory()->create();
    $dossier = creerDossierLie($owner);
    [$invitation, $ancien] = $this->service->create($dossier, 'indivisaire@exemple.fr', $owner);

    $nouveau = $this->service->resend($invitation, $owner);

    expect($nouveau)->not->toBe($ancien)
        ->and($this->service->resolve($ancien))->toBeNull()
        ->and($this->service->resolve($nouveau)?->id)->toBe($invitation->id);
});

it('refuse de relancer ou de révoquer une invitation déjà acceptée', function () {
    $owner = User::factory()->create();
    $dossier = creerDossierLie($owner);
    [$invitation] = $this->service->create($dossier, 'indivisaire@exemple.fr', $owner);
    $invitation->update(['accepted_at' => now()]);

    expect(fn () => $this->service->resend($invitation, $owner))
        ->toThrow(RuntimeException::class, 'déjà été acceptée')
        ->and(fn () => $this->service->revoke($invitation, $owner))
        ->toThrow(RuntimeException::class, 'déjà été acceptée');
});

it('seul le responsable actuel peut relancer ou révoquer', function () {
    $owner = User::factory()->create();
    $dossier = creerDossierLie($owner);
    [$invitation] = $this->service->create($dossier, 'indivisaire@exemple.fr', $owner);
    $etranger = User::factory()->create();

    expect(fn () => $this->service->resend($invitation, $etranger))->toThrow(RuntimeException::class)
        ->and(fn () => $this->service->revoke($invitation, $etranger))->toThrow(RuntimeException::class);
});

it('le courriel annonce le dossier et l\'expiration, et ne lève rien au rendu', function () {
    // ⚠️ `Notification::fake()` n'appelle jamais `toMail()` : on l'appelle nous-mêmes,
    // même patron que tests/Feature/InvitationTest.php (incident du 2026-09-06).
    $owner = User::factory()->create();
    $dossier = creerDossierLie($owner);
    [$invitation, $plain] = $this->service->create($dossier, 'indivisaire@exemple.fr', $owner);

    $mail = (new LinkedDossierInvitationMail(
        LinkedDossierInvitationMail::urlFor($plain),
        $invitation->expires_at,
        $owner->name,
        $dossier->user->name,
        $dossier->siren,
    ))->toMail($invitation);

    $rendu = implode(' ', array_merge($mail->introLines, $mail->outroLines));

    expect($rendu)->toContain($dossier->user->name)
        ->and($rendu)->toContain($owner->name)
        ->and($mail->actionUrl)->toContain('/dossiers/invitation/')
        // Rendu réel (écran 7, variante B) : l'encadré nomme le dossier, le SIREN et
        // l'expéditeur — vérifié sur le HTML produit, pas seulement sur les lignes
        // `introLines`/`outroLines` qui ne couvrent pas l'encadré lui-même.
        ->and((string) $mail->render())->toContain($dossier->user->name)
        ->and((string) $mail->render())->toContain($dossier->siren)
        ->and((string) $mail->render())->toContain($owner->name);
});
