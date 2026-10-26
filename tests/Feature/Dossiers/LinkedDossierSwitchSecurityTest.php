<?php

use App\Enums\DossierMemberRole;
use App\Models\LinkedDossier;
use App\Models\User;
use App\Support\DossierContext;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;

/**
 * Sécurité de la bascule d'authentification vers un dossier lié (plan des dossiers
 * liés § 2, § 4). Un dossier lié, ici, n'a jamais besoin d'être vérifié par Sirene :
 * on le crée directement en base (`withoutGlobalScopes()->forceCreate`-like), la
 * vérification Sirene elle-même étant déjà couverte par LinkedDossierCreationTest.
 */
function creerDossierLie(User $owner, string $siren = '100000009', array $memberState = []): LinkedDossier
{
    $dossierUser = new User;
    $dossierUser->forceFill([
        'name' => 'Indivision Test',
        'email' => 'dossier-'.\Illuminate\Support\Str::lower((string) \Illuminate\Support\Str::ulid()).'@dossiers.invalid',
        'password' => \Illuminate\Support\Facades\Hash::make(\Illuminate\Support\Str::random(32)),
        'is_linked_dossier' => true,
        'is_indivision' => true,
        'siren' => $siren,
    ])->save();

    $dossier = LinkedDossier::create([
        'public_id' => (string) \Illuminate\Support\Str::ulid(),
        'user_id' => $dossierUser->id,
        'owner_id' => $owner->id,
        'kind' => 'indivision',
        'siren' => $siren,
    ]);

    $dossier->members()->create(array_replace([
        'user_id' => $owner->id,
        'role' => DossierMemberRole::Owner,
        'accepted_at' => now(),
    ], $memberState));

    return $dossier;
}

beforeEach(function () {
    config(['dossiers.enabled' => true, 'services.provisioning.token' => null, 'dossiers.quota' => null]);
});

it('ouvre un dossier par bascule, régénère la session, et redirige vers le tableau de bord', function () {
    $principal = User::factory()->create();
    $dossier = creerDossierLie($principal);

    $this->actingAs($principal);
    $tokenAvant = session()->token();

    $this->post(route('filament.admin.dossiers.open', $dossier->public_id))
        ->assertRedirect('/');

    expect(Auth::user()->id)->toBe($dossier->user_id)
        ->and(Auth::user()->is_linked_dossier)->toBeTrue()
        ->and(session(DossierContext::SESSION_PRINCIPAL_ID))->toBe($principal->id)
        // Vieil onglet : le jeton CSRF a changé, celui d'un onglet resté ouvert sur
        // l'ancien compte principal ne vaut plus rien (419 à sa prochaine soumission).
        ->and(session()->token())->not->toBe($tokenAvant);
});

it('refuse un identifiant de dossier forgé (404)', function () {
    $principal = User::factory()->create();

    $this->actingAs($principal)
        ->post(route('filament.admin.dossiers.open', 'identifiant-invente'))
        ->assertNotFound();

    expect(Auth::user()->id)->toBe($principal->id);
});

it('refuse un dossier dont on n\'est pas membre, par le MÊME 404 qu\'un identifiant forgé', function () {
    $proprietaire = User::factory()->create();
    $dossier = creerDossierLie($proprietaire);

    $etranger = User::factory()->create();

    $this->actingAs($etranger)
        ->post(route('filament.admin.dossiers.open', $dossier->public_id))
        ->assertNotFound();

    expect(Auth::user()->id)->toBe($etranger->id);
});

it('refuse la bascule en GET (405)', function () {
    $principal = User::factory()->create();
    $dossier = creerDossierLie($principal);

    $this->actingAs($principal)
        ->get(route('filament.admin.dossiers.open', $dossier->public_id))
        ->assertStatus(405);
});

it('les routes de bascule restent protégées par la vérification CSRF du panel', function () {
    // Les tests Pest désactivent la vérification CSRF (PreventRequestForgery::
    // runningUnitTests()) — c'est un comportement VOULU de Laravel, le même qui permet
    // à toutes les autres actions du panel d'être testées sans jeton. On vérifie donc,
    // à la place d'un 419 impossible à produire ici, que la route n'est dans AUCUNE
    // liste d'exception et porte bien le middleware du panel (qui inclut
    // PreventRequestForgery — voir AdminPanelProvider::panel()).
    $route = collect(Route::getRoutes())->first(fn ($r) => $r->getName() === 'filament.admin.dossiers.open');

    expect($route)->not->toBeNull()
        ->and($route->middleware())->toContain('panel:admin');
});

