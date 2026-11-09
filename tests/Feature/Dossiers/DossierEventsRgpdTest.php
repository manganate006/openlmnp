<?php

use App\Enums\DossierMemberRole;
use App\Models\DossierEvent;
use App\Models\LinkedDossier;
use App\Models\LinkedDossierInvitation;
use App\Models\User;
use App\Services\AccountDeletionService;
use App\Services\LinkedDossierInvitationService;
use App\Services\LinkedDossierService;
use App\Support\DossierEventScrubber;
use Illuminate\Support\Facades\DB;

/**
 * Journal des dossiers liés et droit à l'effacement (RGPD, art. 17). Constat de la
 * bêta du 2026-10-17 : après `openlmnp:account-delete`, `dossier_events` gardait les
 * lignes d'un dossier supprimé, avec l'adresse de l'invité et le SIREN dans `meta`.
 * `creerDossierLie()` : fonction globale Pest, voir LinkedDossierSwitchSecurityTest.php.
 */
beforeEach(function () {
    config(['dossiers.enabled' => true, 'services.provisioning.token' => null, 'dossiers.quota' => null]);
});

/** Compte ordinaire, jamais promu administrateur par « premier compte = admin ». */
function compteRgpd(array $attributs = []): User
{
    $user = User::factory()->create($attributs);

    if ($user->is_admin) {
        $user->forceFill(['is_admin' => false])->save();
    }

    return $user;
}

/** Tout le texte brut du journal et des invitations, pour y chercher une adresse. */
function texteJournalDossiers(): string
{
    return DB::table('dossier_events')->get()->toJson()
        .DB::table('linked_dossier_invitations')->get()->toJson();
}

it('supprime tout le journal d\'un dossier supprimé avec son unique membre, invitation sans compte comprise', function () {
    $owner = compteRgpd(['email' => 'seul.membre@exemple.fr']);
    $dossier = creerDossierLie($owner, '100000009');

    DossierEvent::record($dossier->id, DossierEvent::TYPE_CREATED, $owner, ['siren' => '100000009']);
    app(LinkedDossierInvitationService::class)->create($dossier, 'jamais.inscrit@exemple.fr', $owner);
    DossierEvent::record($dossier->id, DossierEvent::TYPE_SWITCHED, $owner);

    expect(DossierEvent::where('linked_dossier_id', $dossier->id)->count())->toBe(3);

    app(AccountDeletionService::class)->delete($owner);

    expect(LinkedDossier::find($dossier->id))->toBeNull()
        ->and(DossierEvent::where('linked_dossier_id', $dossier->id)->count())->toBe(0)
        ->and(texteJournalDossiers())->not->toContain('jamais.inscrit@exemple.fr')
        ->and(texteJournalDossiers())->not->toContain('seul.membre@exemple.fr')
        ->and(texteJournalDossiers())->not->toContain('100000009');
});

it('expurge l\'adresse d\'un membre supprimé du journal d\'un dossier partagé qui lui survit, en gardant l\'action et la date', function () {
    $owner = compteRgpd();
    $dossier = creerDossierLie($owner, '100000009');
    $invitations = app(LinkedDossierInvitationService::class);

    $partant = compteRgpd(['email' => 'partant@exemple.fr', 'name' => 'Paul Partant']);
    [$invitation] = $invitations->create($dossier, 'partant@exemple.fr', $owner);
    $invitations->accept($invitation, $partant);

    $invite = DossierEvent::where('linked_dossier_id', $dossier->id)->where('type', DossierEvent::TYPE_MEMBER_INVITED)->sole();
    $dateInvitation = $invite->created_at->toDateTimeString();

    // Le partant doit rester seul à partir : il faut un second membre actif, sinon le
    // dossier ne survivrait pas. Ici le responsable reste.
    app(AccountDeletionService::class)->delete($partant->fresh());

    $invite->refresh();

    expect(LinkedDossier::find($dossier->id))->not->toBeNull()
        ->and($invite->type)->toBe(DossierEvent::TYPE_MEMBER_INVITED)
        ->and($invite->created_at->toDateTimeString())->toBe($dateInvitation)
        ->and($invite->meta)->toBe(DossierEventScrubber::REDACTED)
        ->and(DossierEvent::where('linked_dossier_id', $dossier->id)->where('type', DossierEvent::TYPE_MEMBER_ACCEPTED)->sole()->user_id)->toBeNull()
        ->and(LinkedDossierInvitation::find($invitation->id))->toBeNull()
        ->and(mb_strtolower(texteJournalDossiers()))->not->toContain('partant@exemple.fr')
        ->and(texteJournalDossiers())->not->toContain('Paul Partant');
});

