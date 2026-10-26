<?php

use App\Enums\DossierMemberRole;
use App\Http\Middleware\VerifyDossierContext;
use App\Models\DossierEvent;
use App\Models\LinkedDossier;
use App\Models\User;
use App\Support\DossierContext;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\Auth;
use Livewire\Livewire;

/**
 * Le « vieil onglet » (constaté sur la bêta le 2026-10-15) : un onglet resté ouvert sur
 * l'identité d'avant une bascule de dossier écrivait, sans erreur, dans l'identité ACTUELLE
 * de la session — parce qu'en Laravel 13, `PreventRequestForgery::hasValidOrigin()` laisse
 * passer toute requête `Sec-Fetch-Site: same-origin` SANS comparer le jeton CSRF (voir le
 * commentaire de classe d'`App\Support\DossierContext` et `CLAUDE.md` § « Dossiers liés »).
 *
 * Ces tests couvrent `App\Http\Middleware\VerifyDossierContext` : identifiant de contexte,
 * régénéré à chaque bascule, exigé à l'identique sur toute écriture.
 */
function creerDossierLiePourVieilOnglet(User $owner, string $siren = '100000009'): LinkedDossier
{
    $dossierUser = new User;
    $dossierUser->forceFill([
        'name' => 'Indivision Vieil Onglet',
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

    $dossier->members()->create([
        'user_id' => $owner->id,
        'role' => DossierMemberRole::Owner,
        'accepted_at' => now(),
    ]);

    return $dossier;
}

beforeEach(function () {
    config(['dossiers.enabled' => true, 'services.provisioning.token' => null, 'dossiers.quota' => null]);
});

// --- DossierContext::contextId() / regenerateContext() ----------------------------------

it('contextId() crée un identifiant au besoin et le garde stable tant que rien ne bascule', function () {
    $this->actingAs(User::factory()->create());

    $id = DossierContext::contextId();

    expect($id)->toBeString()->not->toBe('')
        ->and(DossierContext::contextId())->toBe($id);
});

it('switchTo() régénère l\'identifiant de contexte', function () {
    $principal = User::factory()->create();
    $dossier = creerDossierLiePourVieilOnglet($principal);

    $this->actingAs($principal);
    $avant = DossierContext::contextId();

    DossierContext::switchTo($dossier);

    expect(DossierContext::contextId())->not->toBe($avant);
});

it('leave() régénère l\'identifiant de contexte (retour au principal)', function () {
    $principal = User::factory()->create();
    $dossier = creerDossierLiePourVieilOnglet($principal);
    $this->actingAs($principal);
    DossierContext::switchTo($dossier);
    $avant = DossierContext::contextId();

    DossierContext::leave();

    expect(DossierContext::contextId())->not->toBe($avant)
        ->and(Auth::id())->toBe($principal->id);
});

it('recoverFrom() régénère l\'identifiant de contexte dans ses deux branches', function () {
    // Branche 1 : retour au principal.
    $principal = User::factory()->create();
    $dossier = creerDossierLiePourVieilOnglet($principal);
    $this->actingAs($principal);
    DossierContext::switchTo($dossier);
    $dossierUser = Auth::user();
    $avant = DossierContext::contextId();

    DossierContext::recoverFrom($dossierUser);

    expect(DossierContext::contextId())->not->toBe($avant)
        ->and(Auth::id())->toBe($principal->id);

    // Branche 2 : principal devenu inutilisable (suspendu) → déconnexion complète, qui
    // regarde `request()->session()` (`fullLogout()`) — en passant par une VRAIE requête
    // HTTP, comme `LinkedDossierSwitchSecurityTest` le fait déjà pour cette raison (sans
    // quoi « Session store not set on request » : `recoverFrom()` appelé hors d'une requête
    // HTTP ne reflète pas l'usage réel, toujours déclenché depuis `EnsureDossierContext`).
    $principal2 = User::factory()->create();
    $dossier2 = creerDossierLiePourVieilOnglet($principal2, '100000017');
    $this->actingAs($principal2);
    $this->post(route('filament.admin.dossiers.open', $dossier2->public_id))->assertRedirect('/');
    $principal2->forceFill(['suspended_at' => now()])->save();

    $this->get('/');

    expect(Auth::check())->toBeFalse();
});

// --- VerifyDossierContext::handle() — unitaire, patron EnsureDossierContext -------------

it('VerifyDossierContext laisse passer une requête dont le contexte correspond', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    $bon = DossierContext::contextId();

    $request = Request::create('/livewire-abc/update', 'POST');
    $request->headers->set('X-Livewire', '1');
    $request->headers->set(VerifyDossierContext::HEADER, $bon);
    $request->setUserResolver(fn () => $user);

    $appele = false;
    $middleware = new VerifyDossierContext;
    $middleware->handle($request, function () use (&$appele) {
        $appele = true;

        return new \Symfony\Component\HttpFoundation\Response;
    });

    expect($appele)->toBeTrue();
});

it('VerifyDossierContext refuse (419) un contexte périmé, SANS appeler la suite — rien n\'est écrit', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    DossierContext::contextId(); // établit un contexte...
    DossierContext::regenerateContext(); // ...puis le périme, comme le ferait une bascule dans un autre onglet.
    $perime = 'un-identifiant-qui-ne-correspond-plus';

    $request = Request::create('/livewire-abc/update', 'POST');
    $request->headers->set('X-Livewire', '1');
    $request->headers->set(VerifyDossierContext::HEADER, $perime);
    $request->setUserResolver(fn () => $user);

    $appele = false;
    $middleware = new VerifyDossierContext;

    expect(fn () => $middleware->handle($request, function () use (&$appele) {
        $appele = true;
    }))->toThrow(TokenMismatchException::class);

    expect($appele)->toBeFalse();
});

