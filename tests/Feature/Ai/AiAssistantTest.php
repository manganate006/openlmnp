<?php

use App\Livewire\AiAssistant;
use App\Livewire\ContextualHelp;
use App\Models\AiUsageLog;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Livewire\Livewire;

/**
 * L'onglet Assistant du panneau d'aide : réponse utile, refus, états d'indisponibilité,
 * journal sans contenu de conversation.
 */
const BASE_CHAT = 'https://openrouter.test/api/v1';

beforeEach(function () {
    Sleep::fake();

    config([
        'ai.enabled' => true,
        'ai.api_key' => 'sk-or-v1-cle-factice-assistant',
        // Épinglé : l'hôte de test n'est pas openrouter.ai, la détection
        // automatique le classerait donc en serveur local.
        'ai.provider' => 'openrouter',
        'ai.base_url' => BASE_CHAT,
        'ai.models.chat' => 'google/gemini-3.7-flash',
        'ai.chat_daily_quota' => 0,
        'ai.chat_max_turns' => 20,
        'ai.chat_tool_calls_max' => 4,
        'ai.model_prices' => [],
        'demo.email' => 'demo@openlmnp.fr',
    ]);

    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

/** Corps SSE d'une réponse en flux, découpée en fragments. */
function fluxAssistant(array $fragments, array $extra = []): string
{
    $corps = '';

    foreach ($fragments as $fragment) {
        $corps .= 'data: '.json_encode(['choices' => [['delta' => ['content' => $fragment]]]])."\n\n";
    }

    foreach ($extra as $evenement) {
        $corps .= 'data: '.json_encode($evenement)."\n\n";
    }

    $corps .= 'data: '.json_encode([
        'choices' => [['delta' => [], 'finish_reason' => 'stop']],
        'usage' => ['prompt_tokens' => 900, 'completion_tokens' => 80, 'cost' => 0.0007],
    ])."\n\n";

    return $corps."data: [DONE]\n\n";
}

function fakeReponse(array $fragments): void
{
    Http::fake([BASE_CHAT.'/chat/completions' => Http::response(fluxAssistant($fragments))]);
}

// =============================================================================
// Réponse utile
// =============================================================================

it('répond à une question et rend un Markdown sûr', function () {
    fakeReponse(['Ouvrez la page **Charges**', ' puis cliquez sur Créer.']);

    Livewire::test(AiAssistant::class)
        ->set('input', 'Où saisir une facture d\'assurance ?')
        ->call('send')
        ->assertSet('busy', false)
        ->assertSee('Ouvrez la page', false)
        ->assertSee('<strong>Charges</strong>', false);
});

it('conserve la question dans le fil', function () {
    fakeReponse(['Réponse.']);

    $component = Livewire::test(AiAssistant::class)
        ->set('input', 'Comment clôturer un exercice ?')
        ->call('send');

    $messages = $component->get('messages');

    expect($messages)->toHaveCount(2)
        ->and($messages[0]['role'])->toBe('user')
        ->and($messages[0]['text'])->toBe('Comment clôturer un exercice ?')
        ->and($messages[1]['role'])->toBe('assistant');
});

it('renvoie la question et les extraits au modèle, jamais les données du compte', function () {
    fakeReponse(['Réponse.']);

    Livewire::test(AiAssistant::class)
        ->set('input', 'Où saisir la taxe foncière ?')
        ->call('send');

    Http::assertSent(function ($request) {
        $messages = collect($request['messages']);

        return $messages->contains(fn ($m) => $m['role'] === 'user' && str_contains($m['content'], 'taxe foncière'))
            && $messages->contains(fn ($m) => $m['role'] === 'system' && str_contains($m['content'], '<extrait'))
            && $request['stream'] === true;
    });
});

it('propose des suggestions liées à la page courante', function () {
    Livewire::test(AiAssistant::class, ['routeName' => 'filament.admin.resources.expenses.index'])
        ->assertSee("Où saisir une facture d'assurance ?");
});

it('envoie une suggestion en un clic', function () {
    fakeReponse(['Dans la page Charges.']);

    Livewire::test(AiAssistant::class)
        ->call('suggest', 'Comment saisir une charge ?')
        ->assertSee('Dans la page Charges.');
});

it('limite la question à 1 000 caractères', function () {
    fakeReponse(['Réponse.']);

    Livewire::test(AiAssistant::class)
        ->set('input', str_repeat('a', 1500))
        ->call('send');

    Http::assertSent(function ($request) {
        $question = collect($request['messages'])->last();

        return strlen($question['content']) === 1000;
    });
});

it('vide le fil quand on démarre une nouvelle conversation', function () {
    fakeReponse(['Réponse.']);

    Livewire::test(AiAssistant::class)
        ->set('input', 'Une question ?')
        ->call('send')
        ->call('newConversation')
        ->assertSet('messages', [])
        ->assertSee("Bonjour, je suis l'assistant OpenLMNP", false);
});

// =============================================================================
// Garde-fous
// =============================================================================

it('remplace un conseil fiscal par le refus standard', function () {
    fakeReponse(['Vu vos revenus, vous devriez opter pour le régime réel.']);

    $component = Livewire::test(AiAssistant::class)
        ->set('input', 'Dois-je passer au réel ?')
        ->call('send');

    expect($component->get('messages')[1]['text'])
        ->toContain('Je ne peux pas vous conseiller sur votre situation fiscale');

    expect(AiUsageLog::where('status', 'refused')->where('feature', 'chat')->count())->toBe(1);
    expect(AiUsageLog::where('status', 'refused')->first()->meta)->toBe(['guard' => 'output']);
});

it('cesse de diffuser dès qu\'un conseil fiscal apparaît dans le flux', function () {
    // Le garde-fou de sortie ne suffit pas : il remplace la réponse une fois complète,
    // alors qu'elle a déjà été lue à l'écran pendant sa génération.
    fakeReponse(['Bonne question. ', 'Dans votre cas, vous devriez opter pour le réel', ' sans hésiter.', ' Voilà.']);

    $fragments = [];

    $answer = app(\App\Services\Ai\Assistant\AssistantService::class)->answer(
        $this->user,
        \App\Services\Ai\Assistant\Conversation::fromDisplayed([]),
        'Dois-je passer au réel ?',
        null,
        function (string $delta) use (&$fragments) {
            $fragments[] = $delta;
        },
    );

    expect($fragments)->toBe(['Bonne question. '])
        ->and(implode('', $fragments))->not->toContain('opter pour le réel')
        ->and($answer->refused)->toBeTrue()
        ->and($answer->text)->toContain('Je ne peux pas vous conseiller sur votre situation fiscale');

    expect(AiUsageLog::where('status', 'refused')->first()->meta)->toBe(['guard' => 'output']);
});

it('diffuse intégralement une réponse sans motif à risque', function () {
    fakeReponse(['Ouvrez ', 'la page Charges, ', 'puis cliquez sur Créer.']);

    $fragments = [];

    app(\App\Services\Ai\Assistant\AssistantService::class)->answer(
        $this->user,
        \App\Services\Ai\Assistant\Conversation::fromDisplayed([]),
        'Où saisir une charge ?',
        null,
        function (string $delta) use (&$fragments) {
            $fragments[] = $delta;
        },
    );

    expect($fragments)->toBe(['Ouvrez ', 'la page Charges, ', 'puis cliquez sur Créer.']);
});

it('journalise un refus de périmètre produit par le modèle', function () {
    fakeReponse(["Je suis l'assistant d'OpenLMNP : je ne réponds qu'aux questions sur l'utilisation du logiciel."]);

    Livewire::test(AiAssistant::class)
        ->set('input', 'Quel temps fera-t-il demain ?')
        ->call('send');

    expect(AiUsageLog::where('status', 'refused')->first()->meta)->toBe(['guard' => 'scope']);
});

it('retire les liens qui ne mènent pas à une page du logiciel', function () {
    fakeReponse(['Voir [ce site](https://exemple.test/piege) et [les charges](/expenses).']);

    $component = Livewire::test(AiAssistant::class)
        ->set('input', 'Où saisir une charge ?')
        ->call('send');

    $html = $component->get('messages')[1]['html'];

    expect($html)->not->toContain('exemple.test')
        ->and($html)->toContain('ce site')
        ->and($html)->toContain('href="/expenses"');
});

it('neutralise le HTML produit par le modèle', function () {
    fakeReponse(['<script>alert(1)</script> Ouvrez la page Charges. <img src=x onerror=alert(1)>']);

    $component = Livewire::test(AiAssistant::class)
        ->set('input', 'Une question ?')
        ->call('send');

    // Le balisage devient du texte visible ; le texte utile n'est pas perdu.
    expect($component->get('messages')[1]['html'])
        ->not->toContain('<script')
        ->not->toContain('<img')
        ->toContain('Ouvrez la page Charges.')
        ->toContain('&lt;script&gt;');
});

it('n\'écrit jamais le texte de la conversation dans le journal', function () {
    fakeReponse(['La réponse secrète.']);

    Livewire::test(AiAssistant::class)
        ->set('input', 'Ma question très identifiable sur le studio de Lyon')
        ->call('send');

    foreach (AiUsageLog::all() as $ligne) {
        $meta = json_encode($ligne->meta);

        expect($meta)->not->toContain('studio')
            ->and($meta)->not->toContain('secrète')
            ->and($meta)->not->toContain('sk-or-v1');
    }
});

// =============================================================================
// États d'indisponibilité
// =============================================================================

it('masque l\'onglet Assistant quand l\'IA est désactivée', function () {
    config(['ai.enabled' => false]);

    Livewire::test(ContextualHelp::class)
        ->assertSet('assistantEnabled', false)
        ->assertDontSee('Assistant');
});

it('masque l\'onglet Assistant pour le compte de démonstration', function () {
    $demo = User::factory()->create(['email' => 'demo@openlmnp.fr']);

    Livewire::actingAs($demo)
        ->test(ContextualHelp::class)
        ->assertSet('assistantEnabled', false);
});

it('affiche l\'onglet Assistant pour un compte normal', function () {
    Livewire::test(ContextualHelp::class)
        ->assertSet('assistantEnabled', true)
        ->assertSee('Assistant');
});

it('garde l\'onglet et explique comment activer l\'assistant quand aucune clé n\'est configurée', function () {
    config(['ai.api_key' => null, 'ai.allow_user_keys' => true]);

    Livewire::test(ContextualHelp::class)->assertSet('assistantEnabled', true);

    Livewire::test(AiAssistant::class)
        ->assertSee('Mon profil')
        ->assertSee('clé OpenRouter');
});

it('affiche le message de quota et le journalise', function () {
    config(['ai.chat_daily_quota' => 1]);

    AiUsageLog::create([
        'user_id' => $this->user->id,
        'feature' => 'chat',
        'status' => 'ok',
        'model' => 'google/gemini-3.7-flash',
        'created_at' => now(),
    ]);

    Livewire::test(AiAssistant::class)
        ->set('input', 'Une question ?')
        ->call('send')
        ->assertSee("Vous avez atteint la limite de 1 messages aujourd'hui.")
        ->assertSee("L'onglet Aide reste disponible.", false);

    expect(AiUsageLog::where('status', 'quota')->count())->toBe(1);
    Http::assertNothingSent();
});

it('invite à repartir de zéro au-delà de la longueur maximale', function () {
    config(['ai.chat_max_turns' => 2]);
    fakeReponse(['Réponse.']);

    $component = Livewire::test(AiAssistant::class)
        ->set('input', 'Première question ?')
        ->call('send');

    Http::fake([BASE_CHAT.'/chat/completions' => Http::response(fluxAssistant(['Deuxième.']))]);

    $component->set('input', 'Deuxième question ?')
        ->call('send')
        ->assertSee('Démarrez une nouvelle conversation');
});

it('propose de réessayer après une panne du fournisseur', function () {
    Http::fake([BASE_CHAT.'/chat/completions' => Http::response(['error' => 'boom'], 503)]);

    Livewire::test(AiAssistant::class)
        ->set('input', 'Une question ?')
        ->call('send')
        ->assertSee('Réessayer')
        ->assertSet('busy', false);

    expect(AiUsageLog::where('status', 'error')->count())->toBeGreaterThan(0);
});

// =============================================================================
// Retours utilisateur et analytics
// =============================================================================

it('journalise un pouce sans contenu de conversation', function () {
    fakeReponse(['Réponse.']);

    Livewire::test(AiAssistant::class)
        ->set('input', 'Une question ?')
        ->call('send')
        ->call('feedback', 1, true)
        ->assertSee('Merci pour votre retour.');

    $ligne = AiUsageLog::where('feature', 'chat')->where('status', 'ok')->first();

    expect($ligne->meta['feedback'])->toBe('up');
});

it('émet ai_chat_feedback avec le motif, sans texte', function () {
    fakeReponse(['Réponse.']);

    Livewire::test(AiAssistant::class)
        ->set('input', 'Une question ?')
        ->call('send')
        ->call('feedback', 1, false, 'inexact')
        ->assertDispatched('analytics', fn ($name, $params) => $params[0]['event'] === 'ai_chat_feedback'
            && $params[0]['up'] === false
            && $params[0]['reason'] === 'inexact');
});

it('émet ai_chat_opened à l\'activation de l\'onglet', function () {
    Livewire::test(AiAssistant::class, ['routeName' => 'filament.admin.resources.incomes.index'])
        ->call('opened')
        ->assertDispatched('analytics', fn ($name, $params) => $params[0]['event'] === 'ai_chat_opened'
            && $params[0]['route'] === 'filament.admin.resources.incomes');
});

it('n\'accepte qu\'un motif de la liste fermée sur un pouce vers le bas', function () {
    fakeReponse(['Réponse.']);

    Livewire::test(AiAssistant::class)
        ->set('input', 'Une question ?')
        ->call('send')
        ->call('feedback', 1, false, 'texte libre écrit par la personne');

    $ligne = AiUsageLog::where('feature', 'chat')->where('status', 'ok')->first();

    expect($ligne->meta['feedback'])->toBe('down')
        ->and($ligne->meta)->not->toHaveKey('reason');
});

it('émet les événements analytics sans jamais le texte de la question', function () {
    fakeReponse(['Réponse.']);

    Livewire::test(AiAssistant::class, ['routeName' => 'filament.admin.resources.expenses.index'])
        ->set('input', 'Où saisir une facture ?')
        ->call('send')
        ->assertDispatched('analytics', function ($name, $params) {
            $payload = $params[0];

            return $payload['event'] === 'ai_chat_question'
                && $payload['route'] === 'filament.admin.resources.expenses'
                && $payload['data_access'] === false
                && $payload['tools_used'] === 0
                && ! str_contains(json_encode($payload), 'facture');
        });
});

it('signale une indisponibilité en analytics', function () {
    config(['ai.chat_daily_quota' => 1]);

    AiUsageLog::create([
        'user_id' => $this->user->id,
        'feature' => 'chat',
        'status' => 'ok',
        'model' => 'google/gemini-3.7-flash',
        'created_at' => now(),
    ]);

    Livewire::test(AiAssistant::class)
        ->set('input', 'Une question ?')
        ->call('send')
        ->assertDispatched('analytics', fn ($name, $params) => $params[0]['event'] === 'ai_chat_unavailable'
            && $params[0]['reason'] === 'quota');
});

it('refuse une question de doctrine fiscale sans appeler le modèle', function () {
    Http::fake();

    $component = Livewire::test(AiAssistant::class)
        ->set('input', "Le BOFiP autorise-t-il l'amortissement du terrain ?")
        ->call('send');

    expect($component->get('messages')[1]['text'])
        ->toContain('Je ne peux pas vous conseiller sur votre situation fiscale');

    Http::assertNothingSent();

    expect(AiUsageLog::where('status', 'refused')->first()->meta)->toBe(['guard' => 'scope']);
});
