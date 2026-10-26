<?php

use App\Exceptions\StaleDossierContextException;
use App\Http\Middleware\VerifyDossierContext;
use App\Models\User;
use App\Support\DossierContext;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;

/**
 * D3 du test bêta du 2026-10-15 : un vieil onglet recevait bien son 419, mais l'utilisateur
 * voyait la boîte native de Livewire, en anglais (« This page has expired. Would you like to
 * refresh the page? »). Remplacée par une fenêtre française, qui recharge la page, et qui
 * distingue le changement de dossier dans un autre onglet d'une simple session expirée.
 *
 * Le comportement JS lui-même (interception du 419, fenêtre, rechargement) est vérifié au
 * navigateur : captures `_admin/docs/tests-beta-2026-10-15/corrige2/d3-*`. Ici : le serveur
 * signale bien le cas, et chaque page embarque bien le script.
 *
 * `creerDossierLiePourVieilOnglet()` : fonction globale, voir VieilOngletContextTest.php.
 */
beforeEach(function () {
    config(['dossiers.enabled' => true, 'services.provisioning.token' => null, 'dossiers.quota' => null]);
});

it('un contexte périmé lève StaleDossierContextException, toujours un TokenMismatchException', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    DossierContext::contextId();

    $request = Request::create('/livewire/update', 'POST');
    $request->headers->set('X-Livewire', '1');
    $request->headers->set(VerifyDossierContext::HEADER, 'perime');
    $request->setUserResolver(fn () => $user);

    try {
        (new VerifyDossierContext)->handle($request, fn () => response('suite'));
        $this->fail('aucune exception levée');
    } catch (StaleDossierContextException $e) {
        expect($e)->toBeInstanceOf(TokenMismatchException::class);
    }
});

it('vieil onglet : 419 avec l\'en-tête de contexte périmé et une page qui dit ce qui s\'est passé', function () {
    $principal = User::factory()->create();
    $dossier = creerDossierLiePourVieilOnglet($principal);
    $this->actingAs($principal);

    $reponse = $this->post(
        route('filament.admin.dossiers.open', $dossier->public_id),
        [],
        ['X-OpenLMNP-Context' => 'contexte-d-un-autre-onglet']
    );

    $reponse->assertStatus(419)
        ->assertHeader(StaleDossierContextException::HEADER, '1')
        ->assertSee('Vous avez changé de dossier dans un autre onglet')
        ->assertDontSee('Reconnectez-vous');
});

it('un 419 ordinaire (jeton CSRF) garde la page « Votre session a expiré », sans l\'en-tête', function () {
    \Illuminate\Support\Facades\Route::get('/__test-csrf-419', fn () => throw new TokenMismatchException('CSRF token mismatch.'))
        ->middleware('web');

    $reponse = $this->get('/__test-csrf-419');

    $reponse->assertStatus(419)
        ->assertHeaderMissing(StaleDossierContextException::HEADER)
        ->assertSee('Votre session a expiré');
});

it('chaque page du panel intercepte le 419 de Livewire avec un message français', function () {
    $this->actingAs(User::factory()->create());

    $html = $this->get('/profile')->assertOk()->getContent();

    expect($html)->toContain('Livewire.interceptRequest(')
        ->and($html)->toContain('response.status !== 419')
        ->and($html)->toContain('preventDefault()')
        ->and($html)->toContain('Vous avez changé de dossier dans un autre onglet')
        ->and($html)->toContain('Cette page a expiré')
        ->and($html)->toContain('Recharger la page')
        ->and($html)->toContain('window.location.reload()')
        ->and($html)->toContain(StaleDossierContextException::HEADER);
});

it('la page de connexion intercepte aussi le 419 (jeton CSRF expiré avant connexion)', function () {
    $html = $this->get(route('filament.admin.auth.login'))->assertOk()->getContent();

    expect($html)->toContain('Livewire.interceptRequest(')
        ->and($html)->toContain('Cette page a expiré')
        // Pas d'identifiant de contexte sans utilisateur connecté.
        ->and($html)->not->toContain('<meta name="openlmnp-context"');
});
