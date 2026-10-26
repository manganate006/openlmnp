<?php

use App\Enums\AccountKind;
use App\Enums\DossierMemberRole;
use App\Filament\Pages\Dashboard;
use App\Filament\Pages\LinkedDossiers;
use App\Filament\Resources\Properties\PropertyResource;
use App\Models\LinkedDossier;
use App\Models\User;
use App\Services\LinkedDossierService;
use App\Support\DossierContext;
use App\Support\DossierQuota;
use App\Support\DossierSwitchException;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Auth;
use Livewire\Livewire;

/**
 * Accès et garde anti-détournement du compte « membre » (App\Enums\AccountKind::
 * Member) — lot « dossiers membre », décision de Jérémie du 2026-10-15.
 *
 * `creerDossierLie()` : fonction globale Pest, voir LinkedDossierSwitchSecurityTest.php.
 */
function creerCompteMembre(string $email = 'membre@exemple.fr'): User
{
    return User::factory()->create(['email' => $email, 'account_kind' => AccountKind::Member]);
}

function rattacherMembre(LinkedDossier $dossier, User $membre): void
{
    $dossier->members()->create([
        'user_id' => $membre->id,
        'role' => DossierMemberRole::Member,
        'accepted_at' => now(),
    ]);
}

beforeEach(function () {
    config(['dossiers.enabled' => true, 'services.provisioning.token' => null, 'dossiers.quota' => null]);
});

// === Quota toujours 0, jamais de création ===

it('un compte membre a toujours un quota de 0, même si la colonne porte une valeur', function () {
    $membre = creerCompteMembre();
    $membre->forceFill(['linked_dossier_quota' => 5])->save();

    expect(DossierQuota::for($membre->fresh()))->toBe(0)
        ->and(DossierQuota::canCreate($membre->fresh()))->toBeFalse();
});

it('masque l\'action de création de dossier lié pour un compte membre', function () {
    $membre = creerCompteMembre();
    $dossier = creerDossierLie(User::factory()->create());
    rattacherMembre($dossier, $membre);

    Livewire::actingAs($membre)->test(LinkedDossiers::class)
        ->assertActionHidden('creer');
});

// === Succession : un compte membre ne devient jamais responsable ===

it('actingOwner() ne choisit jamais un compte membre, le dossier se ferme plutôt', function () {
    $owner = User::factory()->create();
    $dossier = creerDossierLie($owner);
    $membre = creerCompteMembre();
    rattacherMembre($dossier, $membre);

    expect($dossier->actingOwner()->id)->toBe($owner->id);

    $owner->forceFill(['suspended_at' => now()])->save();

    // Le seul autre membre actif est un compte membre : AUCUN responsable, le
    // dossier doit se FERMER plutôt que de le désigner par défaut.
    expect($dossier->actingOwner())->toBeNull()
        ->and($dossier->isClosed())->toBeTrue();
});

it('quitter en tant que créateur ne laisse jamais l\'administration à un membre restant', function () {
    $owner = User::factory()->create();
    $dossier = creerDossierLie($owner);
    $membre = creerCompteMembre();
    rattacherMembre($dossier, $membre);

    app(LinkedDossierService::class)->leave($dossier, $owner);

    expect($dossier->fresh()->actingOwner())->toBeNull()
        ->and($dossier->fresh()->isClosed())->toBeTrue()
        ->and(LinkedDossier::find($dossier->id))->not->toBeNull();
});

// === Anti-détournement : un compte membre n'ouvre un dossier qu'avec un backer actif ===

it('switchTo() refuse un compte membre si personne d\'autre n\'est actif et non-membre', function () {
    $owner = User::factory()->create();
    $dossier = creerDossierLie($owner);
    $membre = creerCompteMembre();
    rattacherMembre($dossier, $membre);

    $owner->forceFill(['suspended_at' => now()])->save();

    $this->actingAs($membre);

    expect(fn () => DossierContext::switchTo($dossier->fresh()))
        ->toThrow(DossierSwitchException::class);

    expect(Auth::id())->toBe($membre->id);
});

it('switchTo() accepte un compte membre tant qu\'un backer standard actif subsiste', function () {
    $owner = User::factory()->create();
    $dossier = creerDossierLie($owner);
    $membre = creerCompteMembre();
    rattacherMembre($dossier, $membre);

    $this->actingAs($membre);
    DossierContext::switchTo($dossier->fresh());

    expect(Auth::user()->id)->toBe($dossier->user_id);
});

it('la route d\'ouverture redirige (sans 404) quand le dossier n\'a plus de backer actif', function () {
    $owner = User::factory()->create();
    $dossier = creerDossierLie($owner);
    $membre = creerCompteMembre();
    rattacherMembre($dossier, $membre);

    $owner->forceFill(['suspended_at' => now()])->save();

    $this->actingAs($membre)
        ->post(route('filament.admin.dossiers.open', $dossier->public_id))
        ->assertRedirect();

    expect(Auth::id())->toBe($membre->id);
});

