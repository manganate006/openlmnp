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

it('offers the hosted plan to nobody when no URL is configured', function () {
    // LE test qui protège le dépôt public : sans URL, aucune sollicitation commerciale
    // n'existe. C'est l'état par défaut, donc celui de toute instance auto-hébergée.
    config()->set('feedback.links.pro', '');

    $demo = User::factory()->create(['is_demo' => true]);

    Livewire::actingAs($demo)
        ->test(FeedbackPrompt::class)
        ->call('open')
        ->call('choose', 'positive')
        ->assertSet('showsProCta', false)
        ->assertDontSee('Cloud Pro')
        // ⚠️ L'accroche NE dépend plus de la présence d'un CTA commercial mais de
        // l'audience : un visiteur de la démonstration reste un prospect même quand
        // aucune offre n'est configurée, et « voici comment nous aider » ne s'adresse
        // pas à lui. Cette assertion attendait l'inverse jusqu'au 2026-09-06.
        ->assertSee('Merci ! Une dernière chose');
});

it('offers the hosted plan only from the demonstration, never to a real account', function () {
    // Un compte réel est déjà client, ou auto-hébergé. Lui vendre l'offre n'a aucun sens.
    config()->set('feedback.links.pro', 'https://openlmnp.fr/tarifs');

    Livewire::actingAs($this->user)
        ->test(FeedbackPrompt::class)
        ->call('open')
        ->call('choose', 'positive')
        ->assertSet('audience', Feedback::AUDIENCE_USER)
        ->assertSet('showsProCta', false)
        ->assertDontSee('Cloud Pro');
});

it('offers the hosted plan to a demo visitor who likes the software', function () {
    config()->set('feedback.links.pro', 'https://openlmnp.fr/tarifs?utm_source=app');
    config()->set('demo.ttl_hours', 24);

    $demo = User::factory()->create(['is_demo' => true]);

    Livewire::actingAs($demo)
        ->test(FeedbackPrompt::class)
        ->call('open')
        ->call('choose', 'positive')
        ->assertSet('showsProCta', true)
        ->assertSee('Gardez tout ça avec Cloud Pro')
        ->assertSee('https://openlmnp.fr/tarifs?utm_source=app', false)
        // Le compte d'heures vient de la config de l'instance, pas d'un texte figé.
        ->assertSee('s\'efface dans 24 heures', false)
        // L'hébergement par soi-même reste proposé : le produit est sous AGPL, taire
        // cette voie au moment de vendre l'offre payante serait malhonnête.
        ->assertSee('si vous préférez l\'héberger vous-même', false)
        // ⚠️ Plus de lien GitHub en démonstration depuis le 2026-09-06 : le séparateur
        // reste, parce qu'il accompagne le CTA commercial, mais les trois gestes qui le
        // suivaient s'adressaient à un contributeur, pas à quelqu'un qui découvre.
        ->assertDontSee('Mettre une étoile sur GitHub');
});

it('annonce la vraie durée du bac à sable de cette instance', function () {
    config()->set('feedback.links.pro', 'https://openlmnp.fr/tarifs');
    config()->set('demo.ttl_hours', 72);

    Livewire::actingAs(User::factory()->create(['is_demo' => true]))
        ->test(FeedbackPrompt::class)
        ->call('open')
        ->call('choose', 'positive')
        ->assertSee('s\'efface dans 72 heures', false);

    // ⚠️ `demo.ttl_hours` est un réglage d'instance, qu'un auto-hébergeur peut poser à 1 :
    // le nombre et son unité voyagent ensemble, sinon on écrit « dans 1 heures » — le même
    // défaut que celui corrigé sur le bandeau d'expiration (`atRiskSentence()`).
    config()->set('demo.ttl_hours', 1);

    Livewire::actingAs(User::factory()->create(['is_demo' => true]))
        ->test(FeedbackPrompt::class)
        ->call('open')
        ->call('choose', 'positive')
        ->assertSee('s\'efface dans 1 heure.', false)
        ->assertDontSee('1 heures');
});

