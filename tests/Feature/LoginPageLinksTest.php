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

// === GARDE-FOUS DE RENDU ===
//
// Le CSS servi par le panel Filament ne contient AUCUN utilitaire Tailwind : le panel
// n'a pas de viteTheme (retiré par c10a7c6f), seules les classes fi-* existent. Une vue
// écrite en Tailwind pur s'affiche donc sans aucune mise en forme — et un <svg> qui n'a
// ni width/height ni CSS de dimension occupe 100 % de la largeur de son conteneur.
// C'est exactement ce qui est arrivé à cette vue entre f65c60ec (31/07/2026) et sa
// réparation : deux icônes de ~800 px de haut sur la page de connexion.
// Les deux tests ci-dessous verrouillent la cause racine, que les assertSee ci-dessus
// n'avaient pas vue passer.

it('sizes every icon of the login hook explicitly', function () {
    config(['demo.enabled' => true, 'services.website.url' => 'https://openlmnp.fr']);

    $html = view('filament.auth.demo-button')->render();

    preg_match_all('/<svg\b[^>]*>/i', $html, $matches);

    expect($matches[0])->not->toBeEmpty();

    foreach ($matches[0] as $tag) {
        expect($tag)->toMatch('/\bwidth=/i')
            ->and($tag)->toMatch('/\bheight=/i');
    }
});

it('styles the login hook without relying on Tailwind utilities', function () {
    config(['demo.enabled' => true, 'services.website.url' => 'https://openlmnp.fr']);

    $html = view('filament.auth.demo-button')->render();

    preg_match_all('/\bclass="([^"]*)"/i', $html, $matches);

    $classes = collect($matches[1])
        ->flatMap(fn (string $attribute) => preg_split('/\s+/', trim($attribute), -1, PREG_SPLIT_NO_EMPTY))
        ->unique();

    expect($classes)->not->toBeEmpty();

    // Seules les classes scopées de la vue (olmnp-login-*) et les composants Filament
    // (fi-*) existent réellement dans le CSS du panel. Tout le reste est inerte.
    $unknown = $classes->reject(fn (string $class) => str_starts_with($class, 'olmnp-login-')
        || str_starts_with($class, 'fi-'));

    expect($unknown->all())->toBe([]);
});
