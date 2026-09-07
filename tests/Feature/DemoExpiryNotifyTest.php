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

// === Ce que `Notification::fake()` ne voit pas ===

it('actually renders the expiry reminder, which the fake never does', function () {
    /*
     * ⚠️ LA MÊME FATALE QUE `DemoResumeLink`, MAIS SANS TÉMOIN.
     *
     * `MailMessage::to()` n'existe pas — c'est une méthode de `Mailable`. `DemoExpiring` la
     * passait au rendu du message, donc à l'envoi. Tous les tests ci-dessus étaient verts :
     * `Notification::fake()` enregistre l'intention d'envoyer et n'appelle JAMAIS `toMail()`.
     *
     * Et contrairement à la prolongation, personne ne voyait l'erreur : la commande tourne
     * toutes les heures sans surveillance, attrape la fatale et la journalise. Le rappel
     * d'expiration n'est donc jamais parti.
     */
    $user = extendedSandbox();

    $mail = (new DemoExpiring('https://exemple.test/reprendre'))->toMail($user);

    expect($mail)->toBeInstanceOf(\Illuminate\Notifications\Messages\MailMessage::class);
});

it('reports a failure instead of announcing success on a send that never left', function () {
    /*
     * Le marqueur d'envoi n'est posé qu'APRÈS le `try` : un compte en échec est repris à
     * chaque passage horaire, indéfiniment. Tant que la commande rendait `SUCCESS` quoi
     * qu'il arrive, le planificateur lisait « Rappels envoyés : 0 » et tenait la panne pour
     * une absence de travail — c'est ce qui a laissé la fatale vivre en production.
     *
     * Le journal ne suffisait pas à alerter : la production tournait avec un `LOG_LEVEL`
     * invalide, qui renvoyait ces lignes vers le journal d'urgence.
     */
    extendedSandbox();

    Notification::shouldReceive('send')->andThrow(new RuntimeException('relais SMTP injoignable'));

    $this->artisan('openlmnp:demo-expiry-notify')->assertFailed();
});
