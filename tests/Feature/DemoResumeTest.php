<?php

use App\Models\User;
use App\Notifications\DemoResumeLink;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\URL;

beforeEach(function () {
    config()->set('demo.enabled', true);
    config()->set('demo.ttl_hours', 24);
    config()->set('demo.extended_ttl_days', 7);
});

function resumable(array $attrs = []): User
{
    return User::factory()->create(array_merge([
        'is_demo' => true,
        'demo_expires_at' => Carbon::now()->addDays(7),
        'demo_email' => 'visiteur@exemple.fr',
    ], $attrs));
}

it('signs the visitor back into their sandbox', function () {
    $user = resumable();

    $this->get(DemoResumeLink::urlFor($user))->assertRedirect('/');

    expect(auth()->id())->toBe($user->id);
});

it('refuses a link whose signature was tampered with', function () {
    $user = resumable();
    $url = DemoResumeLink::urlFor($user);

    $this->get($url.'x')->assertForbidden();

    expect(auth()->check())->toBeFalse();
});

it('refuses a link that has expired', function () {
    $user = resumable();
    $url = DemoResumeLink::urlFor($user);

    // Le lien est calé sur l'expiration du bac à sable : au-delà, il ne mène nulle part.
    $this->travelTo(Carbon::now()->addDays(8));

    $this->get($url)->assertForbidden();

    expect(auth()->check())->toBeFalse();
});

it('refuses to open a session on an account that is no longer a sandbox', function () {
    // Le cas qui compte vraiment : le compte a été PROMU en compte payant entre-temps.
    // Une signature valide ne doit pas ouvrir de session sans mot de passe sur un compte
    // réel — une signature prouve l'origine du lien, pas la légitimité de la cible.
    $user = resumable();
    $url = DemoResumeLink::urlFor($user);

    $user->forceFill([
        'is_demo' => false,
        'demo_expires_at' => null,
        'demo_promoted_at' => Carbon::now(),
    ])->save();

    $this->get($url)->assertRedirect(route('demo.start'));

    expect(auth()->check())->toBeFalse();
});

it('refuses a sandbox whose expiry has passed even if the signature is still valid', function () {
    $user = resumable();
    $url = DemoResumeLink::urlFor($user);

    // Expiration reculée dans le passé sans toucher au lien : le contrôleur doit trancher
    // seul, sans compter sur le middleware `signed`.
    $user->forceFill(['demo_expires_at' => Carbon::now()->subHour()])->save();

    $this->get($url)->assertRedirect(route('demo.start'));

    expect(auth()->check())->toBeFalse();
});

it('builds the link on APP_URL, not on the internal container host', function () {
    // Émis par la commande planifiée, le lien est bâti hors requête HTTP : sans
    // forceRootUrl il porterait l'hôte interne du conteneur et arriverait cassé.
    config()->set('app.url', 'https://app.openlmnp.fr');
    URL::forceRootUrl('http://127.0.0.1:8000');

    expect(DemoResumeLink::urlFor(resumable()))->toStartWith('https://app.openlmnp.fr/demo/reprendre/');
});