it('un compte membre perd l\'accès DÈS LA REQUÊTE SUIVANTE quand le dernier backer se suspend en cours de session', function () {
    $owner = User::factory()->create();
    $dossier = creerDossierLie($owner);
    $membre = creerCompteMembre();
    rattacherMembre($dossier, $membre);

    $this->actingAs($membre);
    $this->post(route('filament.admin.dossiers.open', $dossier->public_id))->assertRedirect('/');
    expect(Auth::user()->id)->toBe($dossier->user_id);

    // Le backer standard se suspend PENDANT que la session du membre est active
    // dans le dossier.
    $owner->forceFill(['suspended_at' => now()])->save();

    $this->get('/')->assertRedirect(route('filament.admin.auth.login'));

    expect(Auth::user()?->id)->toBe($membre->id);
});

it('un compte membre perd l\'accès dès la requête suivante quand le dernier backer QUITTE le dossier', function () {
    $owner = User::factory()->create();
    $dossier = creerDossierLie($owner);
    $membre = creerCompteMembre();
    rattacherMembre($dossier, $membre);

    $this->actingAs($membre);
    $this->post(route('filament.admin.dossiers.open', $dossier->public_id));
    expect(Auth::user()->id)->toBe($dossier->user_id);

    app(LinkedDossierService::class)->leave($dossier, $owner);

    $this->get('/')->assertRedirect(route('filament.admin.auth.login'));

    expect(Auth::user()?->id)->toBe($membre->id);
});

/**
 * ⚠️ Même limite de harnais que LinkedDossierSwitchSecurityTest.php :
 * `Livewire::test()` ne peut pas exercer le middleware PERSISTANT. On vérifie donc
 * ICI, au niveau du middleware lui-même, qu'une requête ultérieure à la perte du
 * backer est bien interrompue.
 */
it('le middleware EnsureDossierContext interrompt lui-même une requête sans backer actif', function () {
    $owner = User::factory()->create();
    $dossier = creerDossierLie($owner);
    $membre = creerCompteMembre();
    rattacherMembre($dossier, $membre);

    $this->actingAs($membre);
    $this->post(route('filament.admin.dossiers.open', $dossier->public_id));
    expect(Auth::user()->is_linked_dossier)->toBeTrue();

    $owner->forceFill(['suspended_at' => now()])->save();

    $middleware = new \App\Http\Middleware\EnsureDossierContext;
    $appelAuSuivant = false;

    expect(fn () => $middleware->handle(request(), function () use (&$appelAuSuivant) {
        $appelAuSuivant = true;
    }))->toThrow(\Illuminate\Auth\AuthenticationException::class);

    expect($appelAuSuivant)->toBeFalse()
        ->and(Auth::user()?->id)->toBe($membre->id);
});

// === Arrivée à la connexion : directement dans le dossier, ou sur la liste ===

it('un compte membre avec un seul dossier ouvrable y est basculé directement', function () {
    $owner = User::factory()->create();
    $dossier = creerDossierLie($owner);
    $membre = creerCompteMembre();
    rattacherMembre($dossier, $membre);

    $this->actingAs($membre);

    Livewire::test(Dashboard::class)->assertRedirect(Filament::getUrl());

    expect(Auth::user()->id)->toBe($dossier->user_id);
});

it('un compte membre avec plusieurs dossiers ouvrables atterrit sur « Dossiers liés »', function () {
    $owner1 = User::factory()->create();
    $owner2 = User::factory()->create();
    $dossier1 = creerDossierLie($owner1, '100000009');
    $dossier2 = creerDossierLie($owner2, '100000017');
    $membre = creerCompteMembre();
    rattacherMembre($dossier1, $membre);
    rattacherMembre($dossier2, $membre);

    $this->actingAs($membre);

    Livewire::test(Dashboard::class)->assertRedirect(LinkedDossiers::getUrl());

    expect(Auth::user()->id)->toBe($membre->id);
});

it('un compte membre sans aucun dossier ouvrable reste sur son tableau de bord (vide, bloqué)', function () {
    $owner = User::factory()->create();
    $dossier = creerDossierLie($owner);
    $membre = creerCompteMembre();
    rattacherMembre($dossier, $membre);
    $owner->forceFill(['suspended_at' => now()])->save(); // plus aucun backer

    $this->actingAs($membre);

    Livewire::test(Dashboard::class)->assertNoRedirect();

    expect(Auth::user()->id)->toBe($membre->id);
});