it('expurge l\'identifiant d\'un membre révoqué puis supprimé, sans toucher les événements des autres', function () {
    $owner = compteRgpd();
    $dossier = creerDossierLie($owner, '100000009');
    $resteMembre = compteRgpd(['email' => 'reste@exemple.fr']);
    $partant = compteRgpd();

    foreach ([$resteMembre, $partant] as $membre) {
        $dossier->members()->create(['user_id' => $membre->id, 'role' => DossierMemberRole::Member, 'accepted_at' => now()]);
    }

    DossierEvent::record($dossier->id, DossierEvent::TYPE_CREATED, $owner, ['siren' => '100000009']);
    DossierEvent::record($dossier->id, DossierEvent::TYPE_MEMBER_INVITED, $owner, ['email' => 'reste@exemple.fr']);
    app(LinkedDossierService::class)->revokeMember($dossier, $owner, $partant);

    $revocation = DossierEvent::where('type', DossierEvent::TYPE_MEMBER_REVOKED)->sole();
    expect($revocation->meta)->toBe(['member_id' => $partant->id]);

    app(AccountDeletionService::class)->delete($partant->fresh());

    expect($revocation->fresh()->meta)->toBe(DossierEventScrubber::REDACTED)
        ->and(DossierEvent::where('type', DossierEvent::TYPE_CREATED)->sole()->meta)->toBe(['siren' => '100000009'])
        ->and(DossierEvent::where('type', DossierEvent::TYPE_MEMBER_INVITED)->sole()->meta)->toBe(['email' => 'reste@exemple.fr']);
});

it('openlmnp:demo-cleanup expurge aussi le journal (même purge que la suppression de compte)', function () {
    $owner = compteRgpd();
    $dossier = creerDossierLie($owner, '100000009');
    $demo = compteRgpd(['email' => 'demo-rgpd@demo.local', 'is_demo' => true, 'demo_expires_at' => now()->subHour()]);

    DossierEvent::record($dossier->id, DossierEvent::TYPE_MEMBER_INVITED, $owner, ['email' => 'demo-rgpd@demo.local']);

    $this->artisan('openlmnp:demo-cleanup')->assertSuccessful();

    expect(User::find($demo->id))->toBeNull()
        ->and(texteJournalDossiers())->not->toContain('demo-rgpd@demo.local');
});

// === Reliquats déjà en base (avant le correctif) ===

/**
 * État laissé par l'ancienne suppression : `User::delete()` seul, sans le correctif.
 *
 * @return array{survivant: LinkedDossier, pending: LinkedDossierInvitation}
 */
