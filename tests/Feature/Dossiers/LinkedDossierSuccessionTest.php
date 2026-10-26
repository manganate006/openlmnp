<?php

use App\Enums\DossierMemberRole;
use App\Models\User;
use App\Services\LinkedDossierService;
use App\Support\DossierContext;
use Illuminate\Support\Facades\Auth;

/**
 * Règles de résiliation (plan § 10 décision n°5, lot 3) :
 *   - chacun garde l'accès tant que SON PROPRE compte est actif ;
 *   - si le créateur est suspendu ou part, l'administration passe à un membre
 *     actif — SANS nouvel état à synchroniser (App\Models\LinkedDossier::
 *     actingOwner(), recalculée à chaque appel, jamais persistée) ;
 *   - sans membre actif, le dossier est conservé mais fermé, jamais supprimé.
 *
 * `creerDossierLie()` : fonction globale Pest, voir LinkedDossierSwitchSecurityTest.php.
 */
beforeEach(function () {
    config(['dossiers.enabled' => true, 'services.provisioning.token' => null, 'dossiers.quota' => null]);
});

it('chacun garde l\'accès tant que SON PROPRE compte est actif, même créateur suspendu', function () {
    $owner = User::factory()->create();
    $dossier = creerDossierLie($owner);
    $membre = User::factory()->create();
    $dossier->members()->create(['user_id' => $membre->id, 'role' => DossierMemberRole::Member, 'accepted_at' => now()]);

    $owner->forceFill(['suspended_at' => now()])->save();

    // DossierContext::switchTo() ne regarde QUE l'acteur lui-même — jamais le
    // créateur du dossier : aucun code à ajouter ici, seulement à vérifier.
    $this->actingAs($membre);
    $this->post(route('filament.admin.dossiers.open', $dossier->public_id))->assertRedirect('/');

    expect(Auth::user()->id)->toBe($dossier->user_id);
});

it('actingOwner() délègue à un membre actif quand le titulaire du rôle Owner est suspendu', function () {
    $owner = User::factory()->create();
    $dossier = creerDossierLie($owner);
    $ancien = User::factory()->create();
    $recent = User::factory()->create();
    $dossier->members()->create(['user_id' => $ancien->id, 'role' => DossierMemberRole::Member, 'accepted_at' => now()->subDays(2)]);
    $dossier->members()->create(['user_id' => $recent->id, 'role' => DossierMemberRole::Member, 'accepted_at' => now()->subDay()]);

    expect($dossier->actingOwner()->id)->toBe($owner->id)
        ->and($dossier->isClosed())->toBeFalse();

    $owner->forceFill(['suspended_at' => now()])->save();

    // Le plus ANCIEN membre accepté parmi les actifs, pas un ordre arbitraire.
    expect($dossier->actingOwner()->id)->toBe($ancien->id);
});

it('actingOwner() délègue aussi quand le titulaire devient un compte de démonstration', function () {
    $owner = User::factory()->create();
    $dossier = creerDossierLie($owner);
    $membre = User::factory()->create();
    $dossier->members()->create(['user_id' => $membre->id, 'role' => DossierMemberRole::Member, 'accepted_at' => now()]);

    $owner->forceFill(['is_demo' => true])->save();

    expect($dossier->actingOwner()->id)->toBe($membre->id);
});

it('isClosed() est vrai sans aucun membre utilisable, et le dossier n\'est jamais supprimé', function () {
    $owner = User::factory()->create();
    $dossier = creerDossierLie($owner);

    $owner->forceFill(['suspended_at' => now()])->save();

    expect($dossier->actingOwner())->toBeNull()
        ->and($dossier->isClosed())->toBeTrue()
        ->and(\App\Models\LinkedDossier::find($dossier->id))->not->toBeNull();
});

it('quitter en tant que créateur laisse l\'administration à un membre actif, sans rien supprimer', function () {
    $owner = User::factory()->create();
    $dossier = creerDossierLie($owner);
    $membre = User::factory()->create();
    $dossier->members()->create(['user_id' => $membre->id, 'role' => DossierMemberRole::Member, 'accepted_at' => now()]);

    app(LinkedDossierService::class)->leave($dossier, $owner);

    expect($dossier->actingOwner()->id)->toBe($membre->id)
        ->and(\App\Models\LinkedDossier::find($dossier->id))->not->toBeNull()
        ->and($dossier->fresh()->isArchived())->toBeFalse();

    // Le créateur a bien perdu l'accès (sa propre ligne est révoquée) ; `owner_id`
    // (trace historique de qui a créé le dossier — décision n°5) n'est volontairement
    // PAS réécrit : voir App\Models\LinkedDossier::actingOwner().
    expect($dossier->fresh()->owner_id)->toBe($owner->id);

    $this->actingAs($owner)
        ->post(route('filament.admin.dossiers.open', $dossier->public_id))
        ->assertNotFound();
});

it('sans membre actif après un départ, le dossier reste conservé mais fermé', function () {
    $owner = User::factory()->create();
    $dossier = creerDossierLie($owner);

    app(LinkedDossierService::class)->leave($dossier, $owner);

    expect($dossier->fresh()->isClosed())->toBeTrue()
        ->and(\App\Models\LinkedDossier::find($dossier->id))->not->toBeNull();
});

it('quitter n\'importe quel rôle est permis, y compris pour le créateur (contrairement à la révocation par autrui)', function () {
    $owner = User::factory()->create();
    $dossier = creerDossierLie($owner);

    // Rappel du contraste : revokeMember() refuse explicitement de révoquer le
    // titulaire du rôle Owner (voir App\Services\LinkedDossierService::revokeMember()).
    expect(fn () => app(LinkedDossierService::class)->revokeMember($dossier, $owner, $owner))
        ->toThrow(RuntimeException::class);

    // ... mais partir de soi-même reste toujours permis.
    app(LinkedDossierService::class)->leave($dossier, $owner);

    expect($dossier->members()->where('user_id', $owner->id)->first()->revoked_at)->not->toBeNull();
});