it('un compte principal (standard) n\'est jamais concerné par la bascule automatique', function () {
    $standard = User::factory()->create();
    $dossier = creerDossierLie($standard);

    $this->actingAs($standard);

    Livewire::test(Dashboard::class)->assertNoRedirect();

    expect(Auth::user()->id)->toBe($standard->id);
});

// === Saisie bloquée dans le principal d'un compte membre ===

it('bloque la navigation ET la création sur Properties dans le principal d\'un compte membre', function () {
    $membre = creerCompteMembre();

    $this->actingAs($membre);

    expect(PropertyResource::canViewAny())->toBeFalse()
        ->and(PropertyResource::canCreate())->toBeFalse();

    $this->get(PropertyResource::getUrl('create'))->assertForbidden();
});

it('autorise de nouveau Properties une fois basculé DANS un dossier', function () {
    $owner = User::factory()->create();
    $dossier = creerDossierLie($owner);
    $membre = creerCompteMembre();
    rattacherMembre($dossier, $membre);

    $this->actingAs($membre);
    DossierContext::switchTo($dossier->fresh());

    expect(PropertyResource::canViewAny())->toBeTrue()
        ->and(PropertyResource::canCreate())->toBeTrue();
});

it('un compte standard n\'est jamais bloqué sur Properties dans son propre principal', function () {
    $standard = User::factory()->create();

    $this->actingAs($standard);

    expect(PropertyResource::canViewAny())->toBeTrue()
        ->and(PropertyResource::canCreate())->toBeTrue();
});

// === Contre-épreuve : la garde anti-détournement, retirée puis remise ===
//
// ⚠️ Non automatisée ici (elle muterait du code de production dans la suite) :
// faite manuellement pendant le développement en commentant temporairement la
// condition `$principal->isMemberAccount() && ! $dossier->hasActiveNonMemberBacker()`
// dans DossierContext::switchTo()/validate(), en re-lançant CE fichier (le test
// « switchTo() refuse... » ci-dessus devient rouge), puis en la remettant (il
// redevient vert). Voir le rapport de livraison pour la confirmation.

// === D6 du test bêta du 2026-10-15 : « Étape 1 : Ajoutez votre bien immobilier » restait affiché ===
//
// Compte membre dans son propre principal, sans dossier ouvrable : le guide de démarrage était
// masqué, mais le widget de synthèse (« Menu Mes biens → Biens immobiliers → Nouveau », menu
// absent pour lui) et le suivi par année restaient sous la phrase « ce compte ne permet pas de
// saisir des biens personnels ».

function widgetsVisiblesDuTableauDeBord(): array
{
    $page = new Dashboard;

    return array_map(
        fn ($widget) => is_string($widget) ? $widget : $widget->widget,
        $page->getVisibleWidgets(),
    );
}

it('compte membre sans dossier ouvrable : le tableau de bord ne montre que l\'explication', function () {
    $owner = User::factory()->create();
    $dossier = creerDossierLie($owner);
    $membre = creerCompteMembre();
    rattacherMembre($dossier, $membre);
    $owner->forceFill(['suspended_at' => now()])->save(); // plus aucun backer

    $this->actingAs($membre);

    $visibles = widgetsVisiblesDuTableauDeBord();

    expect($visibles)->toContain(\App\Filament\Widgets\MemberAccountNotice::class)
        ->and($visibles)->not->toContain(\App\Filament\Widgets\FiscalOverview::class)
        ->and($visibles)->not->toContain(\App\Filament\Widgets\ExpenseCompletionGrid::class)
        ->and($visibles)->not->toContain(\App\Filament\Widgets\PropertyTabsWidget::class)
        ->and($visibles)->not->toContain(\App\Filament\Widgets\BadgesWidget::class)
        ->and($visibles)->not->toContain(\App\Filament\Widgets\AlertBanner::class);

    $this->get('/')->assertOk()->assertDontSee('Ajoutez votre bien immobilier');
});

it('compte standard : le tableau de bord garde ses widgets de synthèse', function () {
    $this->actingAs(User::factory()->create());

    $visibles = widgetsVisiblesDuTableauDeBord();

    expect($visibles)->toContain(\App\Filament\Widgets\FiscalOverview::class)
        ->and($visibles)->toContain(\App\Filament\Widgets\ExpenseCompletionGrid::class)
        ->and($visibles)->not->toContain(\App\Filament\Widgets\MemberAccountNotice::class);
});

it('compte membre basculé DANS un dossier : les widgets de synthèse reviennent', function () {
    $owner = User::factory()->create();
    $dossier = creerDossierLie($owner);
    $membre = creerCompteMembre();
    rattacherMembre($dossier, $membre);

    $this->actingAs($membre);
    DossierContext::switchTo($dossier);

    expect(widgetsVisiblesDuTableauDeBord())->toContain(\App\Filament\Widgets\FiscalOverview::class);
});
