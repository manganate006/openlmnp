<?php

use App\Models\User;
use App\Support\DemoExpiry;
use Illuminate\Support\Carbon;

beforeEach(function () {
    config()->set('demo.reminders', '96:banner,24:modal,23:banner,18:banner,12:banner,6:modal,1:modal');
    config()->set('demo.min_gap_hours', 2);
    config()->set('demo.ttl_hours', 24);
    config()->set('demo.extended_ttl_days', 7);
    config()->set('demo.links.pro', '');
});

/** Un bac à sable à qui il reste exactement $hours heures. */
function sandbox(float $hours, array $attrs = []): User
{
    return User::factory()->create(array_merge([
        'is_demo' => true,
        'demo_expires_at' => Carbon::now()->addMinutes((int) round($hours * 60)),
    ], $attrs));
}

it('ignores anyone who is not on an expiring sandbox', function () {
    expect(DemoExpiry::for(null)->applies())->toBeFalse();

    $real = User::factory()->create(['is_demo' => false, 'demo_expires_at' => null]);
    expect(DemoExpiry::for($real)->applies())->toBeFalse();

    // Le cas qui compte : is_demo vrai mais AUCUNE date d'expiration. Un état incohérent
    // est représentable — la prolongation remet les paliers à zéro puis réécrit la date,
    // il existe donc une fenêtre où les deux ne sont pas d'accord. La garde doit RETENIR
    // l'affichage, jamais l'inventer sur une valeur par défaut.
    $orphan = User::factory()->create(['is_demo' => true, 'demo_expires_at' => null]);
    expect(DemoExpiry::for($orphan)->applies())->toBeFalse()
        ->and(DemoExpiry::for($orphan)->remainingSeconds())->toBe(0)
        ->and(DemoExpiry::for($orphan)->due())->toBeNull();
});

it('still refuses a null expiry when the reference instant is in the past', function () {
    // Ce test existe parce que le précédent ne verrouillait RIEN : il passait aussi bien
    // avec la garde qu'en la retirant. Le cas dangereux n'est pas le temps simulé — Carbon
    // remplace alors le null par ce même temps, et l'écart tombe à zéro — mais un instant
    // de référence EXPLICITE situé dans le passé, ce que fera la commande planifiée qui
    // balaie les comptes. `$now->diffInSeconds(null)` vaut alors `maintenant - $now`,
    // donc un grand positif, et le compte incohérent passerait pour vivant.
    $orphan = User::factory()->create(['is_demo' => true, 'demo_expires_at' => null]);
    $reference = Carbon::now()->subYear();

    expect(DemoExpiry::for($orphan, $reference)->remainingSeconds())->toBe(0)
        ->and(DemoExpiry::for($orphan, $reference)->applies())->toBeFalse()
        ->and(DemoExpiry::for($orphan, $reference)->due())->toBeNull();
});

it('ignores a sandbox that has already expired', function () {
    $user = sandbox(-1);

    expect(DemoExpiry::for($user)->applies())->toBeFalse()
        ->and(DemoExpiry::for($user)->due())->toBeNull();
});

it('reads the thresholds as HOURS REMAINING, sorted from the furthest away', function () {
    expect(array_keys(DemoExpiry::for(null)->thresholds()))->toBe([96, 24, 23, 18, 12, 6, 1]);
});

it('drops malformed thresholds instead of guessing a form', function () {
    // « modale » en français, une forme inventée, un seuil négatif, un fragment sans deux-points.
    config()->set('demo.reminders', '18:modale,12:popup,-4:modal,banner,6:modal');

    expect(DemoExpiry::for(null)->thresholds())->toBe([6 => 'modal']);
});

it('never fires the 96h and 24h thresholds on a 24h sandbox', function () {
    // Un palier n'est retenu que s'il est STRICTEMENT sous la durée de vie du sandbox.
    // Sans ce filtre, « il reste 24 h » se déclencherait à la première seconde : un
    // sandbox de 24 h n'a jamais 24 h pleines devant lui, mais 23 h 59.
    expect(array_keys(DemoExpiry::for(sandbox(23.9))->applicableThresholds()))->toBe([23, 18, 12, 6, 1]);

    // Rien pendant la première heure, puis le premier rappel une fois 1 h écoulée.
    foreach ([23.99, 23.5] as $left) {
        expect(DemoExpiry::for(sandbox($left))->due())->toBeNull();
    }
});

it('gives a first nudge after one hour of use', function () {
    // La session médiane de la démo dure 2 à 5 min : attendre 6 h pour dire un mot,
    // c'est ne parler qu'à ceux qui seraient restés de toute façon.
    expect(DemoExpiry::for(sandbox(23))->due())->toBe(['hours' => 23, 'form' => 'banner'])
        ->and(DemoExpiry::for(sandbox(22))->due())->toBe(['hours' => 23, 'form' => 'banner']);
});

it('does not stack the 24h offer and the 23h nudge on an extended sandbox', function () {
    // Les deux paliers servent des durées de vie différentes. Sans espacement minimal,
    // un sandbox prolongé recevrait la modale de l'offre puis, une heure plus tard, le
    // bandeau qui n'existe que pour le sandbox de 24 h.
    $user = sandbox(24, ['demo_extended_at' => Carbon::now()->subDays(6)]);

    expect(DemoExpiry::for($user)->due())->toBe(['hours' => 24, 'form' => 'modal']);

    DemoExpiry::for($user)->markSeen(24);

    expect($user->fresh()->demo_reminders_seen)->toContain(23)
        ->and(DemoExpiry::for($user->fresh())->due())->toBeNull();

    $user->forceFill(['demo_expires_at' => Carbon::now()->addHours(22)])->save();
    expect(DemoExpiry::for($user->fresh())->due())->toBeNull();
});