function reliquatsAvantCorrectif(): array
{
    $owner = compteRgpd();
    $survivant = creerDossierLie($owner, '100000009');
    $supprime = creerDossierLie($owner, '200000008');

    DossierEvent::record($supprime->id, DossierEvent::TYPE_CREATED, $owner, ['siren' => '200000008']);
    DossierEvent::record($supprime->id, DossierEvent::TYPE_MEMBER_INVITED, $owner, ['email' => 'invite.orphelin@exemple.fr']);

    $parti = compteRgpd(['email' => 'parti@exemple.fr']);
    DossierEvent::record($survivant->id, DossierEvent::TYPE_CREATED, $owner, ['siren' => '100000009']);
    DossierEvent::record($survivant->id, DossierEvent::TYPE_MEMBER_INVITED, $owner, ['email' => 'parti@exemple.fr']);
    DossierEvent::record($survivant->id, DossierEvent::TYPE_MEMBER_REVOKED, $owner, ['member_id' => $parti->id]);
    LinkedDossierInvitation::create([
        'linked_dossier_id' => $survivant->id,
        'email' => 'parti@exemple.fr',
        'token_hash' => hash('sha256', 'jeton-parti'),
        'expires_at' => now()->addDays(7),
        'accepted_at' => now(),
    ]);

    // Invitation en attente vers une adresse sans compte : légitime, à garder.
    DossierEvent::record($survivant->id, DossierEvent::TYPE_MEMBER_INVITED, $owner, ['email' => 'en.attente@exemple.fr']);
    $pending = LinkedDossierInvitation::create([
        'linked_dossier_id' => $survivant->id,
        'email' => 'en.attente@exemple.fr',
        'token_hash' => hash('sha256', 'jeton-attente'),
        'expires_at' => now()->addDays(7),
    ]);

    // Ancienne suppression : le compte et le dossier partent, le journal reste.
    DB::table('users')->where('id', $parti->id)->delete();
    DB::table('users')->where('id', $supprime->user_id)->delete();

    return ['survivant' => $survivant, 'pending' => $pending];
}

it('openlmnp:dossier-events-purge expurge les reliquats existants, et une seconde passe ne trouve plus rien', function () {
    ['survivant' => $survivant, 'pending' => $pending] = reliquatsAvantCorrectif();

    expect(texteJournalDossiers())->toContain('invite.orphelin@exemple.fr')
        ->and(texteJournalDossiers())->toContain('parti@exemple.fr');

    $this->artisan('openlmnp:dossier-events-purge')
        ->expectsOutputToContain('Événements de dossiers supprimés : 2')
        ->expectsOutputToContain('Invitations acceptées par un compte supprimé : 1')
        ->expectsOutputToContain('Événements expurgés (adresse ou compte disparu) : 2')
        ->assertSuccessful();

    expect(texteJournalDossiers())->not->toContain('invite.orphelin@exemple.fr')
        ->and(texteJournalDossiers())->not->toContain('parti@exemple.fr')
        ->and(texteJournalDossiers())->not->toContain('200000008')
        ->and(DossierEvent::whereNotIn('linked_dossier_id', LinkedDossier::query()->select('id'))->count())->toBe(0)
        // Le dossier restant garde son historique (types et dates) et ce qui est légitime.
        ->and(DossierEvent::where('linked_dossier_id', $survivant->id)->count())->toBe(4)
        ->and(LinkedDossierInvitation::find($pending->id))->not->toBeNull()
        ->and(texteJournalDossiers())->toContain('en.attente@exemple.fr')
        ->and(DossierEvent::where('linked_dossier_id', $survivant->id)->where('type', DossierEvent::TYPE_CREATED)->sole()->meta)->toBe(['siren' => '100000009']);

    $this->artisan('openlmnp:dossier-events-purge')
        ->expectsOutputToContain('Événements de dossiers supprimés : 0')
        ->expectsOutputToContain('Invitations acceptées par un compte supprimé : 0')
        ->expectsOutputToContain('Événements expurgés (adresse ou compte disparu) : 0')
        ->assertSuccessful();
});

it('openlmnp:dossier-events-purge --dry-run compte sans rien modifier', function () {
    reliquatsAvantCorrectif();
    $avant = texteJournalDossiers();

    $this->artisan('openlmnp:dossier-events-purge', ['--dry-run' => true])
        ->expectsOutputToContain('Simulation')
        ->expectsOutputToContain('Événements de dossiers supprimés : 2')
        ->assertSuccessful();

    expect(texteJournalDossiers())->toBe($avant);
});
