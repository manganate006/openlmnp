<?php

use App\Enums\DossierMemberRole;
use App\Models\DossierEvent;
use App\Models\LinkedDossierMember;
use App\Models\User;
use App\Services\LinkedDossierInvitationService;
use App\Support\DossierContext;
use App\Support\DossierQuota;
use Filament\Auth\Http\Responses\LoginResponse;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * Acceptation d'une invitation à un dossier lié : le lien `/dossiers/invitation/{token}`
 * (App\Http\Controllers\Dossier\AcceptDossierInvitationController). Même patron de
 * vérification que tests/Feature/InvitationTest.php pour les états du jeton.
 * `creerDossierLie()` : voir LinkedDossierSwitchSecurityTest.php (fonction globale Pest).
 */
beforeEach(function () {
    config(['dossiers.enabled' => true, 'services.provisioning.token' => null, 'dossiers.quota' => null]);
    RateLimiter::clear('dossier.invitation.show');
    $this->service = app(LinkedDossierInvitationService::class);
});

function inviterIndivisaire(string $email = 'indivisaire@exemple.fr'): array
{
    $owner = User::factory()->create();
    $dossier = creerDossierLie($owner);

    [$invitation, $plain] = app(LinkedDossierInvitationService::class)->create($dossier, $email, $owner);

    return [$dossier, $owner, $invitation, $plain];
}

it('404 quand la fonctionnalité est désactivée', function () {
    config(['dossiers.enabled' => false]);
    [, , , $plain] = inviterIndivisaire();

    $this->get(route('dossier.invitation.show', ['token' => $plain]))->assertNotFound();
});

it('refuse un jeton inconnu', function () {
    $this->get(route('dossier.invitation.show', ['token' => Str::random(48)]))
        ->assertStatus(410)
        ->assertSee('n\'est plus valable', false);
});

it('traite une invitation révoquée EXACTEMENT comme un jeton inconnu', function () {
    [, $owner, $invitation, $plain] = inviterIndivisaire();
    $this->service->revoke($invitation, $owner);

    $revoquee = $this->get(route('dossier.invitation.show', ['token' => $plain]));
    RateLimiter::clear('dossier.invitation.show');
    $inconnue = $this->get(route('dossier.invitation.show', ['token' => Str::random(48)]));

    expect($revoquee->getContent())->toBe($inconnue->getContent())
        ->and($revoquee->status())->toBe($inconnue->status());
});

it('annonce une expiration avec un message distinct', function () {
    [, , $invitation, $plain] = inviterIndivisaire();
    $invitation->update(['expires_at' => now()->subDay()]);

    $this->get(route('dossier.invitation.show', ['token' => $plain]))
        ->assertStatus(410)
        ->assertSee('a expiré', false);
});

it('annonce qu\'elle a déjà servi', function () {
    [, , $invitation, $plain] = inviterIndivisaire();
    $invitation->update(['accepted_at' => now()]);

    $this->get(route('dossier.invitation.show', ['token' => $plain]))
        ->assertStatus(410)
        ->assertSee('déjà', false);
});

it('pas connecté : montre la page d\'invitation et mémorise l\'intention de retour', function () {
    [$dossier, , , $plain] = inviterIndivisaire();

    $url = route('dossier.invitation.show', ['token' => $plain]);

    $this->get($url)
        ->assertOk()
        ->assertSee($dossier->user->name, false)
        ->assertSessionHas('url.intended', $url);
});

it('n\'affiche jamais « inscription fermée » : le compte membre gratuit fonctionne toujours', function () {
    // Défaut gênant n°3 du test de bout en bout (2026-10-15) : la page affichait
    // « inscription fermée, rendez-vous sur openlmnp.fr » juste au-dessus du
    // formulaire de compte membre gratuit — qui, lui, fonctionne TOUJOURS pour une
    // invitation valide (il contourne RegistrationGate). Le message n'a plus sa place.
    config(['app.allow_registration' => false]);
    [, , , $plain] = inviterIndivisaire();

    $this->get(route('dossier.invitation.show', ['token' => $plain]))
        ->assertOk()
        ->assertDontSee('inscription libre est fermée', false)
        ->assertSee('Créez un accès gratuit d\'indivisaire', false);
});

