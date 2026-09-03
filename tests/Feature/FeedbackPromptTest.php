<?php

use App\Livewire\FeedbackPrompt;
use App\Models\Feedback;
use App\Models\User;
use App\Notifications\FeedbackReceived;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

beforeEach(function () {
    config()->set('feedback.enabled', true);
    config()->set('feedback.audiences', 'demo,user');
    config()->set('feedback.variants', 'a,b,c');
    config()->set('feedback.cooldown_days', 30);
    config()->set('feedback.forward_email', '');

    $this->user = User::factory()->create();
});

it('stays inert until the browser says the conditions are met', function () {
    Livewire::actingAs($this->user)
        ->test(FeedbackPrompt::class)
        ->assertSet('eligible', true)
        ->assertSet('step', 'idle')
        // Rien n'a été affiché, donc rien n'est compté : une impression enregistrée trop
        // tôt gonflerait le dénominateur du test avec des invitations jamais vues.
        ->assertDontSee('Que pensez-vous');

    expect(Feedback::count())->toBe(0);
});

it('records the impression as soon as it is shown, before any answer', function () {
    Livewire::actingAs($this->user)
        ->test(FeedbackPrompt::class)
        ->call('open')
        ->assertSet('step', 'ask')
        ->assertSee('Que pensez-vous');

    $feedback = Feedback::sole();

    expect($feedback->sentiment)->toBeNull()
        ->and($feedback->variant)->toBeIn(['a', 'b', 'c'])
        ->and($feedback->user_id)->toBe($this->user->id)
        ->and($this->user->fresh()->feedback_prompted_at)->not->toBeNull();
});

it('is not mounted at all when the feature is switched off', function () {
    config()->set('feedback.enabled', false);

    Livewire::actingAs($this->user)
        ->test(FeedbackPrompt::class)
        ->assertSet('eligible', false)
        ->call('open')
        ->assertSet('step', 'idle');

    expect(Feedback::count())->toBe(0);
});

it('serves only the variants still in play', function () {
    config()->set('feedback.variants', 'b');

    Livewire::actingAs($this->user)->test(FeedbackPrompt::class)->call('open');

    expect(Feedback::sole()->variant)->toBe('b');
});

it('records the sentiment on the first click, not on submit', function () {
    // La majorité des gens cliquent puis referment sans écrire : attendre le formulaire
    // perdrait l'essentiel des réponses.
    Livewire::actingAs($this->user)
        ->test(FeedbackPrompt::class)
        ->call('open')
        ->call('choose', 'positive')
        ->assertSet('step', 'positive');

    expect(Feedback::sole()->sentiment)->toBe(Feedback::SENTIMENT_POSITIVE);
});

it('only accepts the two sentiments it knows', function () {
    Livewire::actingAs($this->user)
        ->test(FeedbackPrompt::class)
        ->call('open')
        ->call('choose', 'n<importe>quoi');

    expect(Feedback::sole()->sentiment)->toBe(Feedback::SENTIMENT_NEGATIVE);
});

it('keeps the message and the consent together', function () {
    Livewire::actingAs($this->user)
        ->test(FeedbackPrompt::class)
        ->call('open')
        ->call('choose', 'positive')
        ->set('message', 'La liasse en deux clics, enfin.')
        ->set('authorName', 'Camille')
        ->set('canPublish', true)
        ->call('submit')
        ->assertSet('step', 'done');

    $feedback = Feedback::sole();

    expect($feedback->message)->toBe('La liasse en deux clics, enfin.')
        ->and($feedback->author_name)->toBe('Camille')
        ->and($feedback->can_publish)->toBeTrue();
});

it('refuses a consent that covers no text at all', function () {
    // Cocher « vous pouvez publier » sans rien écrire ne consent à rien de publiable :
    // le garder à true fabriquerait un témoignage vide dans le sas de tri.
    Livewire::actingAs($this->user)
        ->test(FeedbackPrompt::class)
        ->call('open')
        ->call('choose', 'positive')
        ->set('message', '   ')
        ->set('canPublish', true)
        ->call('submit');

    expect(Feedback::sole()->can_publish)->toBeFalse();
});

it('rejects a malformed address instead of losing the feedback', function () {
    Livewire::actingAs($this->user)
        ->test(FeedbackPrompt::class)
        ->call('open')
        ->call('choose', 'negative')
        ->set('authorEmail', 'pas-une-adresse')
        ->call('submit')
        ->assertHasErrors('authorEmail')
        ->assertSet('step', 'negative');
});

it('sends nothing outside when no forwarding address is configured', function () {
    Notification::fake();

    Livewire::actingAs($this->user)
        ->test(FeedbackPrompt::class)
        ->call('open')
        ->call('choose', 'negative')
        ->set('message', 'L\'import Airbnb a échoué.')
        ->call('submit')
        // `escape: false` : le texte est littéral dans le gabarit, il porte donc une vraie
        // apostrophe, quand `assertSee` échapperait la sienne en `&#039;`.
        ->assertSee('Rien n\'a été envoyé à l\'extérieur', false);

    Notification::assertNothingSent();
});

