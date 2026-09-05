<?php

use App\Models\User;
use App\Notifications\DemoExpiring;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    config()->set('demo.enabled', true);
    config()->set('demo.ttl_hours', 24);
    config()->set('demo.extended_ttl_days', 7);
    config()->set('app.url', 'https://app.openlmnp.fr');
    Notification::fake();
});

function extendedSandbox(array $attrs = []): User
{
    return User::factory()->create(array_merge([
        'is_demo' => true,
        'demo_expires_at' => Carbon::now()->addHours(12),
        'demo_email' => 'visiteur@exemple.fr',
        'demo_email_consent_at' => Carbon::now()->subDays(6),
        'demo_extended_at' => Carbon::now()->subDays(6),
    ], $attrs));
}

it('warns a sandbox that is about to be wiped', function () {
    $user = extendedSandbox();

    $this->artisan('openlmnp:demo-expiry-notify')->assertSuccessful();

    Notification::assertSentTo($user, DemoExpiring::class);
});

it('never writes twice to the same sandbox', function () {
    $user = extendedSandbox();

    $this->artisan('openlmnp:demo-expiry-notify')->assertSuccessful();
    $this->artisan('openlmnp:demo-expiry-notify')->assertSuccessful();

    Notification::assertSentToTimes($user, DemoExpiring::class, 1);
});

it('says nothing to someone who never left an address', function () {
    // La prolongation est le seul point de capture du parcours. Pas d'adresse, pas de mot :
    // écrire à `demo-xxxx@demo.local` ne mènerait nulle part de toute façon.
    // Consentement présent mais adresse absente : incohérent, mais représentable. La
    // première rédaction annulait AUSSI le consentement — c'est lui qui retenait l'envoi,
    // et le test ne mesurait donc rien de l'adresse.
    extendedSandbox(['demo_email' => null]);

    $this->artisan('openlmnp:demo-expiry-notify')->assertSuccessful();

    Notification::assertNothingSent();
});

it('says nothing without an explicit consent', function () {
    // Un envoi transactionnel ne consulte PAS la liste de désinscription de Brevo, et
    // celui-ci part en SMTP direct : le consentement repose entièrement sur ce code.
    extendedSandbox(['demo_email_consent_at' => null]);

    $this->artisan('openlmnp:demo-expiry-notify')->assertSuccessful();

    Notification::assertNothingSent();
});

it('says nothing when the expiry date is unknown', function () {
    // LE test qui compte : un e-mail qui AFFIRME exige un signal CONNU. Sur une date
    // nulle, la commande doit RETENIR l'envoi, jamais le déclencher sur un défaut.
    extendedSandbox(['demo_expires_at' => null]);

    $this->artisan('openlmnp:demo-expiry-notify')->assertSuccessful();

    Notification::assertNothingSent();
});

it('says nothing to a sandbox that is still far from expiring', function () {
    extendedSandbox(['demo_expires_at' => Carbon::now()->addDays(5)]);

    $this->artisan('openlmnp:demo-expiry-notify')->assertSuccessful();

    Notification::assertNothingSent();
});

it('says nothing to an already expired sandbox', function () {
    // La purge s'en charge : lui écrire serait annoncer une échéance déjà passée.
    extendedSandbox(['demo_expires_at' => Carbon::now()->subHour()]);

    $this->artisan('openlmnp:demo-expiry-notify')->assertSuccessful();

    Notification::assertNothingSent();
});

it('says nothing to an account that is no longer a sandbox', function () {
    // Compte promu : il a payé, lui annoncer un effacement serait alarmant et faux.
    extendedSandbox(['is_demo' => false, 'demo_promoted_at' => Carbon::now()]);

    $this->artisan('openlmnp:demo-expiry-notify')->assertSuccessful();

    Notification::assertNothingSent();
});

it('does nothing at all when demo mode is switched off', function () {
    config()->set('demo.enabled', false);
    extendedSandbox();

    $this->artisan('openlmnp:demo-expiry-notify')->assertSuccessful();

    Notification::assertNothingSent();
});
