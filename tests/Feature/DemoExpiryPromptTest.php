<?php

use App\Livewire\DemoExpiryPrompt;
use App\Models\User;
use App\Notifications\DemoResumeLink;
use App\Support\DemoExpiry;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

beforeEach(function () {
    config()->set('demo.enabled', true);
    config()->set('demo.ttl_hours', 24);
    config()->set('demo.extended_ttl_days', 7);
    config()->set('demo.min_gap_hours', 2);
    config()->set('demo.reminders', '96:banner,24:modal,23:banner,18:banner,12:banner,6:modal,1:modal');
    config()->set('demo.links.pro', '');
    Notification::fake();
});

/** Un bien minimal, sur le modèle des autres tests du dépôt. */
function demoProperty(User $user, string $name): \App\Models\Property
{
    return \App\Models\Property::create([
        'user_id' => $user->id,
        'name' => $name,
        'address' => '1 rue du Test',
        'city' => 'Lyon',
        'postal_code' => '69003',
        'type' => 'apartment',
        'total_area' => 45,
        'rented_area' => 45,
        'acquisition_date' => '2022-01-01',
        'acquisition_price' => 20000000,
        'land_percentage' => 15,
        'rental_start_date' => '2022-03-01',
        'rental_type' => 'seasonal',
        'is_primary_residence' => false,
    ]);
}

function actingOnSandbox(float $hoursLeft, array $attrs = []): User
{
    $user = User::factory()->create(array_merge([
        'is_demo' => true,
        'demo_expires_at' => Carbon::now()->addMinutes((int) round($hoursLeft * 60)),
    ], $attrs));

    test()->actingAs($user);

    return $user;
}

it('stays out of the way for anyone who is not on a sandbox', function () {
    $this->actingAs(User::factory()->create(['is_demo' => false]));

    // Rien du tout, pas même la feuille de style : une instance qui n'est pas en
    // démonstration ne doit pas servir 6 ko de CSS mort sur chacune de ses pages.
    Livewire::test(DemoExpiryPrompt::class)
        ->assertSet('applies', false)
        ->assertDontSee('<style>', false);
});

it('shows the countdown pill on a live sandbox', function () {
    actingOnSandbox(20);

    Livewire::test(DemoExpiryPrompt::class)
        ->assertSet('applies', true)
        ->assertSee('dx-pill', false);
});

it('refuses a threshold the visitor has not reached', function () {
    // La minuterie tourne dans le navigateur : sans revalidation serveur, il suffirait
    // d'appeler reach() pour faire marquer tous les paliers comme servis.
    $user = actingOnSandbox(20);

    Livewire::test(DemoExpiryPrompt::class)
        ->call('reach', 1)
        ->assertSet('step', 'idle');

    expect($user->fresh()->demo_reminders_seen)->toBeEmpty();
});

it('opens the banner then the modal at the right thresholds', function () {
    actingOnSandbox(23);
    Livewire::test(DemoExpiryPrompt::class)->call('reach', 23)->assertSet('step', 'banner');

    actingOnSandbox(6);
    Livewire::test(DemoExpiryPrompt::class)->call('reach', 6)->assertSet('step', 'modal');
});

it('falls back to the extension when no commercial target is configured', function () {
    // Auto-hébergé : `demo.links.pro` est vide. « Garder mes données » ne peut alors
    // vouloir dire que « prolonger » — surtout pas afficher un cadre vide en guise d'offre.
    actingOnSandbox(6);

    Livewire::test(DemoExpiryPrompt::class)
        ->call('keepData')
        ->assertSet('step', 'extend')
        ->assertSet('offerUrl', '');
});

it('opens the offer iframe and freezes the sandbox while the visitor pays', function () {
    config()->set('demo.links.pro', 'https://openlmnp.fr/migrer');
    $user = actingOnSandbox(1);

    $component = Livewire::test(DemoExpiryPrompt::class)->call('keepData')->assertSet('step', 'offer');

    $user->refresh();

    expect($user->demo_claim_token)->not->toBeEmpty()
        ->and($component->get('offerUrl'))->toContain('demo='.$user->demo_claim_token)
        ->and($component->get('offerUrl'))->toContain('embed=1');

    // Quelqu'un qui sort sa carte à une heure de l'effacement ne doit pas revenir sur un
    // compte purgé : l'expiration est repoussée le temps du paiement.
    expect($user->demo_expires_at->greaterThan(Carbon::now()->addDays(6)))->toBeTrue();
});