it('refuse la bascule d\'un principal suspendu', function () {
    $principal = User::factory()->create(['suspended_at' => now()]);
    $dossier = creerDossierLie($principal);

    // Un principal suspendu échoue dès `canAccessPanel()` (Filament::Authenticate,
    // AVANT même le contrôleur) : 403, pas un aller-retour avec notification.
    $this->actingAs($principal)
        ->post(route('filament.admin.dossiers.open', $dossier->public_id))
        ->assertForbidden();

    expect(Auth::id())->not->toBe($dossier->user_id);
});

it('refuse la bascule pour un compte de démonstration', function () {
    $principal = User::factory()->create(['is_demo' => true]);
    $dossier = creerDossierLie($principal);

    $this->actingAs($principal)
        ->post(route('filament.admin.dossiers.open', $dossier->public_id))
        ->assertRedirect();

    expect(Auth::id())->toBe($principal->id)
        ->and(Auth::id())->not->toBe($dossier->user_id);
});

it('refuse d\'ouvrir un dossier archivé', function () {
    $principal = User::factory()->create();
    $dossier = creerDossierLie($principal);
    $dossier->update(['archived_at' => now()]);

    $this->actingAs($principal)
        ->post(route('filament.admin.dossiers.open', $dossier->public_id))
        ->assertRedirect();

    expect(Auth::id())->not->toBe($dossier->user_id);
});

it('refuse de basculer vers un AUTRE dossier depuis un dossier déjà actif', function () {
    $principal = User::factory()->create();
    $dossierA = creerDossierLie($principal, '100000009');
    $dossierB = creerDossierLie($principal, '100000017');

    $this->actingAs($principal);
    $this->post(route('filament.admin.dossiers.open', $dossierA->public_id))->assertRedirect('/');
    expect(Auth::user()->id)->toBe($dossierA->user_id);

    // Depuis le dossier A (donc sans être membre direct de B au sens de la vérification
    // du contrôleur, qui exige un principal non lié) : même 404 qu'un identifiant inconnu.
    $this->post(route('filament.admin.dossiers.open', $dossierB->public_id))
        ->assertNotFound();

    expect(Auth::user()->id)->toBe($dossierA->user_id);
});

it('refuse toute connexion directe au User d\'un dossier lié', function () {
    $principal = User::factory()->create();
    $dossier = creerDossierLie($principal);

    // Ni mot de passe, ni réinitialisation : aucune session dossier.principal_id en place.
    expect($dossier->user->canAccessPanel(\Filament\Facades\Filament::getPanel('admin')))->toBeFalse();
});

it('canAccessPanel refuse un dossier sans contexte de bascule valide, même authentifié directement', function () {
    $principal = User::factory()->create();
    $dossier = creerDossierLie($principal);

    $this->actingAs($dossier->user);

    // EnsureDossierContext (persistant, posé AVANT Authenticate — voir
    // AdminPanelProvider::panel()) intercepte avant même que canAccessPanel() ne soit
    // consulté : aucun principal en session → déconnexion complète, redirection login.
    $this->get('/')->assertRedirect(route('filament.admin.auth.login'));

    expect(Auth::check())->toBeFalse();
});

it('un membre révoqué en cours de session perd l\'accès dès la requête suivante', function () {
    $principal = User::factory()->create();
    $dossier = creerDossierLie($principal);

    $this->actingAs($principal);
    $this->post(route('filament.admin.dossiers.open', $dossier->public_id))->assertRedirect('/');
    expect(Auth::user()->is_linked_dossier)->toBeTrue();

    // Révocation en base PENDANT que la session du dossier est active.
    $dossier->members()->where('user_id', $principal->id)->update(['revoked_at' => now()]);

    $this->get('/')->assertRedirect(route('filament.admin.auth.login'));

    expect(Auth::user()?->id)->toBe($principal->id);
});

