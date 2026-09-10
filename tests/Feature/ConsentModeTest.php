<?php

use App\Models\User;
use App\Support\ConsentState;

/**
 * Bandeau de consentement et Consent Mode v2 dans le panel.
 *
 * ⚠️ CE QUI DISTINGUE CE DÉPÔT DE LA VITRINE : ici, la mesure est OPTIONNELLE. Une instance
 * auto-hébergée n'a aucun conteneur configuré, donc aucun traceur, donc aucune question à
 * poser. Les deux portes — conteneur et bandeau — sont la même condition, et c'est ce que
 * ces tests protègent : en poser une sans l'autre, c'est soit mesurer sans demander, soit
 * demander sans mesurer.
 */
beforeEach(function () {
    config([
        'services.gtm.id' => 'GTM-TEST123',
        'services.gtm.server_url' => 'https://sgtm.example.com',
        'services.gtm.script_path' => '/lib.js',
    ]);
    \App\Providers\AppServiceProvider::exemptThirdPartyCookiesFromEncryption();
});

it('ne demande rien quand aucun conteneur n\'est configuré', function () {
    // Le cas de l'auto-hébergement : ni traceur, ni bandeau.
    config(['services.gtm.id' => null]);

    $response = $this->get('/login');

    $response->assertOk()
        ->assertDontSee('olmnp-consent-banner')
        ->assertDontSee('gtag(', false);
});

it('pose les quatre signaux à denied tant que rien n\'a été répondu', function () {
    $html = $this->get('/login')->assertOk()->getContent();

    expect($html)->toContain("gtag('consent', 'default'");

    foreach (['ad_storage', 'analytics_storage', 'ad_user_data', 'ad_personalization'] as $signal) {
        expect($html)->toMatch('/'.$signal.": 'denied'/");
    }
});

/**
 * L'ordre est tout l'enjeu : des signaux posés après le conteneur ne retiennent plus rien,
 * et la page paraîtrait conforme à la lecture puisque les deux blocs y figurent.
 */
it('pose les signaux AVANT le chargement du conteneur', function () {
    $html = $this->get('/login')->getContent();

    $consentAt = strpos($html, "gtag('consent', 'default'");
    // ⚠️ Ancre sur `gtm.start`, pas sur l'URL : `@js()` encode en JSON, donc échappe les
    // barres obliques (`https:\/\/sgtm.example.com\/lib.js`). Chercher l'URL telle qu'on
    // l'a écrite dans la config ne trouve rien — et le test passerait au vert par accident
    // si l'assertion était inversée.
    $containerAt = strpos($html, "'gtm.start'");

    expect($consentAt)->not->toBeFalse()
        ->and($containerAt)->not->toBeFalse()
        ->and($consentAt)->toBeLessThan($containerAt);
});

it('affiche le bandeau tant que le choix n\'est pas fait', function () {
    $html = $this->get('/login')->getContent();

    expect($html)->toContain('olmnp-consent-banner')
        ->toContain('data-consent="accept"')
        ->toContain('data-consent="refuse"');
});

/** Refuser doit coûter exactement autant qu'accepter : un bouton, un clic. */
it('offre le refus au même coût que l\'acceptation', function () {
    $html = $this->get('/login')->getContent();

    expect(preg_match_all('/<button[^>]*data-consent="refuse"/', $html))->toBe(1)
        ->and(preg_match_all('/<button[^>]*data-consent="accept"/', $html))->toBe(1);
});

it('retire le bandeau une fois le choix exprimé', function (string $cookie) {
    $html = $this->withUnencryptedCookies([ConsentState::COOKIE => $cookie])
        ->get('/login')
        ->getContent();

    expect($html)->not->toContain('olmnp-consent-banner');
})->with(['v1.a1p1', 'v1.a0p0']);

/**
 * Le choix mémorisé est rendu côté SERVEUR. Le calculer en JavaScript imposerait un
 * aller-retour pendant lequel les tags se seraient prononcés sur le défaut : un utilisateur
 * consentant serait mesuré comme un refusant à chacune de ses visites.
 */
it('rend l\'acceptation mémorisée côté serveur', function () {
    $html = $this->withUnencryptedCookies([ConsentState::COOKIE => 'v1.a1p1'])
        ->get('/login')
        ->getContent();

    // `@js()` rend les chaînes entre guillemets SIMPLES : `'granted'`, pas `"granted"`.
    expect($html)->toContain("gtag('consent', 'update'")
        ->toContain("ad_storage: 'granted'")
        ->toContain("analytics_storage: 'granted'");
});

it('garde un refus mémorisé à denied', function () {
    $html = $this->withUnencryptedCookies([ConsentState::COOKIE => 'v1.a0p0'])
        ->get('/login')
        ->getContent();

    expect($html)->toContain("gtag('consent', 'update'")
        ->not->toContain("'granted'");
});

/**
 * Le cookie est posé par le navigateur, en clair : sans exception de chiffrement, Laravel le
 * mettrait à `null` et le bandeau reparaîtrait à chaque page, sans qu'aucune erreur ne soit
 * levée. La contre-épreuve est ce qui donne sa valeur au test.
 */
it('lit le cookie de consentement malgré le chiffrement des cookies', function () {
    $state = ConsentState::parse('v1.a1p1');

    expect($state->decided)->toBeTrue()
        ->and($state->analytics)->toBeTrue()
        ->and($state->ads)->toBeTrue();

    $reflection = new ReflectionProperty(\Illuminate\Cookie\Middleware\EncryptCookies::class, 'neverEncrypt');
    expect($reflection->getValue())->toContain(ConsentState::COOKIE);
});

it('vaut aussi pour un utilisateur connecté', function () {
    $this->actingAs(User::factory()->create());

    $html = $this->get('/')->getContent();

    expect($html)->toContain("gtag('consent', 'default'")
        ->toContain('olmnp-consent-banner');
});

/** Une valeur corrompue ne doit jamais accorder un consentement. */
it('traite une valeur illisible comme une absence de réponse', function (string $raw) {
    $state = ConsentState::parse($raw);

    expect($state->decided)->toBeFalse()
        ->and($state->analytics)->toBeFalse()
        ->and($state->ads)->toBeFalse();
})->with(['', 'granted', 'v2.a1p1', 'v1.a1', 'nimportequoi', 'v1.a2p1']);