it('sends the refusal straight to the extension, without leaving the sandbox', function () {
    config()->set('demo.links.pro', 'https://openlmnp.fr/migrer');
    actingOnSandbox(6);

    Livewire::test(DemoExpiryPrompt::class)
        ->call('keepData')
        ->call('declineOffer')
        ->assertSet('step', 'extend')
        ->assertSet('offerUrl', '');
});

it('requires a valid address and an explicit consent to extend', function () {
    $user = actingOnSandbox(6);

    Livewire::test(DemoExpiryPrompt::class)
        ->set('email', 'pas-une-adresse')
        ->set('consent', true)
        ->call('extend')
        ->assertHasErrors('email');

    Livewire::test(DemoExpiryPrompt::class)
        ->set('email', 'visiteur@exemple.fr')
        ->set('consent', false)
        ->call('extend')
        ->assertHasErrors('consent');

    expect($user->fresh()->demo_extended_at)->toBeNull();
    Notification::assertNothingSent();
});

it('extends the sandbox, records the consent and sends the resume link', function () {
    $user = actingOnSandbox(1);

    Livewire::test(DemoExpiryPrompt::class)
        ->set('email', 'visiteur@exemple.fr')
        ->set('consent', true)
        ->call('extend')
        ->assertSet('step', 'extended')
        ->assertSet('canExtend', false);

    $user->refresh();

    expect($user->demo_email)->toBe('visiteur@exemple.fr')
        ->and($user->demo_email_consent_at)->not->toBeNull()
        ->and($user->demo_extended_at)->not->toBeNull()
        ->and($user->demo_expires_at->greaterThan(Carbon::now()->addDays(6)))->toBeTrue()
        // Les paliers repartent de zéro : 96 h et 24 h n'ont jamais été servis.
        ->and($user->demo_reminders_seen)->toBe([]);

    Notification::assertSentTo($user, DemoResumeLink::class);
});

it('refuses to extend twice', function () {
    $user = actingOnSandbox(6, ['demo_extended_at' => Carbon::now()->subDay()]);

    Livewire::test(DemoExpiryPrompt::class)
        ->set('email', 'visiteur@exemple.fr')
        ->set('consent', true)
        ->call('extend')
        ->assertSet('step', 'idle');

    expect($user->fresh()->demo_email)->toBeNull();
    Notification::assertNothingSent();
});

it('emits every analytics event the GTM wiring expects', function () {
    // ⚠️ Un événement poussé dans le dataLayer sans trigger GTM est PERDU EN SILENCE : ni
    // erreur JS, ni test rouge, ni alerte. Le mode de panne s'est produit cinq fois dans ce
    // dépôt. Ce test fige les NOMS côté émetteur, pour qu'un renommage casse ici plutôt que
    // de vider un rapport d'analyse sans prévenir. Sur une instance auto-hébergée sans
    // conteneur de mesure, ces événements ne partent nulle part — et c'est très bien.
    config()->set('demo.links.pro', 'https://openlmnp.fr/migrer');
    actingOnSandbox(6);

    Livewire::test(DemoExpiryPrompt::class)
        ->call('reach', 6)
        ->assertDispatched('analytics', fn ($e, $p) => $p[0]['event'] === 'demo_expiry_prompted'
            && $p[0]['demo_threshold'] === 6
            && $p[0]['demo_format'] === 'modal')
        ->call('keepData')
        ->assertDispatched('analytics', fn ($e, $p) => $p[0]['event'] === 'demo_claim_started')
        ->call('declineOffer')
        ->assertDispatched('analytics', fn ($e, $p) => $p[0]['event'] === 'demo_offer_declined')
        ->set('email', 'visiteur@exemple.fr')
        ->set('consent', true)
        ->call('extend')
        ->assertDispatched('analytics', fn ($e, $p) => $p[0]['event'] === 'demo_extended');
});

it('counts what is at risk on the visitor own data, ignoring the sample', function () {
    $user = actingOnSandbox(6);
    $sample = demoProperty($user, 'Exemple');
    $mine = demoProperty($user, 'Le mien');

    $user->forceFill(['demo_seed' => ['property_id' => $sample->id, 'fiscal_year_ids' => []]])->save();

    $component = Livewire::test(DemoExpiryPrompt::class);

    // Un visiteur qui n'a fait que cliquer ne doit pas voir un écran dramatique : seul ce
    // qu'il a saisi lui-même compte, le jeu d'exemple n'a été « perdu » par personne.
    expect($component->get('atRisk')['properties'])->toBe(1)
        ->and($mine->exists)->toBeTrue();
});