it('la connexion ramène automatiquement sur le lien (redirect()->intended partagé avec Filament)', function () {
    // ⚠️ On ne rejoue pas le formulaire Livewire de connexion lui-même (hors
    // périmètre) : on vérifie le CÂBLAGE exact dont dépend le parcours « pas
    // connecté → connexion → retour » — Filament\Auth\Http\Responses\LoginResponse
    // fait TOUJOURS `redirect()->intended(Filament::getUrl())`, et c'est la MÊME
    // clé de session (`url.intended`) que celle posée par le contrôleur.
    [, , , $plain] = inviterIndivisaire();
    $url = route('dossier.invitation.show', ['token' => $plain]);

    $this->get($url);
    expect(session('url.intended'))->toBe($url);

    $reponse = (new LoginResponse)->toResponse(request());

    expect($reponse->getTargetUrl())->toBe($url);
});

it('connecté avec la mauvaise adresse : explique sans rien modifier', function () {
    [, , , $plain] = inviterIndivisaire();
    $autre = User::factory()->create();

    $this->actingAs($autre)
        ->get(route('dossier.invitation.show', ['token' => $plain]))
        ->assertStatus(409)
        ->assertSee($autre->email, false);

    expect(LinkedDossierMember::where('user_id', $autre->id)->exists())->toBeFalse();
});

it('refuse un compte de démonstration', function () {
    [, , $invitation, $plain] = inviterIndivisaire('demo@exemple.fr');
    $demo = User::factory()->create(['email' => 'demo@exemple.fr', 'is_demo' => true]);

    $this->actingAs($demo)
        ->get(route('dossier.invitation.show', ['token' => $plain]))
        ->assertStatus(410);

    expect($invitation->refresh()->isAccepted())->toBeFalse();
});

it('refuse un compte suspendu', function () {
    [, , $invitation, $plain] = inviterIndivisaire('suspendu@exemple.fr');
    $suspendu = User::factory()->create(['email' => 'suspendu@exemple.fr', 'suspended_at' => now()]);

    $this->actingAs($suspendu)
        ->get(route('dossier.invitation.show', ['token' => $plain]))
        ->assertStatus(410);

    expect($invitation->refresh()->isAccepted())->toBeFalse();
});

it('refuse un dossier lié lui-même, en défense en profondeur', function () {
    // Un dossier lié ne porte JAMAIS cette adresse dans la réalité (la sienne est
    // toujours @dossiers.invalid, et App\Services\LinkedDossierInvitationService
    // refuse même de l'inviter) : on la force ici pour isoler CETTE garde
    // (`is_linked_dossier`) de la garde « mauvaise adresse », qui la précéderait sinon.
    [, , $invitation, $plain] = inviterIndivisaire('indivisaire@exemple.fr');

    $autreOwner = User::factory()->create();
    $autreDossier = creerDossierLie($autreOwner, '100000025');
    $autreDossier->user->forceFill(['email' => 'indivisaire@exemple.fr'])->save();

    $this->actingAs($autreDossier->user)
        ->get(route('dossier.invitation.show', ['token' => $plain]))
        ->assertStatus(410);

    expect($invitation->refresh()->isAccepted())->toBeFalse();
});

it('connecté avec la bonne adresse : montre une confirmation, SANS rien rattacher (GET)', function () {
    // Contre-épreuve du changement de comportement du lot 3 (écran 7, variante B,
    // décision du 2026-10-15) : un simple GET, même répété, ne doit JAMAIS rattacher
    // personne — seule la confirmation explicite (POST, test suivant) le fait.
    [$dossier, , $invitation, $plain] = inviterIndivisaire();
    $invite = User::factory()->create(['email' => 'indivisaire@exemple.fr']);

    $url = route('dossier.invitation.show', ['token' => $plain]);

    foreach (range(1, 3) as $i) {
        $this->actingAs($invite)
            ->get($url)
            ->assertOk()
            ->assertSee('Rejoindre le dossier', false)
            ->assertSee($dossier->user->name, false)
            ->assertSee((string) $dossier->siren, false);
    }

    expect($invitation->refresh()->isAccepted())->toBeFalse()
        ->and(\App\Models\LinkedDossierMember::where('user_id', $invite->id)->exists())->toBeFalse();
});

it('accepte : devient membre sans consommer d\'offre, avec l\'audit et l\'événement analytics', function () {
    [$dossier, $owner, $invitation, $plain] = inviterIndivisaire();
    $invite = User::factory()->create(['email' => 'indivisaire@exemple.fr']);
    $quotaAvant = DossierQuota::used($invite);

    $this->actingAs($invite)
        ->post(route('dossier.invitation.confirm', ['token' => $plain]))
        ->assertOk()
        ->assertSee('Vous avez rejoint', false)
        ->assertSee($dossier->user->name, false);

    $membership = $dossier->members()->where('user_id', $invite->id)->first();

    expect($invitation->refresh()->isAccepted())->toBeTrue()
        ->and($membership)->not->toBeNull()
        ->and($membership->role)->toBe(DossierMemberRole::Member)
        ->and($membership->revoked_at)->toBeNull()
        ->and(DossierQuota::used($invite))->toBe($quotaAvant)
        ->and(DossierEvent::where('linked_dossier_id', $dossier->id)
            ->where('type', DossierEvent::TYPE_MEMBER_ACCEPTED)
            ->where('user_id', $invite->id)
            ->exists())->toBeTrue()
        ->and(collect((array) session('analytics'))->firstWhere('event', 'linked_dossier_joined'))->not->toBeNull();

    // Le nouveau membre peut désormais basculer vers le dossier.
    DossierContext::switchTo($dossier->fresh());
    expect(\Illuminate\Support\Facades\Auth::user()->id)->toBe($dossier->user_id);
});

