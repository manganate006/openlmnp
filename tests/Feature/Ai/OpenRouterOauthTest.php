<?php

use App\Models\AiUsageLog;
use App\Models\User;
use App\Services\Ai\OpenRouter\OpenRouterOauth;
use Illuminate\Support\Facades\Http;

/**
 * « Se connecter avec OpenRouter » — remise d'une clé par OAuth PKCE.
 *
 * Ce qui se joue ici n'est pas le confort d'un bouton, c'est la clé d'API d'un
 * utilisateur : on vérifie autant les refus que le chemin heureux. Tous les
 * refus doivent survenir SANS aucune requête sortante — `Http::preventStrayRequests()`,
 * actif globalement, en tient lieu d'assertion.
 *
 * ⚠️ Un seul `Http::fake()` par test : deux s'empilent et la séquence s'épuise.
 * Et `/auth/key` (introspection) et `/auth/keys` (échange) sont deux motifs
 * distincts, à ne pas confondre.
 */
const BASE_OAUTH = 'https://openrouter.test/api/v1';
const ECHANGE = BASE_OAUTH . '/auth/keys';
const CLE_REMISE = 'sk-or-v1-cle-remise-par-oauth';

beforeEach(function () {
    config([
        'ai.enabled' => true,
        'ai.api_key' => null,
        'ai.allow_user_keys' => true,
        'ai.oauth_enabled' => true,
        // Épinglé : l'hôte de test n'est pas openrouter.ai.
        'ai.provider' => 'openrouter',
        'ai.base_url' => BASE_OAUTH,
        'demo.email' => 'demo@openlmnp.fr',
    ]);

    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

/** Demande en cours, telle que `begin()` la pose en session. */
function demandeEnCours(User $user, string $verifier = 'verificateur-de-test-suffisamment-long-pour-la-rfc-7636'): array
{
    return [OpenRouterOauth::SESSION_KEY => [
        'verifier' => $verifier,
        'user_id' => $user->id,
        'issued_at' => now()->timestamp,
    ]];
}

function urlDeDepart(): string
{
    return route('filament.admin.ai.openrouter.start');
}

function urlDeRetour(array $parametres = ['code' => 'code-abc']): string
{
    return route('filament.admin.ai.openrouter.callback') . '?' . http_build_query($parametres);
}

// =============================================================================
// Aller
// =============================================================================

it('redirige vers OpenRouter avec un défi dérivé du vérificateur de session', function () {
    $reponse = $this->get(urlDeDepart());

    $verifier = session(OpenRouterOauth::SESSION_KEY)['verifier'];
    $attendu = rtrim(strtr(base64_encode(hash('sha256', $verifier, binary: true)), '+/', '-_'), '=');

    parse_str((string) parse_url($reponse->headers->get('Location'), PHP_URL_QUERY), $query);

    expect($query['code_challenge'])->toBe($attendu)
        ->and($query['code_challenge_method'])->toBe('S256')
        ->and($query['callback_url'])->toBe(route('filament.admin.ai.openrouter.callback'));
});

it('n\'expose jamais le vérificateur dans l\'URL d\'autorisation', function () {
    // Le publier reviendrait à supprimer la seule preuve d'identité du procédé.
    $reponse = $this->get(urlDeDepart());

    expect($reponse->headers->get('Location'))
        ->not->toContain(session(OpenRouterOauth::SESSION_KEY)['verifier']);
});

it('produit un vérificateur conforme à la RFC 7636', function () {
    $this->get(urlDeDepart());

    $verifier = session(OpenRouterOauth::SESSION_KEY)['verifier'];

    expect(strlen($verifier))->toBeGreaterThanOrEqual(43)
        ->and(strlen($verifier))->toBeLessThanOrEqual(128)
        ->and($verifier)->toMatch('/^[A-Za-z0-9\-._~]+$/');
});

it('construit le retour depuis l\'hôte réellement utilisé, pas depuis APP_URL', function () {
    // Une instance atteinte par son adresse de réseau local doit revenir au
    // même endroit : le navigateur ne connaît pas forcément APP_URL.
    config(['app.url' => 'http://localhost']);

    $reponse = $this->get('http://192.168.1.20:8090/ia/openrouter/connexion');

    parse_str((string) parse_url($reponse->headers->get('Location'), PHP_URL_QUERY), $query);

    expect($query['callback_url'])->toContain('192.168.1.20:8090');
});

it('refuse le départ quand la fonctionnalité n\'a pas lieu d\'être', function (array $config) {
    // 404 et non 403 : on ne renseigne pas sur la configuration de l'instance.
    config($config);

    $this->get(urlDeDepart())->assertNotFound();
    Http::assertNothingSent();
})->with([
    'IA désactivée' => [['ai.enabled' => false]],
    'clé fournie par l\'instance' => [['ai.api_key' => 'sk-or-v1-instance']],
    'clés utilisateur interdites' => [['ai.allow_user_keys' => false]],
    'remise de clé désactivée' => [['ai.oauth_enabled' => false]],
    'fournisseur sans remise de clé' => [['ai.provider' => 'auto', 'ai.base_url' => 'http://ollama:11434/v1']],
]);

it('refuse le départ sur un compte de démonstration', function () {
    // Ses identifiants sont publics : y attacher une vraie clé serait la donner.
    $this->actingAs(User::factory()->create(['is_demo' => true]));

    $this->get(urlDeDepart())->assertNotFound();
});

it('exige une session authentifiée', function () {
    auth()->logout();

    $this->get(urlDeDepart())->assertRedirect(route('filament.admin.auth.login'));
    Http::assertNothingSent();
});

// =============================================================================
// Retour — chemin heureux
// =============================================================================

it('échange le code, enregistre la clé et revient au profil', function () {
    Http::fake([ECHANGE => Http::response(['key' => CLE_REMISE])]);

    $this->withSession(demandeEnCours($this->user))
        ->get(urlDeRetour())
        ->assertRedirect(route('filament.admin.auth.profile'));

    expect($this->user->refresh()->ai_api_key)->toBe(CLE_REMISE);

    Http::assertSent(fn ($request) => $request->url() === ECHANGE
        && $request['code'] === 'code-abc'
        && $request['code_verifier'] === demandeEnCours($this->user)[OpenRouterOauth::SESSION_KEY]['verifier']
        && $request['code_challenge_method'] === 'S256');
});

it('n\'envoie aucun en-tête d\'autorisation à l\'échange', function () {
    // On n'a pas encore de clé : réutiliser le client d'AiClient enverrait un
    // « Bearer » vide, que le fournisseur refuserait.
    Http::fake([ECHANGE => Http::response(['key' => CLE_REMISE])]);

    $this->withSession(demandeEnCours($this->user))->get(urlDeRetour());

    Http::assertSent(fn ($request) => ! $request->hasHeader('Authorization'));
});

it('journalise la remise de clé sans jamais écrire la clé ni le code', function () {
    Http::fake([ECHANGE => Http::response(['key' => CLE_REMISE])]);

    $this->withSession(demandeEnCours($this->user))->get(urlDeRetour());

    $lignes = AiUsageLog::withoutGlobalScopes()->get();

    expect($lignes)->toHaveCount(1)
        ->and($lignes->first()->feature)->toBe('key_oauth')
        ->and($lignes->first()->status)->toBe('ok')
        ->and($lignes->first()->meta)->toBeNull()
        // Sonde anti-fuite sur le journal entier, sérialisé.
        ->and(json_encode($lignes->toArray()))
        ->not->toContain(CLE_REMISE)
        ->not->toContain('code-abc');
});

it('consomme le vérificateur : rejouer le même retour échoue', function () {
    Http::fake([ECHANGE => Http::response(['key' => CLE_REMISE])]);

    $session = demandeEnCours($this->user);
    $this->withSession($session)->get(urlDeRetour());

    // Second passage : le vérificateur a été retiré de la session au premier.
    // Sans cela, un code intercepté resterait rejouable.
    $this->get(urlDeRetour())->assertRedirect(route('filament.admin.auth.profile'));

    Http::assertSentCount(1);
    expect($this->user->refresh()->ai_api_key)->toBe(CLE_REMISE);
});

// =============================================================================
// Retour — refus, sans aucune requête sortante
// =============================================================================

it('refuse un retour sans demande en cours', function () {
    $this->get(urlDeRetour())->assertRedirect(route('filament.admin.auth.profile'));

    Http::assertNothingSent();
    expect($this->user->refresh()->ai_api_key)->toBeNull();
});

it('refuse un retour sans code : l\'utilisateur a annulé', function () {
    $this->withSession(demandeEnCours($this->user))
        ->get(route('filament.admin.ai.openrouter.callback'))
        ->assertRedirect(route('filament.admin.auth.profile'));

    Http::assertNothingSent();
    expect($this->user->refresh()->ai_api_key)->toBeNull();
});

it('refuse une demande ouverte depuis un autre compte', function () {
    // Bascule de compte dans le même navigateur : la demande ne suit pas.
    //
    // La redirection est assertée, et pas seulement l'absence de requête :
    // sans elle, retirer le garde laisserait le test vert — l'appel sortant
    // serait alors bloqué par preventStrayRequests(), donc « rien envoyé »
    // resterait vrai alors que le garde aurait disparu.
    $autre = User::factory()->create();

    $this->withSession(demandeEnCours($autre))
        ->get(urlDeRetour())
        ->assertRedirect(route('filament.admin.auth.profile'));

    Http::assertNothingSent();
    expect($this->user->refresh()->ai_api_key)->toBeNull();
});

it('refuse une demande de plus de dix minutes', function () {
    // Calé sur l'expiration des codes chez le fournisseur : au-delà, l'échange
    // échouerait de toute façon, autant ne pas le tenter.
    $session = demandeEnCours($this->user);
    $session[OpenRouterOauth::SESSION_KEY]['issued_at'] = now()->subMinutes(11)->timestamp;

    $this->withSession($session)
        ->get(urlDeRetour())
        ->assertRedirect(route('filament.admin.auth.profile'));

    Http::assertNothingSent();
    expect($this->user->refresh()->ai_api_key)->toBeNull();
});

it('exige une session authentifiée sur le retour', function () {
    auth()->logout();

    $this->get(urlDeRetour())->assertRedirect(route('filament.admin.auth.login'));
    Http::assertNothingSent();
});

// =============================================================================
// Retour — le fournisseur refuse ou répond mal
// =============================================================================

it('n\'enregistre rien quand le fournisseur refuse le code', function () {
    // C'est aussi ce qui fait échouer une injection de clé : le code d'un tiers
    // a été émis contre SON défi, pas contre notre vérificateur.
    Http::fake([ECHANGE => Http::response(['error' => 'invalid_grant'], 403)]);

    $this->withSession(demandeEnCours($this->user))
        ->get(urlDeRetour())
        ->assertRedirect(route('filament.admin.auth.profile'));

    expect($this->user->refresh()->ai_api_key)->toBeNull();

    $ligne = AiUsageLog::withoutGlobalScopes()->sole();

    expect($ligne->status)->toBe('error')
        ->and($ligne->error_code)->toBe('AiOauthFailed')
        ->and($ligne->meta)->toBeNull();
});

it('n\'enregistre rien quand le fournisseur est injoignable', function () {
    Http::fake(fn () => throw new \Illuminate\Http\Client\ConnectionException('injoignable'));

    $this->withSession(demandeEnCours($this->user))
        ->get(urlDeRetour())
        ->assertRedirect(route('filament.admin.auth.profile'));

    expect($this->user->refresh()->ai_api_key)->toBeNull();
});

it('refuse une réponse sans clé exploitable', function (array $corps) {
    Http::fake([ECHANGE => Http::response($corps)]);

    $this->withSession(demandeEnCours($this->user))
        ->get(urlDeRetour())
        ->assertRedirect(route('filament.admin.auth.profile'));

    expect($this->user->refresh()->ai_api_key)->toBeNull();
})->with([
    'aucune clé' => [[]],
    'clé vide' => [['key' => '   ']],
    'clé non textuelle' => [['key' => ['sk-or-v1-x']]],
    // Serait tronquée en base, donc inutilisable, et le dire vaut mieux que
    // laisser l'utilisateur croire que c'est fait.
    'clé trop longue' => [['key' => 'sk-or-v1-' . str_repeat('x', 300)]],
]);

// =============================================================================
// Disponibilité et avertissements
// =============================================================================

it('n\'avertit pas quand le retour se fait en HTTPS ou sur localhost', function (string $url) {
    expect(OpenRouterOauth::insecureCallbackWarning($url))->toBeNull();
})->with([
    'https' => ['https://app.openlmnp.fr/ia/openrouter/retour'],
    'localhost' => ['http://localhost:8090/ia/openrouter/retour'],
    'boucle locale' => ['http://127.0.0.1:8000/ia/openrouter/retour'],
]);

it('avertit quand le retour se fera en HTTP sur une autre adresse', function () {
    // Le fournisseur ne garantit que HTTPS et localhost : on prévient sans
    // désactiver, la saisie manuelle restant disponible juste en dessous.
    $avertissement = OpenRouterOauth::insecureCallbackWarning('http://192.168.1.20:8090/ia/openrouter/retour');

    expect($avertissement)->toContain('192.168.1.20')
        ->and($avertissement)->toContain('HTTPS');
});
