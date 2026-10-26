<?php

use App\Models\User;
use App\Services\Ai\AiClient;
use App\Services\Ai\Analysis\Prompts\Prompt;
use App\Services\Ai\Providers\AiProviders;
use App\Services\Ai\Providers\GeminiProvider;
use App\Services\Ai\Providers\StreamState;
use Illuminate\Support\Facades\Http;

/**
 * Pilotes natifs Anthropic et Google Gemini.
 *
 * Trois défauts guettent ici, tous silencieux et tous coûteux :
 *   - un schéma que Gemini refuse, et chaque analyse échoue en 400 ;
 *   - un JSON d'extraction qu'Anthropic rend dans l'entrée d'un outil et non en
 *     texte, et chaque document déclenche une passe de réparation facturée ;
 *   - une clé Google en chaîne de requête, et elle se retrouve dans les
 *     journaux d'accès de tout ce qui est sur le chemin.
 */
const BASE_ANTHROPIC = 'https://anthropic.test/v1';
const BASE_GEMINI = 'https://gemini.test/v1beta';

function clientPour(string $fournisseur, string $baseUrl): AiClient
{
    config([
        'ai.enabled' => true,
        'ai.api_key' => 'cle-de-test',
        'ai.provider' => $fournisseur,
        'ai.base_url' => $baseUrl,
        'ai.models.documents' => 'modele-test',
        'ai.models.chat' => 'modele-test',
        'ai.model_prices' => [],
        'ai.pdf_text_fallback' => 'never',
        'demo.email' => 'demo@openlmnp.fr',
    ]);

    return AiClient::for(User::factory()->create());
}

function messagesDocument(): array
{
    return [
        ['role' => 'system', 'content' => 'Tu extrais des données.'],
        ['role' => 'user', 'content' => [AiClient::filePart('facture.pdf', 'JVBERi0K')]],
        ['role' => 'user', 'content' => 'Extrais les données.'],
    ];
}

// =============================================================================
// Anthropic
// =============================================================================

it('traduit une conversation vers le dialecte d\'Anthropic', function () {
    Http::fake([BASE_ANTHROPIC . '/messages' => Http::response([
        'id' => 'msg_1',
        'model' => 'modele-test',
        'content' => [['type' => 'tool_use', 'name' => 'extraction', 'input' => ['montant' => '12.00']]],
        'usage' => ['input_tokens' => 100, 'output_tokens' => 20],
        'stop_reason' => 'tool_use',
    ])]);

    clientPour('anthropic', BASE_ANTHROPIC)
        ->completeJson('documents', messagesDocument(), ['name' => 'facture', 'schema' => ['type' => 'object']]);

    Http::assertSent(function ($request) {
        $corps = $request->data();

        return $request->url() === BASE_ANTHROPIC . '/messages'
            // Clé en x-api-key, jamais en Bearer.
            && $request->hasHeader('x-api-key', 'cle-de-test')
            && ! $request->hasHeader('Authorization')
            && $request->hasHeader('anthropic-version')
            // La consigne système sort de la liste des messages.
            && $corps['system'] === 'Tu extrais des données.'
            && count($corps['messages']) === 2
            // Le PDF part en bloc `document`, pas en couche texte extraite.
            && $corps['messages'][0]['content'][0]['type'] === 'document'
            && $corps['messages'][0]['content'][0]['source']['media_type'] === 'application/pdf'
            // max_tokens est obligatoire chez Anthropic.
            && isset($corps['max_tokens'])
            // Le schéma se sert par un outil dont l'usage est imposé.
            && $corps['tool_choice'] === ['type' => 'tool', 'name' => 'extraction'];
    });
});

it('lit le JSON d\'extraction dans l\'entrée de l\'outil, et non en texte', function () {
    // ⚠️ LE piège du pilote. Sans cette normalisation, AiClient juge la réponse
    // vide, lève AiBadResponse, et DocumentAnalysisService déclenche une passe
    // de réparation — facturée — sur chaque document analysé.
    Http::fake([BASE_ANTHROPIC . '/messages' => Http::response([
        'id' => 'msg_2',
        'model' => 'modele-test',
        'content' => [['type' => 'tool_use', 'name' => 'extraction', 'input' => ['montant' => '143.20']]],
        'usage' => ['input_tokens' => 100, 'output_tokens' => 20],
    ])]);

    $result = clientPour('anthropic', BASE_ANTHROPIC)
        ->completeJson('documents', messagesDocument(), ['name' => 'facture', 'schema' => ['type' => 'object']]);

    expect($result->content)->toBe(['montant' => '143.20'])
        ->and($result->promptTokens)->toBe(100)
        ->and($result->completionTokens)->toBe(20)
        // Anthropic ne facture pas dans sa réponse : mieux vaut aucun coût
        // qu'un chiffre inventé.
        ->and($result->costUsd)->toBeNull();
});

