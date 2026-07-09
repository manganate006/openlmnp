<?php

use App\Models\User;
use App\Providers\AppServiceProvider;
use Illuminate\Auth\Events\Login;

beforeEach(function () {
    $this->user = User::factory()->create();
});

// === DÉSACTIVÉ PAR DÉFAUT (installations self-hosted) ===

it('does not inject any tracking when GTM is not configured', function () {
    config(['services.gtm.id' => null]);

    $this->get('/login')
        ->assertOk()
        ->assertDontSee('googletagmanager', false)
        ->assertDontSee("'dataLayer'", false)
        ->assertDontSee('ns.html', false);
});

// === ACTIVÉ PAR VARIABLE D'ENVIRONNEMENT ===

it('injects the GTM snippet when a container id is configured', function () {
    config([
        'services.gtm.id' => 'GTM-TEST123',
        'services.gtm.server_url' => 'https://sgtm.example.com',
        'services.gtm.script_path' => '/lib.js',
    ]);

    $this->get('/login')
        ->assertOk()
        ->assertSee('GTM-TEST123', false)
        ->assertSee('https:\/\/sgtm.example.com\/lib.js', false)
        ->assertSee('https://sgtm.example.com/ns.html?id=GTM-TEST123', false)
        ->assertSee("window.addEventListener('analytics'", false);
});

it('uses the standard Google servers by default', function () {
    config(['services.gtm.id' => 'GTM-TEST123']);

    $this->get('/login')
        ->assertOk()
        ->assertSee('https:\/\/www.googletagmanager.com\/gtm.js', false);
});

// === ÉVÉNEMENTS AUTH (file d'attente en session) ===

it('pushes a login event on the page following authentication', function () {
    config(['services.gtm.id' => 'GTM-TEST123']);

    event(new Login('web', $this->user, false));

    $this->actingAs($this->user)
        ->get('/')
        ->assertOk()
        ->assertSee('\\u0022event\\u0022:\\u0022login\\u0022', false)
        ->assertSee('\\u0022method\\u0022:\\u0022email\\u0022', false);
});

it('flags demo logins with the demo method', function () {
    config(['services.gtm.id' => 'GTM-TEST123']);

    $demoUser = User::factory()->create([
        'is_demo' => true,
        'demo_expires_at' => now()->addHours(24),
    ]);

    event(new Login('web', $demoUser, false));

    $this->actingAs($demoUser)
        ->get('/')
        ->assertOk()
        ->assertSee('\\u0022event\\u0022:\\u0022login\\u0022', false)
        ->assertSee('\\u0022method\\u0022:\\u0022demo\\u0022', false);
});

it('registers a sign_up event through the full registration flow', function () {
    config(['services.gtm.id' => 'GTM-TEST123']);

    event(new Illuminate\Auth\Events\Registered($this->user));

    $this->actingAs($this->user)
        ->get('/')
        ->assertOk()
        ->assertSee('\\u0022event\\u0022:\\u0022sign_up\\u0022', false);
});

it('queues multiple analytics events without overwriting them', function () {
    AppServiceProvider::queueAnalyticsEvent(['event' => 'sign_up', 'method' => 'email']);
    AppServiceProvider::queueAnalyticsEvent(['event' => 'login', 'method' => 'email']);

    expect(session('analytics'))->toHaveCount(2);
});

it('does not leak queued analytics events indefinitely', function () {
    config(['services.gtm.id' => 'GTM-TEST123']);

    // En production l'événement part PENDANT la requête de login (qui redirige) :
    // le flash n'est visible que sur la page suivante. Ici event() est déclenché
    // hors requête, le flash survit donc une requête de plus avant vieillissement.
    event(new Login('web', $this->user, false));

    $this->actingAs($this->user)->get('/')->assertSee('\\u0022event\\u0022:\\u0022login\\u0022', false);
    $this->actingAs($this->user)->get('/');
    $this->actingAs($this->user)->get('/')->assertDontSee('\\u0022event\\u0022:\\u0022login\\u0022', false);
});
