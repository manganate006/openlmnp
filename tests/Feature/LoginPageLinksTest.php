<?php

// Bloc démo + lien « Découvrir OpenLMNP » injectés sous le formulaire de login
// via le render hook AUTH_LOGIN_FORM_AFTER (voir AdminPanelProvider) et pilotés
// par config : demo.enabled (DEMO_MODE) et services.website.url (OPENLMNP_WEBSITE_URL).

// === BLOC DÉMO (config demo.enabled) ===

it('shows the demo card on /login when demo mode is enabled', function () {
    config(['demo.enabled' => true]);

    $this->get('/login')
        ->assertOk()
        ->assertSee('Essayez la démo')
        ->assertSee('Sandbox sans inscription, données d\'exemple', false)
        ->assertSee(route('demo.start'), false);
});

it('hides the demo card on /login when demo mode is disabled', function () {
    config(['demo.enabled' => false]);

    $this->get('/login')
        ->assertOk()
        ->assertDontSee('Essayez la démo');
});

// === LIEN SITE OFFICIEL (config services.website.url) ===

it('shows the official website link on /login when a website url is configured', function () {
    config(['services.website.url' => 'https://openlmnp.fr']);

    $this->get('/login')
        ->assertOk()
        ->assertSee('Pas encore de compte ?')
        ->assertSee('Découvrir OpenLMNP')
        ->assertSee('https://openlmnp.fr', false);
});

it('hides the official website link on /login when the website url is empty', function () {
    // Cas d'une instance auto-hébergée qui vide OPENLMNP_WEBSITE_URL.
    config(['services.website.url' => null]);

    $this->get('/login')
        ->assertOk()
        ->assertDontSee('Découvrir OpenLMNP');
});