it('rend les appels d\'outils d\'Anthropic au format canonique', function () {
    // Le consommateur réinjecte ce message tel quel dans la conversation : il
    // doit être au format OpenAI, sans quoi la boucle d'outils de l'assistant
    // ne s'y retrouverait pas.
    config(['ai.provider' => 'anthropic', 'ai.base_url' => BASE_ANTHROPIC]);

    $reponse = AiProviders::resolve()->parseResponse([
        'id' => 'msg_3',
        'model' => 'modele-test',
        'content' => [
            ['type' => 'text', 'text' => 'Je regarde.'],
            ['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'list_properties', 'input' => ['limit' => 5]],
        ],
        'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
        'stop_reason' => 'tool_use',
    ], 'modele-test');

    expect($reponse->content)->toBe('Je regarde.')
        ->and($reponse->toolCalls)->toBe([[
            'id' => 'toolu_1',
            'type' => 'function',
            'function' => ['name' => 'list_properties', 'arguments' => '{"limit":5}'],
        ]])
        ->and($reponse->message['role'])->toBe('assistant')
        ->and($reponse->message['tool_calls'])->toBe($reponse->toolCalls)
        ->and($reponse->finishReason)->toBe('tool_use');
});

it('recompose un appel d\'outil diffusé fragment par fragment', function () {
    // Anthropic nomme ses événements et livre les arguments en morceaux ; il
    // termine par `message_stop`, jamais par « data: [DONE] ».
    config(['ai.provider' => 'anthropic', 'ai.base_url' => BASE_ANTHROPIC]);

    $provider = AiProviders::resolve();
    $etat = new StreamState('modele-test');

    $provider->decodeStreamEvent('message_start', ['message' => ['id' => 'msg_4', 'usage' => ['input_tokens' => 7]]], $etat);
    $provider->decodeStreamEvent('content_block_start', ['index' => 0, 'content_block' => ['type' => 'tool_use', 'id' => 'toolu_9', 'name' => 'get_property']], $etat);
    $provider->decodeStreamEvent('content_block_delta', ['index' => 0, 'delta' => ['type' => 'input_json_delta', 'partial_json' => '{"id"']], $etat);
    $provider->decodeStreamEvent('content_block_delta', ['index' => 0, 'delta' => ['type' => 'input_json_delta', 'partial_json' => ':3}']], $etat);
    $provider->decodeStreamEvent('message_delta', ['delta' => ['stop_reason' => 'tool_use'], 'usage' => ['output_tokens' => 4]], $etat);
    $provider->decodeStreamEvent('message_stop', [], $etat);

    expect($etat->done)->toBeTrue()
        ->and($etat->orderedToolCalls())->toBe([[
            'id' => 'toolu_9',
            'type' => 'function',
            'function' => ['name' => 'get_property', 'arguments' => '{"id":3}'],
        ]])
        ->and($etat->usage)->toBe(['prompt_tokens' => 7, 'completion_tokens' => 4])
        ->and($etat->finishReason)->toBe('tool_use');
});

it('n\'engage aucun second appel quand Anthropic refuse le schéma', function () {
    // Chez Anthropic le schéma passe par un outil forcé : un 400 est une erreur
    // de configuration, pas une lacune du serveur. Relancer coûterait sans rien
    // réparer — d'où Capability::StructuredOutput à faux.
    Http::fake([BASE_ANTHROPIC . '/messages' => Http::response(['error' => 'invalid json_schema'], 400)]);

    expect(fn () => clientPour('anthropic', BASE_ANTHROPIC)
        ->completeJson('documents', messagesDocument(), ['name' => 'x', 'schema' => ['type' => 'object']]))
        ->toThrow(\App\Services\Ai\Exceptions\AiBadResponse::class);

    Http::assertSentCount(1);
});

// =============================================================================
// Google Gemini
// =============================================================================

it('met le modèle dans l\'URL et la clé dans un en-tête', function () {
    Http::fake([BASE_GEMINI . '/models/*' => Http::response([
        'candidates' => [['content' => ['parts' => [['text' => '{"montant":"12.00"}']]], 'finishReason' => 'STOP']],
        'usageMetadata' => ['promptTokenCount' => 50, 'candidatesTokenCount' => 10],
    ])]);

    clientPour('gemini', BASE_GEMINI)
        ->completeJson('documents', messagesDocument(), ['name' => 'facture', 'schema' => ['type' => 'object']]);

    Http::assertSent(function ($request) {
        // ⚠️ Une clé en chaîne de requête atterrit dans les journaux d'accès de
        // Google, dans ceux de tout proxy traversé, et dans l'URL elle-même.
        return ! str_contains($request->url(), 'key=')
            && $request->hasHeader('x-goog-api-key', 'cle-de-test')
            && ! $request->hasHeader('Authorization')
            && $request->url() === BASE_GEMINI . '/models/modele-test:generateContent';
    });
});

it('retire du schéma les mots-clés que Gemini refuse', function () {
    // Le schéma d'extraction réel de l'application pose cinq
    // `additionalProperties`, et AiClient y ajoute `strict`. Sans ce nettoyage,
    // CHAQUE analyse de document échouerait en 400.
    expect(json_encode(Prompt::schema()))->toContain('additionalProperties');

    $propre = GeminiProvider::sanitizeSchema(Prompt::schema()['schema'] + ['strict' => true]);

    expect(json_encode($propre))
        ->not->toContain('additionalProperties')
        ->not->toContain('strict')
        // Le nettoyage ne doit pas vider le schéma au passage.
        ->and($propre['type'])->toBe('object')
        ->and($propre['properties'])->not->toBeEmpty();
});

it('envoie un schéma déjà nettoyé dans generationConfig', function () {
    Http::fake([BASE_GEMINI . '/models/*' => Http::response([
        'candidates' => [['content' => ['parts' => [['text' => '{"a":1}']]]]],
        'usageMetadata' => ['promptTokenCount' => 1, 'candidatesTokenCount' => 1],
    ])]);

    clientPour('gemini', BASE_GEMINI)->completeJson('documents', messagesDocument(), Prompt::schema());

    Http::assertSent(function ($request) {
        $corps = $request->data();

        return ! str_contains(json_encode($corps), 'additionalProperties')
            && $corps['generationConfig']['responseMimeType'] === 'application/json'
            // `low` ramène le budget de raisonnement à zéro : c'est la mesure
            // qui a fait disparaître 532 jetons facturés sur une question triviale.
            && $corps['generationConfig']['thinkingConfig']['thinkingBudget'] === 0;
    });
});

it('traduit une conversation vers le dialecte de Gemini', function () {
    Http::fake([BASE_GEMINI . '/models/*' => Http::response([
        'modelVersion' => 'modele-test',
        'candidates' => [['content' => ['parts' => [['text' => '{"montant":"9.90"}']]], 'finishReason' => 'STOP']],
        'usageMetadata' => ['promptTokenCount' => 42, 'candidatesTokenCount' => 7],
    ])]);

    $result = clientPour('gemini', BASE_GEMINI)
        ->completeJson('documents', messagesDocument(), ['name' => 'facture', 'schema' => ['type' => 'object']]);

    Http::assertSent(function ($request) {
        $corps = $request->data();

        return $corps['systemInstruction']['parts'][0]['text'] === 'Tu extrais des données.'
            && count($corps['contents']) === 2
            // Le PDF part en inline_data, pas en couche texte extraite.
            && $corps['contents'][0]['parts'][0]['inline_data']['mime_type'] === 'application/pdf'
            && $corps['contents'][1]['role'] === 'user';
    });

    expect($result->content)->toBe(['montant' => '9.90'])
        ->and($result->promptTokens)->toBe(42)
        ->and($result->model)->toBe('modele-test');
});

it('corrige un préfixe d\'éditeur, sans jamais traduire depuis un autre', function (string $fournisseur, string $baseUrl, string $saisi, string $attendu) {
    config(['ai.provider' => $fournisseur, 'ai.base_url' => $baseUrl]);

    expect(AiProviders::resolve()->normalizeModel($saisi))->toBe($attendu);
})->with([
    'préfixe Google chez Google' => ['gemini', BASE_GEMINI, 'google/gemini-3.7-flash', 'gemini-3.7-flash'],
    'préfixe models/ chez Google' => ['gemini', BASE_GEMINI, 'models/gemini-3.7-flash', 'gemini-3.7-flash'],
    'préfixe Anthropic chez Anthropic' => ['anthropic', BASE_ANTHROPIC, 'anthropic/claude-sonnet-5', 'claude-sonnet-5'],
    // Doit échouer en 404 chez le fournisseur plutôt que d'être « deviné » :
    // envoyer les documents à un modèle non choisi serait pire qu'une erreur.
    'slug Google chez Anthropic' => ['anthropic', BASE_ANTHROPIC, 'google/gemini-3.7-flash', 'google/gemini-3.7-flash'],
]);
