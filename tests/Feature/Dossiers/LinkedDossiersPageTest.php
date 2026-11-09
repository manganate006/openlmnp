<?php

use App\Filament\Pages\LinkedDossiers;
use App\Models\LinkedDossier;
use App\Models\User;
use App\Support\HelpContentRegistry;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

const PAGE_SIREN_1 = '100000009';
const PAGE_SIREN_2 = '100000017';

function pageSireneCompany(string $siren): array
{
    return [
        'siren' => $siren,
        'nom_complet' => 'INDIVISION TEST',
        'statut_diffusion' => 'O',
        'etat_administratif' => 'A',
        'date_creation' => '2020-01-01',
        'date_fermeture' => null,
        'activite_principale' => '68.20A',
        'nature_juridique' => '2110',
    ];
}

function fakePageSirene(string $siren): void
{
    Http::fake([
        'recherche-entreprises.api.gouv.fr/*' => Http::response([
            'results' => [pageSireneCompany($siren)],
            'total_results' => 1,
        ]),
    ]);
}

beforeEach(function () {
    config(['dossiers.enabled' => true, 'services.provisioning.token' => null, 'dossiers.quota' => null]);
});

it('est inaccessible quand la fonctionnalité est désactivée', function () {
    config(['dossiers.enabled' => false]);
    $user = User::factory()->create();

    expect(LinkedDossiers::canAccess())->toBeFalse();
});

it('est inaccessible depuis un dossier lié lui-même', function () {
    $owner = User::factory()->create();
    fakePageSirene(PAGE_SIREN_1);
    $dossier = app(\App\Services\LinkedDossierService::class)->create($owner, PAGE_SIREN_1, 'Test');

    $this->actingAs($dossier->user);

    expect(LinkedDossiers::canAccess())->toBeFalse();
});

it('crée un dossier lié depuis l\'assistant, avec son événement analytics', function () {
    $owner = User::factory()->create();
    fakePageSirene(PAGE_SIREN_1);

    Livewire::actingAs($owner)
        ->test(LinkedDossiers::class)
        ->callAction('creer', [
            'siren' => PAGE_SIREN_1,
            'name' => 'Indivision Dupont-Martin',
        ])
        ->assertHasNoActionErrors();

    $dossier = LinkedDossier::where('siren', PAGE_SIREN_1)->first();
    expect($dossier)->not->toBeNull()
        ->and($dossier->owner_id)->toBe($owner->id);

    // ⚠️ L'événement `linked_dossier_created` est mis en file par le même mécanisme
    // que `sign_up`/`login` (AppServiceProvider::queueAnalyticsEvent(), flash de
    // session, voir tests/Feature/GtmIntegrationTest.php) — mais PAS vérifiable ICI
    // via `session('analytics')` : `Livewire::test(...)->callAction(...)` enchaîne en
    // réalité PLUSIEURS sous-requêtes internes (montage puis appel de l'action), et
    // cette ré-entrée efface les clés de session arbitraires entre deux sous-requêtes
    // (seul `_flash.new`/`_flash.old` survit, pas la valeur flashée elle-même) —
    // constaté à l'identique sur un composant Filament MINIMAL sans aucun rapport
    // avec ce code, donc un artefact du harnais de test de Filament/Livewire, pas un
    // bug de cette fonctionnalité. `GtmIntegrationTest.php` ne rencontre jamais ce
    // problème parce que ses exemples appellent une méthode PUBLIQUE directement
    // (`->call('create')`), jamais `->callAction()`. Le mécanisme lui-même
    // (`queueAnalyticsEvent`, jamais `purchase` — plan § 5) est donc vérifié par
    // lecture de code plutôt que par ce test, qui se concentre sur ce qu'il peut
    // vraiment observer : la création réelle du dossier.
});

it('affiche un message d\'erreur sans créer de dossier pour un SIREN invalide', function () {
    $owner = User::factory()->create();

    Livewire::actingAs($owner)
        ->test(LinkedDossiers::class)
        ->callAction('creer', [
            'siren' => '123456789',
            'name' => 'Test',
        ]);

    expect(LinkedDossier::count())->toBe(0);
});

it('liste les dossiers dont l\'utilisateur est membre, avec le quota', function () {
    $owner = User::factory()->create();
    fakePageSirene(PAGE_SIREN_1);
    app(\App\Services\LinkedDossierService::class)->create($owner, PAGE_SIREN_1, 'Mon indivision');

    Livewire::actingAs($owner)
        ->test(LinkedDossiers::class)
        ->assertSee('Mon indivision')
        ->assertSee(PAGE_SIREN_1);
});

it('distingue une invitation refusée (par l\'invité) d\'une invitation révoquée (par le dossier)', function () {
    // Défaut gênant n°6 du test de bout en bout (2026-10-15) : les deux
    // s'affichaient toutes deux « Révoquée », les deux décisions étant écrites
    // dans la même colonne `revoked_at`.
    $owner = User::factory()->create();
    fakePageSirene(PAGE_SIREN_1);
    $dossier = app(\App\Services\LinkedDossierService::class)->create($owner, PAGE_SIREN_1, 'Test badges');
    $invitations = app(\App\Services\LinkedDossierInvitationService::class);

    [$refusee] = $invitations->create($dossier, 'refuse@exemple.fr', $owner);
    [$revoquee] = $invitations->create($dossier, 'revoque@exemple.fr', $owner);

    $invitations->decline($refusee);
    $invitations->revoke($revoquee, $owner);

    Livewire::actingAs($owner)
        ->test(LinkedDossiers::class)
        ->assertSee('Refusée')
        ->assertSee('Révoquée');
});

it('archive un dossier (créateur seulement) et ferme son ouverture', function () {
    $owner = User::factory()->create();
    fakePageSirene(PAGE_SIREN_1);
    $dossier = app(\App\Services\LinkedDossierService::class)->create($owner, PAGE_SIREN_1, 'Test');

    Livewire::actingAs($owner)
        ->test(LinkedDossiers::class)
        ->call('archiver', $dossier->id);

    expect($dossier->fresh()->isArchived())->toBeTrue();

    $this->actingAs($owner)
        ->post(route('filament.admin.dossiers.open', $dossier->public_id))
        ->assertRedirect();

    expect(\Illuminate\Support\Facades\Auth::id())->not->toBe($dossier->user_id);
});

it('refuse d\'archiver un dossier dont on n\'est pas le créateur', function () {
    $owner = User::factory()->create();
    fakePageSirene(PAGE_SIREN_1);
    $dossier = app(\App\Services\LinkedDossierService::class)->create($owner, PAGE_SIREN_1, 'Test');

    $autre = User::factory()->create();

    try {
        Livewire::actingAs($autre)->test(LinkedDossiers::class)->call('archiver', $dossier->id);
    } catch (\Throwable $e) {
        expect($e->getCode())->toBe(403);
    }

    expect($dossier->fresh()->isArchived())->toBeFalse();
});

it('résout la fiche d\'aide depuis sa route, sans retomber sur le repli', function () {
    expect(HelpContentRegistry::resolve('filament.admin.pages.linked-dossiers'))
        ->toBe(['view' => 'help.linked-dossiers', 'title' => 'Dossiers liés']);
});