it('refuse explicitement : ferme le lien sans rattacher personne', function () {
    [$dossier, , $invitation, $plain] = inviterIndivisaire();
    $invite = User::factory()->create(['email' => 'indivisaire@exemple.fr']);

    $this->actingAs($invite)
        ->post(route('dossier.invitation.decline', ['token' => $plain]))
        ->assertOk()
        ->assertSee('refusée', false);

    expect($invitation->refresh()->isAccepted())->toBeFalse()
        // Défaut gênant n°6 (2026-10-15) : un refus de l'INVITÉ écrit `declined_at`,
        // PAS `revoked_at` (réservé à une révocation PAR le propriétaire du dossier).
        ->and($invitation->fresh()->isDeclined())->toBeTrue()
        ->and($invitation->fresh()->isRevoked())->toBeFalse()
        ->and($dossier->members()->where('user_id', $invite->id)->exists())->toBeFalse();

    // Impossible de confirmer après coup : même état qu'une révocation par l'invitant.
    RateLimiter::clear('dossier.invitation.show');
    $this->actingAs($invite)
        ->post(route('dossier.invitation.confirm', ['token' => $plain]))
        ->assertStatus(410);
});

it('ne consomme le lien qu\'une seule fois : une seconde confirmation échoue', function () {
    [, , $invitation, $plain] = inviterIndivisaire();
    $invite = User::factory()->create(['email' => 'indivisaire@exemple.fr']);

    $this->actingAs($invite)
        ->post(route('dossier.invitation.confirm', ['token' => $plain]))
        ->assertOk();

    RateLimiter::clear('dossier.invitation.show');

    $this->actingAs($invite)
        ->post(route('dossier.invitation.confirm', ['token' => $plain]))
        ->assertStatus(410);

    expect($invitation->refresh()->accepted_at)->not->toBeNull();
});

it('mauvais compte : un seul bouton POST qui déconnecte puis ramène sur le lien', function () {
    [, , , $plain] = inviterIndivisaire();
    $autre = User::factory()->create();

    $reponse = $this->actingAs($autre)
        ->post(route('dossier.invitation.switch-account', ['token' => $plain]));

    $reponse->assertRedirect(route('dossier.invitation.show', ['token' => $plain]));
    expect(\Illuminate\Support\Facades\Auth::check())->toBeFalse();
});

it('réactive la même ligne de membre après un départ puis une nouvelle invitation', function () {
    [$dossier, $owner] = inviterIndivisaire();
    $invite = User::factory()->create(['email' => 'indivisaire@exemple.fr']);

    [, $plain1] = app(LinkedDossierInvitationService::class)->create($dossier, 'reinvite@exemple.fr', $owner);
    $invite2 = User::factory()->create(['email' => 'reinvite@exemple.fr']);
    $this->actingAs($invite2)->post(route('dossier.invitation.confirm', ['token' => $plain1]));
    $ancienneLigne = $dossier->members()->where('user_id', $invite2->id)->first();

    app(\App\Services\LinkedDossierService::class)->leave($dossier, $invite2);

    [, $plain2] = app(LinkedDossierInvitationService::class)->create($dossier, 'reinvite@exemple.fr', $owner);
    RateLimiter::clear('dossier.invitation.show');
    $this->actingAs($invite2)->post(route('dossier.invitation.confirm', ['token' => $plain2]))->assertOk();

    $nouvelleLigne = $dossier->members()->where('user_id', $invite2->id)->first();

    expect($nouvelleLigne->id)->toBe($ancienneLigne->id)
        ->and($nouvelleLigne->revoked_at)->toBeNull();
});

it('limite les tentatives par minute', function () {
    foreach (range(1, 10) as $i) {
        $this->get(route('dossier.invitation.show', ['token' => Str::random(48)]));
    }

    $this->get(route('dossier.invitation.show', ['token' => Str::random(48)]))->assertStatus(429);
});