it('never sells anything to someone who just said they were not convinced', function () {
    config()->set('feedback.links.pro', 'https://openlmnp.fr/tarifs');

    Livewire::actingAs(User::factory()->create(['is_demo' => true]))
        ->test(FeedbackPrompt::class)
        ->call('open')
        ->call('choose', 'negative')
        ->assertSee('Désolé')
        ->assertDontSee('Cloud Pro');
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

// ─────────────────────────────────────────────────────────────────────────────
// Écran « j'aime » vu par un PROSPECT de la démonstration
//
// Sur le cloud, `FEEDBACK_AUDIENCES=demo` : la quasi-totalité des invitations
// s'adressent à des visiteurs qui découvrent le produit, pas à des développeurs.
// ─────────────────────────────────────────────────────────────────────────────

it('ne propose aucun geste GitHub à un visiteur de la démonstration', function () {
    // LE test qui répond au constat d'origine : étoile, parrainage et Discussions
    // n'ont aucun sens pour quelqu'un qui découvre le produit depuis quatre minutes.
    Livewire::actingAs(User::factory()->create(['is_demo' => true]))
        ->test(FeedbackPrompt::class)
        ->call('open')
        ->call('choose', 'positive')
        ->assertSet('isDemo', true)
        ->assertDontSee('Mettre une étoile sur GitHub')
        ->assertDontSee('Devenir sponsor')
        ->assertDontSee('En parler dans les Discussions');
});

it('garde les gestes GitHub pour un compte réel', function () {
    // Non-régression : pour une instance auto-hébergée, ces trois gestes sont
    // exactement ce qu'il faut proposer.
    Livewire::actingAs($this->user)
        ->test(FeedbackPrompt::class)
        ->call('open')
        ->call('choose', 'positive')
        ->assertSet('isDemo', false)
        ->assertSee('Mettre une étoile sur GitHub')
        ->assertSee('Devenir sponsor');
});

it('enregistre un frein coché SANS aucun envoi', function () {
    // Le cœur de la demande : quelqu'un qui coche puis referme a quand même répondu.
    // Aucun appel à submit() ici — c'est tout l'intérêt.
    Livewire::actingAs(User::factory()->create(['is_demo' => true]))
        ->test(FeedbackPrompt::class)
        ->call('open')
        ->call('choose', 'positive')
        ->set('blockers', ['prix', 'reprise']);

    expect(Feedback::sole()->blockers)->toEqualCanonicalizing(['prix', 'reprise']);
});

it('retire un frein décoché', function () {
    $component = Livewire::actingAs(User::factory()->create(['is_demo' => true]))
        ->test(FeedbackPrompt::class)
        ->call('open')
        ->call('choose', 'positive')
        ->set('blockers', ['prix', 'avis']);

    expect(Feedback::sole()->blockers)->toEqualCanonicalizing(['prix', 'avis']);

    $component->set('blockers', ['prix']);
    expect(Feedback::sole()->fresh()->blockers)->toBe(['prix']);

    // Tout décocher ne doit pas laisser un tableau vide qui se compterait comme
    // « a répondu » dans le rapport : on revient à null.
    $component->set('blockers', []);
    expect(Feedback::sole()->fresh()->blockers)->toBeNull();
});

it('rejette un frein que le formulaire ne propose pas', function () {
    // Rien ne garantit ce que le navigateur envoie, et une valeur libre polluerait
    // durablement les comptages — les identifiants sont une énumération fermée.
    Livewire::actingAs(User::factory()->create(['is_demo' => true]))
        ->test(FeedbackPrompt::class)
        ->call('open')
        ->call('choose', 'positive')
        ->set('blockers', ['prix', 'n_importe_quoi', '<script>']);

    expect(Feedback::sole()->blockers)->toBe(['prix']);
});

it('pose la question qui compte au prospect, et celle du témoignage à un compte réel', function () {
    Livewire::actingAs(User::factory()->create(['is_demo' => true]))
        ->test(FeedbackPrompt::class)
        ->call('open')
        ->call('choose', 'positive')
        ->assertSee('Qu\'est-ce qui vous manque pour franchir le pas ?', false)
        // Le consentement à publication ne peut mener à rien en démonstration :
        // isPublishableAsTestimonial() refuse par construction ce qui en vient.
        ->assertDontSee('J\'autorise OpenLMNP à publier', false)
        ->assertDontSee('Prénom');

    Livewire::actingAs($this->user)
        ->test(FeedbackPrompt::class)
        ->call('open')
        ->call('choose', 'positive')
        ->assertSee('Ce qui vous a plu')
        ->assertSee('J\'autorise OpenLMNP à publier', false);
});

it('propose de garder le bac à sable, et passe la main sans rien réimplémenter', function () {
    config()->set('demo.enabled', true);

    $demo = User::factory()->create([
        'is_demo' => true,
        'demo_expires_at' => now()->addHours(20),
    ]);

    Livewire::actingAs($demo)
        ->test(FeedbackPrompt::class)
        ->call('open')
        ->call('choose', 'positive')
        ->assertSet('showsKeepSandbox', true)
        ->assertSee('Gardez votre travail 7 jours')
        // Un mot déjà tapé ne doit pas disparaître parce qu'on change d'écran.
        ->set('message', 'Il me manque la reprise de mon historique.')
        ->call('keepSandbox')
        ->assertDispatched('open-demo-extend');

    expect(Feedback::sole()->message)->toBe('Il me manque la reprise de mon historique.');
});

it('ne promet pas une prolongation déjà accordée', function () {
    config()->set('demo.enabled', true);

    $demo = User::factory()->create([
        'is_demo' => true,
        'demo_expires_at' => now()->addDays(6),
        'demo_extended_at' => now()->subDay(),
    ]);

    Livewire::actingAs($demo)
        ->test(FeedbackPrompt::class)
        ->call('open')
        ->call('choose', 'positive')
        ->assertSet('showsKeepSandbox', false)
        ->assertDontSee('Gardez votre travail 7 jours');
});

it('conserve les freins quand le compte démo qui les a laissés est purgé', function () {
    // LE test qui compte pour un lot d'audience démo : `feedback.user_id` est en
    // nullOnDelete() et les sandboxes sont purgés à l'heure. Un frein déclaré est
    // précisément une donnée qu'on veut garder après la purge.
    $demo = User::factory()->create([
        'is_demo' => true,
        'demo_expires_at' => now()->subHour(),
    ]);

    Livewire::actingAs($demo)
        ->test(FeedbackPrompt::class)
        ->call('open')
        ->call('choose', 'positive')
        ->set('blockers', ['confiance', 'teletransmission']);

    $this->artisan('openlmnp:demo-cleanup')->assertSuccessful();

    expect(User::find($demo->id))->toBeNull()
        ->and(Feedback::count())->toBe(1)
        ->and(Feedback::sole()->user_id)->toBeNull()
        ->and(Feedback::sole()->blockers)->toEqualCanonicalizing(['confiance', 'teletransmission']);
});
