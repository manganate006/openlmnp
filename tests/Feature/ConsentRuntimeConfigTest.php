<?php

use App\Support\ConsentState;

// Rappel du piège n°1 de l'image Docker : `docker-entrypoint.sh` ne recopie vers `.env`
// qu'une allowlist FIXE. Une variable absente de cette liste est silencieusement ignorée
// en production, `-e` ou pas.
//
// Ce test relit les `env()` de `config/consent.php` plutôt que d'en tenir une copie :
// ajouter un réglage sans l'ajouter à l'allowlist fait échouer la suite, au lieu de
// produire une option qui ne répond pas en production.

it('exposes every consent setting through the entrypoint allowlist', function () {
    $config = file_get_contents(config_path('consent.php'));
    $entrypoint = file_get_contents(base_path('docker-entrypoint.sh'));

    preg_match_all("/env\(\s*'([A-Z0-9_]+)'/", $config, $matches);
    $variables = array_unique($matches[1]);

    expect($variables)->not->toBeEmpty();

    foreach ($variables as $variable) {
        expect($entrypoint)->toContain($variable);
    }
});

/**
 * ⚠️ Le défaut vide n'est pas une omission, c'est la valeur juste pour une instance
 * auto-hébergée : elle tourne sur un seul hôte et n'a rien à partager. Et un domaine écrit
 * en dur y serait pire qu'inutile — un navigateur REFUSE un cookie dont le `domain` ne
 * couvre pas l'hôte courant, donc le consentement deviendrait impossible à mémoriser et le
 * bandeau reparaîtrait à chaque page, sans le moindre message d'erreur.
 */
it('scopes the consent cookie to the host by default', function () {
    expect(config('consent.cookie_domain'))->toBeNull();
});

it('writes no domain attribute when none is configured', function () {
    config(['services.gtm.id' => 'GTM-TEST123', 'consent.cookie_domain' => null]);
    \App\Providers\AppServiceProvider::exemptThirdPartyCookiesFromEncryption();

    expect($this->get('/login')->getContent())->not->toContain('domain=');
});

/**
 * Le cas du cloud : vitrine et application sont deux hôtes d'un même domaine, et `_ga` y est
 * déjà partagé (gtag le pose en `cookieDomain=auto`). Sans cette portée, accepter sur la
 * vitrine donnerait un `_ga` valable ici pendant que l'application redemanderait quand même,
 * et un refus là-bas ne protégerait pas ici.
 */
it('scopes the consent cookie to the registrable domain when configured', function () {
    config(['services.gtm.id' => 'GTM-TEST123', 'consent.cookie_domain' => '.openlmnp.fr']);
    \App\Providers\AppServiceProvider::exemptThirdPartyCookiesFromEncryption();

    expect($this->get('/login')->getContent())->toContain('domain=.openlmnp.fr');
});

/**
 * La portée ne doit jamais court-circuiter la porte du conteneur : pas de GTM configuré,
 * pas de traceur, donc pas de bandeau — et donc aucun cookie, quelle que soit la portée.
 */
it('still asks nothing when no container is configured, whatever the domain', function () {
    config(['services.gtm.id' => null, 'consent.cookie_domain' => '.openlmnp.fr']);

    $html = $this->get('/login')->getContent();

    expect($html)->not->toContain('olmnp-consent-banner')
        ->and($html)->not->toContain(ConsentState::COOKIE);
});