it('keeps the 96h and 24h thresholds on a sandbox that was extended to 7 days', function () {
    $extended = sandbox(120, ['demo_extended_at' => Carbon::now()->subDay()]);

    expect(array_keys(DemoExpiry::for($extended)->applicableThresholds()))->toBe([96, 24, 23, 18, 12, 6, 1]);
});

it('serves exactly the 6h / 12h / 18h / 23h escalation asked for on a 24h sandbox', function () {
    expect(DemoExpiry::for(sandbox(18))->due())->toBe(['hours' => 18, 'form' => 'banner'])   // 6 h écoulées
        ->and(DemoExpiry::for(sandbox(12))->due())->toBe(['hours' => 12, 'form' => 'banner']) // 12 h écoulées
        ->and(DemoExpiry::for(sandbox(6))->due())->toBe(['hours' => 6, 'form' => 'modal'])    // 18 h écoulées
        ->and(DemoExpiry::for(sandbox(1))->due())->toBe(['hours' => 1, 'form' => 'modal']);   // 23 h écoulées
});

it('shows the most urgent threshold when several were crossed at once', function () {
    // Quelqu'un qui ferme son navigateur à 20 h restantes et revient à 5 h restantes a
    // franchi 18, 12 et 6. Lui montrer « il reste 18 h » serait faux ET rassurant à tort.
    expect(DemoExpiry::for(sandbox(5))->due())->toBe(['hours' => 6, 'form' => 'modal']);
});

it('serves a threshold only once, and never falls back to a milder one', function () {
    // À 6 h restantes, 18, 12 et 6 sont TOUS franchis. Après avoir servi le plus grave,
    // réafficher « il reste 12 h » serait faux et rassurant à tort : les paliers moins
    // urgents sont donc marqués caducs du même coup.
    $user = sandbox(6);
    $expiry = DemoExpiry::for($user);

    expect($expiry->due())->toBe(['hours' => 6, 'form' => 'modal']);

    $expiry->markSeen(6);

    expect(DemoExpiry::for($user->fresh())->due())->toBeNull()
        ->and($user->fresh()->demo_reminders_seen)->toBe([6, 12, 18, 23]);

    // Et le palier suivant, plus urgent, reste bien disponible.
    $user->forceFill(['demo_expires_at' => Carbon::now()->addMinutes(30)])->save();
    expect(DemoExpiry::for($user->fresh())->due())->toBe(['hours' => 1, 'form' => 'modal']);
});

it('refuses to record a threshold the visitor has not actually reached', function () {
    // La minuterie tourne dans le navigateur, mais c'est le serveur qui tranche. Sans
    // cette revalidation, n'importe qui pourrait faire marquer les paliers restants
    // comme servis et se soustraire à toutes les relances suivantes.
    $user = sandbox(20);
    $expiry = DemoExpiry::for($user);

    expect($expiry->isReached(6))->toBeFalse();

    $expiry->markSeen(6);
    $expiry->markSeen(1);

    expect($user->fresh()->demo_reminders_seen)->toBeEmpty();
});

it('refuses a threshold that is not in the configured list', function () {
    $user = sandbox(0.5);

    expect(DemoExpiry::for($user)->isReached(3))->toBeFalse();

    DemoExpiry::for($user)->markSeen(3);

    expect($user->fresh()->demo_reminders_seen)->toBeEmpty();
});

it('replays the whole escalation after an extension', function () {
    $user = sandbox(1);
    DemoExpiry::for($user)->markSeen(1);
    // Tous les paliers franchis sont marqués d'un coup, pas seulement celui qu'on sert.
    expect($user->fresh()->demo_reminders_seen)->toBe([1, 6, 12, 18, 23]);

    // Prolongation : 7 jours devant, et les paliers hauts n'ont jamais été servis.
    $user->forceFill([
        'demo_expires_at' => Carbon::now()->addDays(7),
        'demo_extended_at' => Carbon::now(),
    ])->save();
    DemoExpiry::resetSeen($user);

    $user->refresh();
    expect($user->demo_reminders_seen)->toBe([])
        ->and(DemoExpiry::for($user)->due())->toBeNull();       // 168 h restantes : rien encore

    // À J+3 il reste 96 h → bandeau ; à J+6 il reste 24 h → modale, celle qui porte l'offre.
    $ext = fn (float $left) => sandbox($left, ['demo_extended_at' => Carbon::now()->subDay()]);

    expect(DemoExpiry::for($ext(96))->due())->toBe(['hours' => 96, 'form' => 'banner'])
        ->and(DemoExpiry::for($ext(24))->due())->toBe(['hours' => 24, 'form' => 'modal']);
});

it('offers the extension only once', function () {
    expect(DemoExpiry::for(sandbox(6))->canExtend())->toBeTrue()
        ->and(DemoExpiry::for(sandbox(6, ['demo_extended_at' => Carbon::now()]))->canExtend())->toBeFalse();
});

it('has no commercial offer until a target URL is configured', function () {
    expect(DemoExpiry::for(sandbox(6))->hasOffer())->toBeFalse();

    config()->set('demo.links.pro', 'https://openlmnp.fr/migrer');

    expect(DemoExpiry::for(sandbox(6))->hasOffer())->toBeTrue();
});