it('VerifyDossierContext refuse (419) une requête Livewire SANS en-tête du tout', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    DossierContext::contextId();

    // Une page rendue AVANT ce déploiement n'a jamais posé l'en-tête : absence totale,
    // pas seulement une valeur fausse.
    $request = Request::create('/livewire-abc/update', 'POST');
    $request->headers->set('X-Livewire', '1');
    $request->setUserResolver(fn () => $user);

    $middleware = new VerifyDossierContext;

    expect(fn () => $middleware->handle($request, fn () => null))
        ->toThrow(TokenMismatchException::class);
});

it('VerifyDossierContext laisse passer une simple lecture (GET, sans en-tête Livewire)', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $request = Request::create('/admin', 'GET');
    $request->setUserResolver(fn () => $user);

    $appele = false;
    $middleware = new VerifyDossierContext;
    $middleware->handle($request, function () use (&$appele) {
        $appele = true;

        return new \Symfony\Component\HttpFoundation\Response;
    });

    expect($appele)->toBeTrue();
});

it('VerifyDossierContext laisse passer sans utilisateur authentifié', function () {
    $request = Request::create('/admin/dossiers/revenir', 'POST');
    $request->setUserResolver(fn () => null);

    $appele = false;
    $middleware = new VerifyDossierContext;
    $middleware->handle($request, function () use (&$appele) {
        $appele = true;

        return new \Symfony\Component\HttpFoundation\Response;
    });

    expect($appele)->toBeTrue();
});

it('VerifyDossierContext exclut le téléversement Livewire (fichier temporaire)', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $request = Request::create('/livewire-abc/upload-file', 'POST');
    $route = (new Route(['POST'], '/livewire-abc/upload-file', []))->name('livewire.upload-file');
    $request->setRouteResolver(fn () => $route);
    $request->setUserResolver(fn () => $user);
    // Pas d'en-tête de contexte du tout : si l'exclusion ne jouait pas, ce serait un 419.

    $appele = false;
    $middleware = new VerifyDossierContext;
    $middleware->handle($request, function () use (&$appele) {
        $appele = true;

        return new \Symfony\Component\HttpFoundation\Response;
    });

    expect($appele)->toBeTrue();
});

it('VerifyDossierContext exclut la déconnexion (formulaire rendu par Filament, sans point d\'insertion)', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $request = Request::create('/admin/logout', 'POST');
    $route = (new Route(['POST'], '/admin/logout', []))->name('filament.admin.auth.logout');
    $request->setRouteResolver(fn () => $route);
    $request->setUserResolver(fn () => $user);
    // Pas d'en-tête de contexte non plus.

    $appele = false;
    $middleware = new VerifyDossierContext;
    $middleware->handle($request, function () use (&$appele) {
        $appele = true;

        return new \Symfony\Component\HttpFoundation\Response;
    });

    expect($appele)->toBeTrue();
});