it('forwards to the configured address when there is one', function () {
    Notification::fake();
    config()->set('feedback.forward_email', 'contact@openlmnp.fr');

    Livewire::actingAs($this->user)
        ->test(FeedbackPrompt::class)
        ->call('open')
        ->call('choose', 'negative')
        ->set('message', 'L\'import Airbnb a échoué.')
        ->call('submit');

    Notification::assertSentOnDemand(FeedbackReceived::class);
});

it('marks a dismissal apart from an invitation simply ignored', function () {
    Livewire::actingAs($this->user)
        ->test(FeedbackPrompt::class)
        ->call('open')
        ->call('dismiss')
        ->assertSet('eligible', false);

    $feedback = Feedback::sole();

    expect($feedback->dismissed_at)->not->toBeNull()
        ->and($feedback->sentiment)->toBeNull()
        ->and($this->user->fresh()->feedback_answered_at)->not->toBeNull();
});

it('cannot be opened twice, which would count the same view as two', function () {
    Livewire::actingAs($this->user)
        ->test(FeedbackPrompt::class)
        ->call('open')
        ->call('open')
        ->call('open');

    expect(Feedback::count())->toBe(1);
});

it('pushes the four analytics events with the variant attached', function () {
    // Ces noms et ces paramètres sont câblés à la main dans GTM (v16) et enregistrés comme
    // dimensions GA4. Les renommer sans toucher au container ne casserait rien de visible :
    // les événements partiraient dans le dataLayer et s'y arrêteraient, en silence. C'est
    // le mode de panne des extensions v13 et v14 — ce test est là pour l'attraper.
    $component = Livewire::actingAs($this->user)->test(FeedbackPrompt::class);

    $component->call('open')
        ->assertDispatched('analytics', fn ($event, $params) => $params[0]['event'] === 'feedback_prompted'
            && isset($params[0]['feedback_variant'], $params[0]['feedback_trigger']));

    $component->call('choose', 'positive')
        ->assertDispatched('analytics', fn ($event, $params) => $params[0]['event'] === 'feedback_given'
            && $params[0]['sentiment'] === 'positive'
            && isset($params[0]['feedback_variant']));

    $component->set('message', 'Un mot.')->call('submit')
        ->assertDispatched('analytics', fn ($event, $params) => $params[0]['event'] === 'feedback_message'
            && $params[0]['has_message'] === 'yes'
            && isset($params[0]['feedback_variant']));

    Livewire::actingAs(User::factory()->create())
        ->test(FeedbackPrompt::class)
        ->call('open')
        ->call('dismiss')
        ->assertDispatched('analytics', fn ($event, $params) => $params[0]['event'] === 'feedback_dismissed'
            && isset($params[0]['feedback_variant']));
});

it('never sends user_type as an event parameter', function () {
    // `user_type` est une dimension à portée UTILISATEUR, posée à chaque page par
    // `partials/gtm-head` et lue par le rapport hebdomadaire. En faire aussi un paramètre
    // d'événement créerait deux dimensions homonymes de portées différentes, illisibles
    // l'une comme l'autre — même piège que `source` / `capture_source` en v15.
    Livewire::actingAs($this->user)
        ->test(FeedbackPrompt::class)
        ->call('open')
        ->assertDispatched('analytics', fn ($event, $params) => ! array_key_exists('user_type', $params[0]));
});

it('never publishes a testimonial written from the demonstration', function () {
    // Le retour est précieux, mais son auteur n'a pas tenu SA comptabilité dans le
    // logiciel : le publier comme retour d'usage serait un faux avis.
    $fromDemo = Feedback::factory()->answered()->create([
        'audience' => Feedback::AUDIENCE_DEMO,
        'can_publish' => true,
        'message' => 'Très clair.',
    ]);

    $fromUser = Feedback::factory()->answered()->create([
        'audience' => Feedback::AUDIENCE_USER,
        'can_publish' => true,
        'message' => 'Très clair.',
    ]);

    expect($fromDemo->isPublishableAsTestimonial())->toBeFalse()
        ->and($fromUser->isPublishableAsTestimonial())->toBeTrue();
});

it('keeps the feedback when the demo account that wrote it is purged', function () {
    // LE test de cette fonctionnalité. Les comptes de démonstration sont détruits toutes
    // les heures : en cascade, on effacerait dans l'heure la seule population qu'on
    // cherche à écouter, et le compteur du test A/B/C avec.
    $demo = User::factory()->create([
        'is_demo' => true,
        'demo_expires_at' => now()->subHour(),
    ]);

    Livewire::actingAs($demo)
        ->test(FeedbackPrompt::class)
        ->call('open')
        ->call('choose', 'positive');

    $this->artisan('openlmnp:demo-cleanup')->assertSuccessful();

    expect(User::find($demo->id))->toBeNull()
        ->and(Feedback::count())->toBe(1)
        ->and(Feedback::sole()->user_id)->toBeNull()
        ->and(Feedback::sole()->sentiment)->toBe(Feedback::SENTIMENT_POSITIVE);
});
