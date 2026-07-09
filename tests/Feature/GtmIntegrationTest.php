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

// === USER TYPE (distinction démo / utilisateur réel dans GA4) ===

it('marks anonymous pages with visitor user_type', function () {
    config(['services.gtm.id' => 'GTM-TEST123']);

    $this->get('/login')
        ->assertOk()
        ->assertSee("user_type: 'visitor'", false);
});

it('marks authenticated pages with user user_type', function () {
    config(['services.gtm.id' => 'GTM-TEST123']);

    $this->actingAs($this->user)
        ->get('/')
        ->assertOk()
        ->assertSee("user_type: 'user'", false);
});

it('marks demo sandbox pages with demo user_type', function () {
    config(['services.gtm.id' => 'GTM-TEST123']);

    $demoUser = User::factory()->create([
        'is_demo' => true,
        'demo_expires_at' => now()->addHours(24),
    ]);

    $this->actingAs($demoUser)
        ->get('/')
        ->assertOk()
        ->assertSee("user_type: 'demo'", false);
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

// === CONVERSION DÉMO → INSCRIPTION (from_demo) ===

it('sets the demo-seen cookie when entering the demo sandbox', function () {
    config(['demo.enabled' => true, 'demo.ttl_hours' => 24, 'demo.max_accounts' => 200]);

    $this->get('/demo')
        ->assertRedirect('/')
        ->assertCookie('olmnp_demo_seen', '1');
});

it('flags sign_up with from_demo when the visitor has seen the demo', function () {
    $this->app['request']->cookies->set('olmnp_demo_seen', '1');

    event(new Illuminate\Auth\Events\Registered($this->user));

    $events = collect(session('analytics'));
    expect($events->firstWhere('event', 'sign_up')['from_demo'])->toBeTrue();
});

it('flags sign_up without from_demo when the visitor never saw the demo', function () {
    event(new Illuminate\Auth\Events\Registered($this->user));

    $events = collect(session('analytics'));
    expect($events->firstWhere('event', 'sign_up')['from_demo'])->toBeFalse();
});

// === ÉVÉNEMENTS PRODUIT (plan de taggage v6) ===

it('dispatches tutorial_begin when the onboarding wizard is shown', function () {
    $this->actingAs($this->user);

    Livewire\Livewire::test(\App\Filament\Pages\OnboardingWizard::class)
        ->assertDispatched('analytics', fn ($name, $params) => ($params[0]['event'] ?? null) === 'tutorial_begin');
});

it('queues property_added and tutorial_complete when the onboarding wizard completes', function () {
    $this->actingAs($this->user);

    Livewire\Livewire::test(\App\Filament\Pages\OnboardingWizard::class)
        ->set('data.name', 'Studio Test')
        ->set('data.type', 'apartment')
        ->set('data.rental_type', 'seasonal')
        ->set('data.address', '1 rue Test')
        ->set('data.city', 'Paris')
        ->set('data.postal_code', '75001')
        ->set('data.total_area', 30)
        ->set('data.rented_area', 30)
        ->set('data.acquisition_price', 100000)
        ->set('data.acquisition_date', '2024-01-01')
        ->set('data.rental_start_date', '2024-02-01')
        ->call('create');

    $events = collect(session('analytics'));
    expect($events->pluck('event'))->toContain('property_added', 'tutorial_complete')
        ->and($events->firstWhere('event', 'property_added')['source'])->toBe('wizard');
});

it('dispatches simulator_used when the simulator is opened', function () {
    $this->actingAs($this->user);

    Livewire\Livewire::test(\App\Filament\Pages\Simulator::class)
        ->assertDispatched('analytics', fn ($name, $params) => ($params[0]['event'] ?? null) === 'simulator_used');
});

it('dispatches projection_used with the projected duration', function () {
    $this->actingAs($this->user);

    Livewire\Livewire::test(\App\Filament\Pages\Projection::class)
        ->assertDispatched('analytics', fn ($name, $params) => ($params[0]['event'] ?? null) === 'projection_used'
            && ($params[0]['years'] ?? null) === 10);
});

it('queues fiscal_year_closed when a fiscal year is closed via the wizard', function () {
    $this->actingAs($this->user);

    Livewire\Livewire::test(\App\Filament\Pages\FiscalYearWizard::class)
        ->set('data.year', 2025)
        ->set('data.status', \App\Models\FiscalYear::STATUS_CLOSED)
        ->call('create');

    $events = collect(session('analytics'));
    expect($events->firstWhere('event', 'fiscal_year_closed')['fiscal_year'])->toBe(2025);
});

it('does not queue fiscal_year_closed for a draft fiscal year', function () {
    $this->actingAs($this->user);

    Livewire\Livewire::test(\App\Filament\Pages\FiscalYearWizard::class)
        ->set('data.year', 2025)
        ->set('data.status', \App\Models\FiscalYear::STATUS_DRAFT)
        ->call('create');

    expect(collect((array) session('analytics'))->firstWhere('event', 'fiscal_year_closed'))->toBeNull();
});

it('buckets imported row counts without exposing exact values', function () {
    expect(\App\Support\Analytics::rowsBucket(0))->toBe('0')
        ->and(\App\Support\Analytics::rowsBucket(1))->toBe('1-10')
        ->and(\App\Support\Analytics::rowsBucket(10))->toBe('1-10')
        ->and(\App\Support\Analytics::rowsBucket(11))->toBe('11-50')
        ->and(\App\Support\Analytics::rowsBucket(50))->toBe('11-50')
        ->and(\App\Support\Analytics::rowsBucket(51))->toBe('50+');
});
