<?php

use App\Enums\DossierMemberRole;
use App\Filament\Pages\LinkedDossiers;
use App\Models\DossierEvent;
use App\Models\User;
use App\Services\LinkedDossierService;
use App\Support\DossierContext;
use Illuminate\Support\Facades\Auth;
use Livewire\Livewire;

/**
 * Le membre, une fois accepté : il voit le dossier, bascule, quitte — et deux
 * dossiers d'un même membre restent étanches l'un de l'autre (lot 3).
 * `creerDossierLie()` : fonction globale Pest, voir LinkedDossierSwitchSecurityTest.php.
 */
beforeEach(function () {
    config(['dossiers.enabled' => true, 'services.provisioning.token' => null, 'dossiers.quota' => null]);
});

function ajouterMembre(\App\Models\LinkedDossier $dossier, User $membre, ?\Carbon\CarbonInterface $acceptedAt = null): \App\Models\LinkedDossierMember
{
    return $dossier->members()->create([
        'user_id' => $membre->id,
        'role' => DossierMemberRole::Member,
        'accepted_at' => $acceptedAt ?? now(),
    ]);
}

it('le membre voit le dossier dans son sélecteur et peut y basculer', function () {
    $owner = User::factory()->create();
    $dossier = creerDossierLie($owner);
    $membre = User::factory()->create();
    ajouterMembre($dossier, $membre);

    expect(DossierContext::dossiersFor($membre)->pluck('id'))->toContain($dossier->id);

    $this->actingAs($membre);
    $this->post(route('filament.admin.dossiers.open', $dossier->public_id))->assertRedirect('/');

    expect(Auth::user()->id)->toBe($dossier->user_id);
});

it('un membre peut quitter le dossier (service)', function () {
    $owner = User::factory()->create();
    $dossier = creerDossierLie($owner);
    $membre = User::factory()->create();
    ajouterMembre($dossier, $membre);

    app(LinkedDossierService::class)->leave($dossier, $membre);

    expect($dossier->members()->where('user_id', $membre->id)->first()->revoked_at)->not->toBeNull()
        ->and(DossierContext::dossiersFor($membre)->pluck('id'))->not->toContain($dossier->id)
        ->and(DossierEvent::where('linked_dossier_id', $dossier->id)
            ->where('type', DossierEvent::TYPE_LEFT)
            ->where('user_id', $membre->id)
            ->exists())->toBeTrue();

    // Il ne peut plus l'ouvrir : même 404 qu'un dossier inconnu.
    $this->actingAs($membre)
        ->post(route('filament.admin.dossiers.open', $dossier->public_id))
        ->assertNotFound();
});

it('un membre peut quitter le dossier depuis la page (Livewire)', function () {
    $owner = User::factory()->create();
    $dossier = creerDossierLie($owner);
    $membre = User::factory()->create();
    ajouterMembre($dossier, $membre);

    Livewire::actingAs($membre)
        ->test(LinkedDossiers::class)
        ->call('quitter', $dossier->id);

    expect($dossier->members()->where('user_id', $membre->id)->first()->revoked_at)->not->toBeNull();
});

it('le responsable actuel peut révoquer un membre depuis la page (Livewire)', function () {
    $owner = User::factory()->create();
    $dossier = creerDossierLie($owner);
    $membre = User::factory()->create();
    ajouterMembre($dossier, $membre);

    Livewire::actingAs($owner)
        ->test(LinkedDossiers::class)
        ->call('revoquerMembre', $dossier->id, $membre->id);

    expect($dossier->members()->where('user_id', $membre->id)->first()->revoked_at)->not->toBeNull();
});

it('refuse qu\'un simple membre (pas responsable) révoque quelqu\'un d\'autre', function () {
    $owner = User::factory()->create();
    $dossier = creerDossierLie($owner);
    $membre = User::factory()->create();
    $autreMembre = User::factory()->create();
    ajouterMembre($dossier, $membre);
    ajouterMembre($dossier, $autreMembre);

    try {
        Livewire::actingAs($membre)->test(LinkedDossiers::class)->call('revoquerMembre', $dossier->id, $autreMembre->id);
    } catch (\Throwable $e) {
        expect($e->getCode())->toBe(403);
    }

    expect($dossier->members()->where('user_id', $autreMembre->id)->first()->revoked_at)->toBeNull();
});

it('isole deux dossiers d\'un même membre : quitter l\'un laisse l\'autre intact', function () {
    $ownerA = User::factory()->create();
    $dossierA = creerDossierLie($ownerA, '100000009');
    $ownerB = User::factory()->create();
    $dossierB = creerDossierLie($ownerB, '100000017');

    $membre = User::factory()->create();
    ajouterMembre($dossierA, $membre);
    ajouterMembre($dossierB, $membre);

    expect(DossierContext::dossiersFor($membre)->pluck('id')->sort()->values()->all())
        ->toBe(collect([$dossierA->id, $dossierB->id])->sort()->values()->all());

    app(LinkedDossierService::class)->leave($dossierA, $membre);

    $restants = DossierContext::dossiersFor($membre)->pluck('id');

    expect($restants)->not->toContain($dossierA->id)
        ->and($restants)->toContain($dossierB->id);

    // Et la bascule : A refuse désormais, B fonctionne toujours.
    $this->actingAs($membre);
    $this->post(route('filament.admin.dossiers.open', $dossierA->public_id))->assertNotFound();
    $this->post(route('filament.admin.dossiers.open', $dossierB->public_id))->assertRedirect('/');
    expect(Auth::user()->id)->toBe($dossierB->user_id);
});
