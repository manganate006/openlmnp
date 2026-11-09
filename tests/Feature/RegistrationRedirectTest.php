<?php

use App\Models\User;
use App\Providers\Filament\AdminPanelProvider;
use Filament\Panel;

/*
 * /register quand l'inscription publique est fermée : Filament ne déclare pas sa route,
 * et l'URL rendait donc un 404 nu. Une route de repli est posée à la place (voir la branche
 * `else` d'AdminPanelProvider), qui mène à la page de souscription si l'instance en déclare
 * une, et à /login avec une explication sinon.
 *
 * POURQUOI ON RECONSTRUIT UN PANEL au lieu d'appeler $this->get('/register') tel quel : la
 * route est posée au BOOT du panel, et dans un test HTTP le panel est déjà booté avec la
 * configuration par défaut (base vierge → inscription ouverte). C'est l'avertissement en
 * tête de RegistrationFlagTest, et la raison pour laquelle le 404 n'était couvert par aucun
 * test jusqu'ici.
 *
 * On rejoue donc la production dans son ordre : construire le panel avec la configuration
 * voulue, puis exécuter les fermetures de routes qu'il a collectées. Laravel indexe ses
 * routes par URI (RouteCollection::addToCollections), donc la /register posée ici REMPLACE
 * celle du boot. Reconstruire l'application entière (refreshApplication) serait le réflexe
 * naturel, et détruirait la base SQLite :memory: du test avec sa transaction en cours.
 */
function replayPanelRoutes(): Panel
{
    $panel = (new AdminPanelProvider(app()))->panel(Panel::make());

    foreach ($panel->getRoutes() as $routes) {
        $routes($panel);
    }

    return $panel;
}

it('sends /register to the subscription page when registration is closed', function () {
    config([
        'app.allow_registration' => 'false',
        'services.website.signup_url' => 'https://openlmnp.fr/tarifs#creer-un-compte',
    ]);

    expect(replayPanelRoutes()->hasRegistration())->toBeFalse();

    $this->get('/register')
        // 302 et pas 301 : un 301 resterait dans le cache du navigateur, et /register ne
        // reviendrait jamais pour qui l'a visité une fois si l'instance rouvrait sa porte.
        ->assertStatus(302)
        ->assertRedirect('https://openlmnp.fr/tarifs#creer-un-compte');
});

it('sends /register back to the login page when the instance declares no subscription page', function () {
    // Le cas du dépôt public et de toute instance auto-hébergée : rien à vendre, nulle part
    // où envoyer le visiteur — mais pas de cul-de-sac pour autant.
    config([
        'app.allow_registration' => 'false',
        'services.website.signup_url' => '',
    ]);

    replayPanelRoutes();

    $this->get('/register')->assertRedirect('/login?inscription=fermee');
});

it('explains on the login page why registration was refused', function () {
    $this->get('/login?inscription=fermee')
        ->assertOk()
        ->assertSee('La création de compte est fermée sur cette instance.');
});

it('shows no such explanation on a plain login page', function () {
    $this->get('/login')
        ->assertOk()
        ->assertDontSee('La création de compte est fermée sur cette instance.');
});

it('leaves the real registration page alone while registration is open', function () {
    config([
        'app.allow_registration' => 'true',
        'services.website.signup_url' => 'https://openlmnp.fr/tarifs#creer-un-compte',
    ]);

    $panel = replayPanelRoutes();

    // Rien n'est posé du tout : la redirection ne doit pas seulement PERDRE face à la vraie
    // page d'inscription, elle ne doit pas exister.
    expect($panel->hasRegistration())->toBeTrue()
        ->and($panel->getRoutes())->toBeEmpty();

    $this->get('/register')->assertOk();
});

it('closes the door in auto mode once the first account exists', function () {
    config([
        'app.allow_registration' => 'auto',
        'services.website.signup_url' => 'https://openlmnp.fr/tarifs#creer-un-compte',
    ]);
    User::factory()->create();

    replayPanelRoutes();

    $this->get('/register')->assertRedirect('https://openlmnp.fr/tarifs#creer-un-compte');
});
