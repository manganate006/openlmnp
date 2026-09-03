<?php

use App\Models\Feedback;
use App\Models\User;
use App\Support\FeedbackEligibility;

beforeEach(function () {
    config()->set('feedback.enabled', true);
    config()->set('feedback.audiences', 'demo,user');
    config()->set('feedback.cooldown_days', 30);
    config()->set('feedback.return_days', 2);
});

it('offers nothing when the feature is switched off', function () {
    config()->set('feedback.enabled', false);

    expect(FeedbackEligibility::for(User::factory()->create())->shouldOffer())->toBeFalse();
});

it('offers nothing to a visitor who is not signed in', function () {
    expect(FeedbackEligibility::for(null)->shouldOffer())->toBeFalse();
});

it('honours the configured audiences', function () {
    $user = User::factory()->create(['is_demo' => false]);
    $demo = User::factory()->create(['is_demo' => true]);

    config()->set('feedback.audiences', 'demo');
    expect(FeedbackEligibility::for($user)->shouldOffer())->toBeFalse()
        ->and(FeedbackEligibility::for($demo)->shouldOffer())->toBeTrue();

    config()->set('feedback.audiences', 'user');
    expect(FeedbackEligibility::for($user)->shouldOffer())->toBeTrue()
        ->and(FeedbackEligibility::for($demo)->shouldOffer())->toBeFalse();

    // Une liste vide n'offre à personne : c'est l'équivalent d'un interrupteur fermé,
    // et ça ne doit surtout pas se comporter comme « aucun filtre, donc tout le monde ».
    config()->set('feedback.audiences', '');
    expect(FeedbackEligibility::for($user)->shouldOffer())->toBeFalse()
        ->and(FeedbackEligibility::for($demo)->shouldOffer())->toBeFalse();
});

it('treats the published fixed demo account as a demo audience', function () {
    // Ce compte-là porte is_demo = false alors que ses identifiants sont publics —
    // même exception que celle qu'applique AiAccess.
    $fixed = User::factory()->create([
        'is_demo' => false,
        'email' => config('demo.email'),
    ]);

    expect(FeedbackEligibility::for($fixed)->audience())->toBe(Feedback::AUDIENCE_DEMO);
});

it('does not ask again within the cooldown window', function () {
    $user = User::factory()->create(['feedback_answered_at' => now()->subDays(3)]);

    expect(FeedbackEligibility::for($user)->shouldOffer())->toBeFalse();

    // ⚠️ Les colonnes de cette fonctionnalité doivent figurer dans l'attribut
    // `#[Fillable(...)]` de User (Laravel 13 le déclare en attribut PHP, pas en propriété).
    // Une colonne oubliée n'échoue pas : Eloquent l'ignore EN SILENCE à l'`update()`,
    // alors que les fabriques, qui écrivent en `unguarded`, la posent sans broncher —
    // d'où un test qui semble passer à la création et casse à la mise à jour.
    $user->update(['feedback_answered_at' => now()->subDays(31)]);

    expect(FeedbackEligibility::for($user->fresh())->shouldOffer())->toBeTrue();
});

it('remembers through the cookie when the account itself is gone', function () {
    // C'est le cas d'un visiteur de la démonstration : son compte d'hier a été purgé,
    // le nouveau n'a aucune mémoire. Seul le cookie évite de reposer la question.
    $fresh = User::factory()->create(['is_demo' => true]);

    $cookies = [FeedbackEligibility::COOKIE_STATE => json_encode(['at' => now()->subDay()->toDateString()])];

    expect(FeedbackEligibility::for($fresh, $cookies)->shouldOffer())->toBeFalse();
});

it('recognises a demo visitor who came back days later', function () {
    $demo = User::factory()->create(['is_demo' => true]);

    // Premier passage aujourd'hui : rien ne dit qu'il est déjà venu.
    $today = [FeedbackEligibility::COOKIE_FIRST_SEEN => now()->toDateString()];
    expect(FeedbackEligibility::for($demo, $today)->trigger())->toBe(Feedback::TRIGGER_SESSION);

    // Premier passage il y a trois jours, sandbox détruit depuis longtemps.
    $older = [FeedbackEligibility::COOKIE_FIRST_SEEN => now()->subDays(3)->toDateString()];
    expect(FeedbackEligibility::for($demo, $older)->trigger())->toBe(Feedback::TRIGGER_RETURN);
});

it('uses the account age for a real user, who outlives any cookie', function () {
    $old = User::factory()->create(['created_at' => now()->subDays(10)]);
    $new = User::factory()->create(['created_at' => now()]);

    expect(FeedbackEligibility::for($old)->trigger())->toBe(Feedback::TRIGGER_RETURN)
        ->and(FeedbackEligibility::for($new)->trigger())->toBe(Feedback::TRIGGER_SESSION);
});

it('survives a tampered cookie instead of breaking the page', function () {
    $user = User::factory()->create(['is_demo' => true]);

    $garbage = [
        FeedbackEligibility::COOKIE_FIRST_SEEN => 'pas-une-date',
        FeedbackEligibility::COOKIE_STATE => '{ceci n\'est pas du json',
    ];

    expect(FeedbackEligibility::for($user, $garbage)->shouldOffer())->toBeTrue()
        ->and(FeedbackEligibility::for($user, $garbage)->trigger())->toBe(Feedback::TRIGGER_SESSION);
});

it('reads the thresholds that the browser will enforce', function () {
    config()->set('feedback.min_seconds', 240);
    config()->set('feedback.min_actions', 2);
    config()->set('feedback.actions', 'projection_used, simulator_used ,');

    $thresholds = FeedbackEligibility::for(User::factory()->create())->thresholds();

    expect($thresholds['minSeconds'])->toBe(240)
        ->and($thresholds['minActions'])->toBe(2)
        // Espaces et virgule finale tolérés : une liste se règle à la main, en production,
        // dans un `docker run` déjà long.
        ->and($thresholds['actions'])->toBe(['projection_used', 'simulator_used']);
});