it('VerifyDossierContext est bien enregistré comme middleware PERSISTANT du panel', function () {
    \Filament\Facades\Filament::getPanel('admin'); // force le boot du panel

    expect(\Livewire\Livewire::getPersistentMiddleware())
        ->toContain(VerifyDossierContext::class);
});

// --- Vraie pile HTTP : le scénario du bug, sur les routes de bascule --------------------

it('le vieil onglet (ancien contexte) est refusé en 419 par la vraie pile HTTP, et rien n\'est écrit', function () {
    $principal = User::factory()->create();
    $dossierA = creerDossierLiePourVieilOnglet($principal, '100000009');
    $dossierB = creerDossierLiePourVieilOnglet($principal, '100000017');

    $this->actingAs($principal);

    // Onglet 1 : ouvre son contexte (sans écrire), comme le ferait un premier rendu de page.
    $contexteOnglet1 = DossierContext::contextId();

    // Onglet 2, dans la MÊME session : bascule réellement vers le dossier A. L'auto-injection
    // de TestCase::call() porte le contexte COURANT (celui de l'onglet 2, légitime).
    $this->post(route('filament.admin.dossiers.open', $dossierA->public_id))
        ->assertRedirect('/');
    expect(Auth::user()->id)->toBe($dossierA->user_id);

    $evenementsAvant = DossierEvent::count();

    // Onglet 1, resté sur l'ancien contexte : tente à son tour une bascule (vers B). Avec le
    // vieux bug, cette requête aurait été acceptée (same-origin, CSRF non vérifié) et aurait
    // basculé le dossier actif à la place de l'utilisateur de l'onglet 2, en silence.
    $reponse = $this->post(
        route('filament.admin.dossiers.open', $dossierB->public_id),
        [],
        ['X-OpenLMNP-Context' => $contexteOnglet1]
    );

    $reponse->assertStatus(419);

    // Rien n'a changé : toujours dans le dossier A, aucun nouvel événement d'audit.
    expect(Auth::user()->id)->toBe($dossierA->user_id)
        ->and(DossierEvent::count())->toBe($evenementsAvant);
});

it('avec le bon identifiant de contexte, la bascule passe normalement', function () {
    $principal = User::factory()->create();
    $dossier = creerDossierLiePourVieilOnglet($principal);

    $this->actingAs($principal);

    $this->post(route('filament.admin.dossiers.open', $dossier->public_id))
        ->assertRedirect('/');

    expect(Auth::user()->id)->toBe($dossier->user_id);
});

it('après leave(), l\'ancien contexte (celui du dossier) est lui aussi refusé', function () {
    $principal = User::factory()->create();
    $dossier = creerDossierLiePourVieilOnglet($principal);

    $this->actingAs($principal);
    $this->post(route('filament.admin.dossiers.open', $dossier->public_id))->assertRedirect('/');
    $contexteDossier = DossierContext::contextId();

    $this->post(route('filament.admin.dossiers.leave'))->assertRedirect('/');
    expect(Auth::user()->id)->toBe($principal->id);

    // Un onglet resté affiché DANS le dossier tente une action avec ce contexte, désormais périmé.
    $reponse = $this->post(
        route('filament.admin.dossiers.leave'),
        [],
        ['X-OpenLMNP-Context' => $contexteDossier]
    );

    $reponse->assertStatus(419);
    expect(Auth::user()->id)->toBe($principal->id);
});

it('une requête sans en-tête du tout est refusée (page rendue avant ce déploiement)', function () {
    $principal = User::factory()->create();
    $dossier = creerDossierLiePourVieilOnglet($principal);
    $this->actingAs($principal);

    $this->withoutDossierContextHeader()
        ->post(route('filament.admin.dossiers.open', $dossier->public_id))
        ->assertStatus(419);

    expect(Auth::id())->toBe($principal->id);
});

it('la déconnexion fonctionne SANS le champ de contexte (formulaire vendor Filament)', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $this->withoutDossierContextHeader()
        ->post(route('filament.admin.auth.logout'))
        ->assertRedirect();

    expect(Auth::check())->toBeFalse();
});
