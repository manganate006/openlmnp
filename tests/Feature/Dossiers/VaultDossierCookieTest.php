<?php

use App\Models\User;
use App\Services\Vault\VaultKeyManager;
use App\Support\VaultSession;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

/**
 * Le piège signalé par le plan (§ 2.3, § 10) : « changer de dossier reverrouille
 * aujourd'hui le coffre ». Un seul nom de cookie partagé par toute l'instance ne
 * pouvait porter qu'UNE clé déverrouillée à la fois par navigateur — or un
 * dossier lié s'authentifie dans la MÊME session navigateur que son principal
 * (bascule, jamais une seconde session). Corrigé par App\Support\VaultSession::
 * cookieNameFor() (un cookie PAR utilisateur). `vaultCookie()`/`encryptedDocument()` :
 * fonctions globales Pest définies par tests/Feature/VaultDocumentServingTest.php.
 */
beforeEach(function () {
    config(['dossiers.enabled' => true, 'services.provisioning.token' => null, 'dossiers.quota' => null]);
    Storage::fake('local');
});

it('donne un nom de cookie distinct par utilisateur', function () {
    $a = User::factory()->create();
    $b = User::factory()->create();
    $session = app(VaultSession::class);

    expect($session->cookieNameFor($a))->not->toBe($session->cookieNameFor($b));
});

it('verrouiller le coffre d\'un utilisateur ne touche pas celui d\'un autre actif dans la même requête', function () {
    $principal = User::factory()->create();
    [, $principalDek] = app(VaultKeyManager::class)->create($principal, 'une phrase de passe du principal');

    $dossierUser = User::factory()->create(['is_linked_dossier' => true]);
    [, $dossierDek] = app(VaultKeyManager::class)->create($dossierUser, 'une phrase de passe du dossier');

    $session = app(VaultSession::class);
    $session->unlock($principal, $principalDek, 1);
    $session->unlock($dossierUser, $dossierDek, 1);

    $session->lock($dossierUser);

    expect($session->isUnlockedFor($dossierUser))->toBeFalse()
        ->and($session->isUnlockedFor($principal))->toBeTrue();
});

it('un cookie par utilisateur : les clés du principal et du dossier coexistent dans la même requête', function () {
    // ⚠️ CONTRE-ÉPREUVE DE CE TEST (faite à la main, pas committée) : revenir à un
    // nom de cookie unique (cookieName() sans suffixe d'utilisateur) fait échouer
    // CE test précisément — le second cookie posé écrase le premier sous le même
    // nom, et la lecture du document du dossier échoue (423).
    //
    // Bascule RÉELLE (DossierContext::switchTo() via la route HTTP), pour que la
    // session porte le `dossier.principal_id` qu'EnsureDossierContext exige — sans
    // quoi la seconde requête, authentifiée comme le dossier, serait refusée AVANT
    // même d'atteindre le coffre, pour une tout autre raison que celle testée ici.
    $principal = User::factory()->create();
    [, $principalDek] = app(VaultKeyManager::class)->create($principal, 'une phrase de passe du principal');
    $urlPrincipal = encryptedDocument($principal, $principalDek, '%PDF-1.4 justificatif du principal');

    $dossier = creerDossierLie($principal);
    $dossierUser = $dossier->user;
    [, $dossierDek] = app(VaultKeyManager::class)->create($dossierUser, 'une phrase de passe du dossier');
    $urlDossier = encryptedDocument($dossierUser, $dossierDek, '%PDF-1.4 justificatif du dossier');

    // Les DEUX cookies, envoyés ENSEMBLE — exactement ce qu'un navigateur fait une
    // fois que les deux identités ont, l'une après l'autre, déverrouillé leur coffre
    // dans la même session (bascule de dossier, jamais une seconde session).
    $cookies = array_merge(
        vaultCookie($principal, $principalDek),
        vaultCookie($dossierUser, $dossierDek),
    );

    $this->actingAs($principal)->withCookies($cookies)->get($urlPrincipal)
        ->assertOk();

    $this->post(route('filament.admin.dossiers.open', $dossier->public_id))->assertRedirect('/');

    $this->withCookies($cookies)->get($urlDossier)
        ->assertOk();
});

it('revenir au principal après une bascule ne retrouve pas son coffre reverrouillé', function () {
    // Reproduction du scénario exact du plan : le principal déverrouille SON
    // coffre, bascule vers le dossier, déverrouille CELUI du dossier, puis revient.
    // Son propre cookie (nom distinct) n'a jamais été touché entre-temps.
    $principal = User::factory()->create();
    [, $principalDek] = app(VaultKeyManager::class)->create($principal, 'une phrase de passe du principal');

    $session = app(VaultSession::class);
    $session->unlock($principal, $principalDek, 1);
    expect($session->isUnlockedFor($principal))->toBeTrue();

    $dossierUser = User::factory()->create(['is_linked_dossier' => true]);
    [, $dossierDek] = app(VaultKeyManager::class)->create($dossierUser, 'une phrase de passe du dossier');
    $session->unlock($dossierUser, $dossierDek, 1);

    expect($session->isUnlockedFor($principal))->toBeTrue()
        ->and($session->isUnlockedFor($dossierUser))->toBeTrue();
});

it('la déconnexion verrouille le coffre de la bonne identité (event->user, pas un Auth::user() déjà vidé)', function () {
    $user = User::factory()->create();
    [, $dek] = app(VaultKeyManager::class)->create($user, 'une phrase de passe quelconque');

    $this->actingAs($user);
    app(VaultSession::class)->unlock($user, $dek, 1);
    expect(app(VaultSession::class)->isUnlockedFor($user))->toBeTrue();

    Auth::logout();

    expect(app(VaultSession::class)->isUnlockedFor($user))->toBeFalse();
});