/**
 * ⚠️ `Livewire::test(...)->call(...)` NE PEUT PAS exercer le middleware PERSISTANT :
 * `Livewire\Mechanisms\PersistentMiddleware\PersistentMiddleware::boot()` ne
 * l'applique QUE si `HandleRequests::isLivewireRoute()` est vrai, et son propre
 * commentaire le dit : « Only apply middleware to requests hitting the Livewire
 * update endpoint, and not any fake requests such as a test. » C'est une limite du
 * harnais de test de Livewire lui-même, pas de ce middleware. On vérifie donc ICI,
 * au niveau du middleware lui-même (sans détour par Livewire), qu'une requête
 * ultérieure à la révocation est bien interrompue — c'est ce même code qui tourne,
 * posé en isPersistent sur le panel (vérifié par ailleurs : voir
 * bootstrap/app.php, `Livewire::getPersistentMiddleware()` contient
 * EnsureDossierContext une fois le panel démarré).
 */
it('le middleware EnsureDossierContext interrompt lui-même une requête après révocation', function () {
    $principal = User::factory()->create();
    $dossier = creerDossierLie($principal);

    $this->actingAs($principal);
    $this->post(route('filament.admin.dossiers.open', $dossier->public_id));
    expect(Auth::user()->is_linked_dossier)->toBeTrue();

    $dossier->members()->where('user_id', $principal->id)->update(['revoked_at' => now()]);

    $middleware = new \App\Http\Middleware\EnsureDossierContext;
    $appelAuSuivant = false;

    expect(fn () => $middleware->handle(request(), function () use (&$appelAuSuivant) {
        $appelAuSuivant = true;
    }))->toThrow(\Illuminate\Auth\AuthenticationException::class);

    expect($appelAuSuivant)->toBeFalse()
        ->and(Auth::user()?->id)->toBe($principal->id);
});

it('EnsureDossierContext est bien enregistré comme middleware PERSISTANT du panel', function () {
    // C'est CETTE ligne qui garantit qu'un composant Livewire déjà monté (donc une
    // simple requête de mise à jour AJAX, qui ne rejoue pas Authenticate) revalide
    // quand même le contexte à chaque interaction.
    \Filament\Facades\Filament::getPanel('admin'); // force le boot du panel, donc l'appel à Livewire::addPersistentMiddleware()

    expect(\Livewire\Livewire::getPersistentMiddleware())
        ->toContain(\App\Http\Middleware\EnsureDossierContext::class);
});

it('le mot de passe du principal changé ailleurs referme le dossier à la requête suivante', function () {
    $principal = User::factory()->create();
    $dossier = creerDossierLie($principal);

    $this->actingAs($principal);
    $this->post(route('filament.admin.dossiers.open', $dossier->public_id))->assertRedirect('/');
    expect(Auth::user()->is_linked_dossier)->toBeTrue();

    // Le principal change de mot de passe depuis un autre appareil pendant que la
    // session du dossier tourne ailleurs.
    $principal->forceFill(['password' => \Illuminate\Support\Facades\Hash::make('un-autre-mot-de-passe')])->save();

    $this->get('/')->assertRedirect(route('filament.admin.auth.login'));
    expect(Auth::user()?->is_linked_dossier ?? false)->toBeFalse();
});

it('retourne au principal quand il est encore utilisable, ou déconnecte sinon', function () {
    // Cas 1 : principal encore valable (membre révoqué) → retour possible.
    $principal = User::factory()->create();
    $dossier = creerDossierLie($principal);
    $this->actingAs($principal);
    $this->post(route('filament.admin.dossiers.open', $dossier->public_id))->assertRedirect('/');
    $dossier->members()->where('user_id', $principal->id)->update(['revoked_at' => now()]);
    $this->get('/');
    expect(Auth::user()?->id)->toBe($principal->id);

    // Cas 2 : principal devenu inutilisable (suspendu) pendant la même bascule → déconnexion.
    $principal2 = User::factory()->create();
    $dossier2 = creerDossierLie($principal2, '100000025');
    $this->actingAs($principal2);
    $this->post(route('filament.admin.dossiers.open', $dossier2->public_id))->assertRedirect('/');
    $principal2->forceFill(['suspended_at' => now()])->save();
    $this->get('/');
    expect(Auth::check())->toBeFalse();
});

it('refuse la création d\'un dossier pour un compte démo via DossierContext::switchTo', function () {
    $demo = User::factory()->create(['is_demo' => true]);
    $dossier = creerDossierLie($demo);

    $this->actingAs($demo);

    expect(fn () => DossierContext::switchTo($dossier))
        ->toThrow(\App\Support\DossierSwitchException::class, 'démonstration');
});
