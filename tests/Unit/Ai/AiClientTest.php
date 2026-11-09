<?php

use App\Models\AiUsageLog;
use App\Models\User;
use App\Services\Ai\AiClient;
use App\Services\Ai\Exceptions\AiBadResponse;
use App\Services\Ai\Exceptions\AiException;
use App\Services\Ai\Exceptions\AiInsufficientCredits;
use App\Services\Ai\Exceptions\AiKeyInvalid;
use App\Services\Ai\Exceptions\AiModelUnavailable;
use App\Services\Ai\Exceptions\AiRateLimited;
use App\Services\Ai\Exceptions\AiTimeout;
use App\Services\Ai\Exceptions\AiUnavailable;
use GuzzleHttp\Promise\FulfilledPromise;
use GuzzleHttp\Psr7\PumpStream;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

/** Clé factice, jamais réelle : sert aussi de sonde anti-fuite. */
const CLE_FACTICE = 'sk-or-v1-cle-de-test-ne-doit-jamais-fuiter';

const BASE = 'https://openrouter.test/api/v1';

beforeEach(function () {
    // Les temporisations avant nouvelle tentative ne doivent pas ralentir la suite.
    Sleep::fake();

    config([
        'ai.enabled' => true,
        'ai.api_key' => CLE_FACTICE,
        'ai.allow_user_keys' => true,
        // Épinglé : l'hôte de test n'est pas openrouter.ai, la détection
        // automatique le classerait donc en serveur local.
        'ai.provider' => 'openrouter',
        'ai.base_url' => BASE,
        'ai.timeout' => 60,
        'ai.data_collection' => 'deny',
        'ai.app_referer' => 'https://openlmnp.fr',
        'ai.app_title' => 'OpenLMNP',
        'ai.pdf_engine' => 'native',
        'ai.models.documents' => 'google/gemini-3.7-flash',
        'ai.models.chat' => 'google/gemini-3.7-flash',
        'ai.model_prices' => [],
        'demo.email' => 'demo@openlmnp.fr',
    ]);

    $this->user = User::factory()->create();
    $this->client = AiClient::for($this->user);
});

/**
 * Réponse de complétion classique.
 *
 * `usage` est remplacé en bloc (et non fusionné) : sans quoi le `cost` par
 * défaut survivrait aux tests qui vérifient justement son absence.
 */
function reponseCompletion(array $content = ['montant' => '143.20'], array $extra = [], ?array $usage = null): array
{
    return array_replace_recursive([
        'id' => 'gen-abc123',
        'model' => 'google/gemini-3.7-flash',
        'choices' => [[
            'message' => ['role' => 'assistant', 'content' => json_encode($content)],
            'finish_reason' => 'stop',
        ]],
        'usage' => $usage ?? ['prompt_tokens' => 4000, 'completion_tokens' => 500, 'cost' => 0.0024],
    ], $extra);
}

function schemaFacture(): array
{
    return ['name' => 'facture', 'schema' => ['type' => 'object', 'properties' => ['montant' => ['type' => 'string']]]];
}

function messagesTexte(): array
{
    return [['role' => 'user', 'content' => 'Extrais les données de cette facture.']];
}

/**
 * Conversation la plus riche que l'application produise : consigne système,
 * message utilisateur porteur d'un PDF, puis la question.
 *
 * @return array<mixed>
 */
function messagesAvecPdf(): array
{
    return [
        ['role' => 'system', 'content' => 'Tu extrais des données de justificatifs.'],
        ['role' => 'user', 'content' => [AiClient::filePart('facture.pdf', 'JVBERi0xLjQK')]],
        ['role' => 'user', 'content' => 'Extrais les données de cette facture.'],
    ];
}

/**
 * Trie les clés en profondeur : deux charges utiles identiques au sens de l'API
 * ne doivent pas être déclarées différentes pour un simple ordre de clés, mais
 * la comparaison reste stricte sur les types (=== après tri).
 *
 * @param  array<mixed>  $valeur
 * @return array<mixed>
 */
function trieRecursivement(array $valeur): array
{
    ksort($valeur);

    foreach ($valeur as $cle => $element) {
        if (is_array($element)) {
            $valeur[$cle] = trieRecursivement($element);
        }
    }

    return $valeur;
}

// =============================================================================
// Charge utile de référence — VERROU DE NON-RÉGRESSION
// =============================================================================

/**
 * Ce test fige, au champ près, ce que l'application envoie aujourd'hui à
 * OpenRouter dans son cas le plus riche : PDF joint (donc plugin `file-parser`),
 * schéma strict, budget de raisonnement, annotations d'un parsing déjà facturé
 * et plafond de jetons.
 *
 * ⚠️ Il ne doit PAS être modifié par un travail d'abstraction multi-fournisseur.
 * Il est la seule preuve mécanique qu'un utilisateur OpenRouter existant reçoit
 * exactement la même requête après refactorisation. S'il devient rouge, c'est la
 * refactorisation qui a changé de comportement, pas le test qui a vieilli.
 */
it('émet exactement la charge utile OpenRouter de référence', function () {
    Http::fake([BASE . '/chat/completions' => Http::response(reponseCompletion())]);

    $annotations = [['type' => 'file', 'file' => ['hash' => 'abc123', 'name' => 'facture.pdf']]];

    $this->client->completeJson('documents', messagesAvecPdf(), schemaFacture(), null, [
        'max_tokens' => 4000,
        'file_annotations' => $annotations,
    ]);

    $attendu = [
        'model' => 'google/gemini-3.7-flash',
        'messages' => [
            ['role' => 'system', 'content' => 'Tu extrais des données de justificatifs.'],
            ['role' => 'user', 'content' => [[
                'type' => 'file',
                'file' => ['filename' => 'facture.pdf', 'file_data' => 'data:application/pdf;base64,JVBERi0xLjQK'],
            ]]],
            // Les annotations se rejouent sur un message ASSISTANT inséré juste
            // après le message porteur du fichier : c'est ce qu'attend OpenRouter
            // pour ne pas re-parser — ni refacturer — le document.
            ['role' => 'assistant', 'content' => 'Document lu.', 'annotations' => $annotations],
            ['role' => 'user', 'content' => 'Extrais les données de cette facture.'],
        ],
        'temperature' => 0.0,
        'provider' => ['data_collection' => 'deny'],
        'usage' => ['include' => true],
        'max_tokens' => 4000,
        'reasoning' => ['effort' => 'low'],
        'plugins' => [['id' => 'file-parser', 'pdf' => ['engine' => 'native']]],
        'response_format' => [
            'type' => 'json_schema',
            'json_schema' => schemaFacture() + ['strict' => true],
        ],
    ];

    Http::assertSent(fn ($request) => trieRecursivement($request->data()) === trieRecursivement($attendu));
});

// =============================================================================
// Requête émise
// =============================================================================

it('envoie les en-têtes OpenRouter et la clé en Bearer', function () {
    Http::fake([BASE . '/chat/completions' => Http::response(reponseCompletion())]);

    $this->client->completeJson('documents', messagesTexte(), schemaFacture());

    Http::assertSent(function ($request) {
        return $request->url() === BASE . '/chat/completions'
            && $request->hasHeader('Authorization', 'Bearer ' . CLE_FACTICE)
            && $request->hasHeader('HTTP-Referer', 'https://openlmnp.fr')
            && $request->hasHeader('X-Title', 'OpenLMNP');
    });
});

it('demande la non-conservation des données, le coût, et une température nulle', function () {
    Http::fake([BASE . '/chat/completions' => Http::response(reponseCompletion())]);

    $this->client->completeJson('documents', messagesTexte(), schemaFacture());

    Http::assertSent(function ($request) {
        return $request['provider']['data_collection'] === 'deny'
            && $request['usage']['include'] === true
            && $request['temperature'] === 0.0
            && $request['model'] === 'google/gemini-3.7-flash';
    });
});

it('réduit le raisonnement du modèle selon la configuration de la fonction documents', function () {
    config(['ai.reasoning.documents' => 'low']);
    Http::fake([BASE . '/chat/completions' => Http::response(reponseCompletion())]);

    $this->client->completeJson('documents', messagesTexte(), schemaFacture());

    // Sans cela, Gemini 3.x Flash facture des centaines de jetons de raisonnement
    // invisibles et les prélève sur max_tokens (réponse coupée en plein mot).
    Http::assertSent(fn ($request) => $request['reasoning'] === ['effort' => 'low']);
});

it('applique la configuration de raisonnement propre à l\'assistant en flux', function () {
    config(['ai.reasoning.chat' => 'medium']);
    Http::fake([BASE . '/chat/completions' => Http::response(fluxSse([
        ['choices' => [['delta' => ['content' => 'Réponse.']]]],
    ]))]);

    $this->client->stream('chat', messagesTexte());

    Http::assertSent(fn ($request) => ($request['stream'] ?? false) === true
        && $request['reasoning'] === ['effort' => 'medium']);
});

it('n\'envoie aucun paramètre de raisonnement quand la configuration vaut none', function () {
    config(['ai.reasoning.documents' => 'none']);
    Http::fake([BASE . '/chat/completions' => Http::response(reponseCompletion())]);

    $this->client->completeJson('documents', messagesTexte(), schemaFacture());

    Http::assertSent(fn ($request) => ! array_key_exists('reasoning', $request->data()));
});

it('laisse une option reasoning explicite primer sur la configuration', function () {
    config(['ai.reasoning.documents' => 'low']);
    Http::fake([BASE . '/chat/completions' => Http::response(reponseCompletion())]);

    $this->client->completeJson('documents', messagesTexte(), schemaFacture(), options: [
        'reasoning' => ['effort' => 'high'],
    ]);
    $this->client->completeJson('documents', messagesTexte(), schemaFacture(), options: [
        'reasoning' => null,
    ]);

    Http::assertSent(fn ($request) => ($request['reasoning'] ?? null) === ['effort' => 'high']);
    Http::assertSent(fn ($request) => ! array_key_exists('reasoning', $request->data()));
});

it('contraint la sortie par un schéma JSON strict', function () {
    Http::fake([BASE . '/chat/completions' => Http::response(reponseCompletion())]);

    $this->client->completeJson('documents', messagesTexte(), schemaFacture(), options: ['max_tokens' => 4000]);

    Http::assertSent(function ($request) {
        return $request['response_format']['type'] === 'json_schema'
            && $request['response_format']['json_schema']['name'] === 'facture'
            && $request['response_format']['json_schema']['strict'] === true
            && $request['max_tokens'] === 4000;
    });
});

it('décode le contenu JSON dans AiResult', function () {
    Http::fake([BASE . '/chat/completions' => Http::response(reponseCompletion(['montant' => '143.20', 'categorie' => 'energy']))]);

    $result = $this->client->completeJson('documents', messagesTexte(), schemaFacture());

    expect($result->json())->toBe(['montant' => '143.20', 'categorie' => 'energy'])
        ->and($result->model)->toBe('google/gemini-3.7-flash')
        ->and($result->promptTokens)->toBe(4000)
        ->and($result->completionTokens)->toBe(500)
        ->and($result->providerRequestId)->toBe('gen-abc123')
        ->and($result->finishReason)->toBe('stop');
});

// =============================================================================
// Fichiers joints — moteur PDF toujours explicite
// =============================================================================

it('joint le plugin file-parser avec un moteur explicite dès qu\'un PDF est envoyé', function () {
    Http::fake([BASE . '/chat/completions' => Http::response(reponseCompletion())]);

    $messages = [[
        'role' => 'user',
        'content' => [
            ['type' => 'text', 'text' => 'Analyse ce document.'],
            AiClient::filePart('facture.pdf', base64_encode('%PDF-1.4 factice')),
        ],
    ]];

    $this->client->completeJson('documents', $messages, schemaFacture());

    Http::assertSent(function ($request) {
        // Sans moteur explicite, OpenRouter facturerait mistral-ocr (2 $/1000 pages).
        return $request['plugins'][0]['id'] === 'file-parser'
            && $request['plugins'][0]['pdf']['engine'] === 'native';
    });
});

it('n\'ajoute aucun plugin quand aucun fichier n\'est joint', function () {
    Http::fake([BASE . '/chat/completions' => Http::response(reponseCompletion())]);

    $this->client->completeJson('documents', messagesTexte(), schemaFacture());

    Http::assertSent(fn ($request) => ! isset($request['plugins']));
});

it('construit une partie image en data URL', function () {
    $part = AiClient::imagePart('image/jpeg', 'QUJD');

    expect($part['type'])->toBe('image_url')
        ->and($part['image_url']['url'])->toBe('data:image/jpeg;base64,QUJD');
});

/** Conversation de suivi : le message porteur du fichier puis une consigne. */
function messagesAvecFichierPuisConsigne(): array
{
    return [
        ['role' => 'system', 'content' => 'Tu extrais des factures.'],
        ['role' => 'user', 'content' => [
            ['type' => 'text', 'text' => 'Analyse ce document.'],
            AiClient::filePart('facture.pdf', base64_encode('%PDF-1.4 factice')),
        ]],
        ['role' => 'user', 'content' => 'Le JSON précédent était invalide, recommence.'],
    ];
}

it('renvoie les file_annotations reçues, avec le message assistant qui les porte', function () {
    $annotations = [['type' => 'file', 'file' => ['hash' => 'abc', 'content' => 'texte extrait']]];

    Http::fake([BASE . '/chat/completions' => Http::response(reponseCompletion(extra: [
        'choices' => [['message' => ['annotations' => $annotations]]],
    ]))]);

    $result = $this->client->completeJson('documents', messagesTexte(), schemaFacture());

    expect($result->fileAnnotations)->toBe($annotations)
        // Le message brut est réinjectable tel quel dans une conversation de suivi.
        ->and($result->message['annotations'])->toBe($annotations);
});

it('rejoue les annotations sur un message assistant inséré après le fichier', function () {
    $annotations = [['type' => 'file', 'file' => ['hash' => 'abc', 'content' => 'texte extrait']]];
    Http::fake([BASE . '/chat/completions' => Http::response(reponseCompletion())]);

    $this->client->completeJson('documents', messagesAvecFichierPuisConsigne(), schemaFacture(), options: [
        'file_annotations' => $annotations,
    ]);

    // OpenRouter attend les annotations sur un message ASSISTANT de la requête
    // de suivi, le message utilisateur gardant sa partie fichier : c'est ce qui
    // lui permet de ne pas re-parser (ni refacturer) le document.
    Http::assertSent(function ($request) use ($annotations) {
        $messages = $request['messages'];

        return count($messages) === 4
            && $messages[1]['role'] === 'user'
            && ! isset($messages[1]['annotations'])
            && $messages[1]['content'][1]['type'] === 'file'
            && $messages[2]['role'] === 'assistant'
            && $messages[2]['annotations'] === $annotations
            && $messages[3]['role'] === 'user'
            && $messages[3]['content'] === 'Le JSON précédent était invalide, recommence.';
    });
});

it('ne duplique pas les annotations déjà portées par un message assistant', function () {
    $annotations = [['type' => 'file', 'file' => ['hash' => 'abc', 'content' => 'texte extrait']]];
    Http::fake([BASE . '/chat/completions' => Http::response(reponseCompletion())]);

    $messages = messagesAvecFichierPuisConsigne();
    array_splice($messages, 2, 0, [['role' => 'assistant', 'content' => '{"montant":"x"}', 'annotations' => $annotations]]);

    $this->client->completeJson('documents', $messages, schemaFacture(), options: [
        'file_annotations' => $annotations,
    ]);

    Http::assertSent(function ($request) {
        $porteurs = array_filter($request['messages'], fn ($m) => ! empty($m['annotations']));

        return count($request['messages']) === 4 && count($porteurs) === 1;
    });
});

// =============================================================================
// Repli json_object
// =============================================================================

it('retente en json_object quand le serveur refuse json_schema', function () {
    Http::fake([BASE . '/chat/completions' => Http::sequence()
        ->push(['error' => ['message' => 'response_format json_schema is not supported']], 400)
        ->push(reponseCompletion(), 200),
    ]);

    $result = $this->client->completeJson('documents', messagesTexte(), schemaFacture());

    expect($result->json())->toBe(['montant' => '143.20']);
    Http::assertSentCount(2);

    expect(AiUsageLog::withoutGlobalScopes()->first()->meta)
        ->toHaveKey('response_format', 'json_object');
});

it('ne retente pas quand le 400 ne vient pas du format de réponse', function () {
    Http::fake([BASE . '/chat/completions' => Http::response(['error' => ['message' => 'invalid model id']], 400)]);

    expect(fn () => $this->client->completeJson('documents', messagesTexte(), schemaFacture()))
        ->toThrow(AiBadResponse::class);

    Http::assertSentCount(1);
});

it('ne relance aucun appel quand seul le contenu est mal formé', function () {
    Http::fake([BASE . '/chat/completions' => Http::response([
        'choices' => [['message' => ['content' => 'ceci n\'est pas du JSON']]],
    ])]);

    expect(fn () => $this->client->completeJson('documents', messagesTexte(), schemaFacture()))
        ->toThrow(AiBadResponse::class);

    // Un contenu illisible ne doit pas déclencher un second appel payant.
    Http::assertSentCount(1);
});

// =============================================================================
// Nouvelles tentatives
// =============================================================================

it('retente une fois sur 429 puis réussit', function () {
    Http::fake([BASE . '/chat/completions' => Http::sequence()
        ->push(['error' => 'rate limited'], 429)
        ->push(reponseCompletion(), 200),
    ]);

    $result = $this->client->completeJson('documents', messagesTexte(), schemaFacture());

    expect($result->json())->toBe(['montant' => '143.20']);
    Http::assertSentCount(2);
    expect(AiUsageLog::withoutGlobalScopes()->first()->meta)->toHaveKey('retried', true);
});

it('retente une fois sur 5xx puis réussit', function () {
    Http::fake([BASE . '/chat/completions' => Http::sequence()
        ->push(['error' => 'bad gateway'], 502)
        ->push(reponseCompletion(), 200),
    ]);

    expect($this->client->completeJson('documents', messagesTexte(), schemaFacture())->json())
        ->toBe(['montant' => '143.20']);

    Http::assertSentCount(2);
});

it('abandonne après une seule nouvelle tentative', function () {
    Http::fake([BASE . '/chat/completions' => Http::response(['error' => 'rate limited'], 429)]);

    expect(fn () => $this->client->completeJson('documents', messagesTexte(), schemaFacture()))
        ->toThrow(AiRateLimited::class);

    Http::assertSentCount(2);
});

it('ne retente jamais sur une erreur définitive', function (int $status, string $exception) {
    Http::fake([BASE . '/chat/completions' => Http::response(['error' => 'refusé'], $status)]);

    expect(fn () => $this->client->completeJson('documents', messagesTexte(), schemaFacture()))
        ->toThrow($exception);

    // Réessayer ne ferait que doubler le coût sans aucune chance de succès.
    Http::assertSentCount(1);
})->with([
    [401, AiKeyInvalid::class],
    [403, AiKeyInvalid::class],
    [402, AiInsufficientCredits::class],
]);

it('traduit 404 et tout 5xx en modèle indisponible', function (int $status) {
    Http::fake([BASE . '/chat/completions' => Http::response(['error' => 'nope'], $status)]);

    // Une panne du fournisseur n'est pas une « réponse inexploitable » du modèle.
    expect(fn () => $this->client->completeJson('documents', messagesTexte(), schemaFacture()))
        ->toThrow(AiModelUnavailable::class);
})->with([404, 500, 502, 503, 504]);

it('temporise avant la nouvelle tentative, en respectant Retry-After plafonné', function () {
    Http::fake([BASE . '/chat/completions' => Http::sequence()
        ->push(['error' => 'rate limited'], 429, ['Retry-After' => '3'])
        ->push(reponseCompletion(), 200),
    ]);

    $this->client->completeJson('documents', messagesTexte(), schemaFacture());

    Sleep::assertSequence([Sleep::for(3)->seconds()]);
});

it('plafonne un Retry-After excessif', function () {
    Http::fake([BASE . '/chat/completions' => Http::sequence()
        ->push(['error' => 'rate limited'], 429, ['Retry-After' => '120'])
        ->push(['error' => 'bad gateway'], 502),
    ]);

    // 429 (Retry-After 120 → plafonné à 5 s) puis 502 : une seule nouvelle
    // tentative, la requête de l'utilisateur ne doit pas attendre deux minutes.
    expect(fn () => $this->client->completeJson('documents', messagesTexte(), schemaFacture()))
        ->toThrow(AiModelUnavailable::class);

    Sleep::assertSequence([Sleep::for(5)->seconds()]);
});

it('attend une seconde par défaut avant de retenter sur 5xx', function () {
    Http::fake([BASE . '/chat/completions' => Http::sequence()
        ->push(['error' => 'oops'], 500)
        ->push(reponseCompletion(), 200),
    ]);

    $this->client->completeJson('documents', messagesTexte(), schemaFacture());

    Sleep::assertSequence([Sleep::for(1)->seconds()]);
});

it('journalise un modèle non configuré sans rien envoyer', function () {
    config(['ai.models.documents' => 'none']);

    expect(fn () => AiClient::for($this->user)->completeJson('documents', messagesTexte(), schemaFacture()))
        ->toThrow(AiModelUnavailable::class);

    Http::assertNothingSent();

    $log = AiUsageLog::withoutGlobalScopes()->sole();

    expect($log->status)->toBe('error')
        ->and($log->error_code)->toBe('AiModelUnavailable')
        ->and($log->model)->toBeNull();
});

it('retente une fois sur délai dépassé puis lève AiTimeout', function () {
    $appels = 0;
    Http::fake(function () use (&$appels) {
        $appels++;
        throw new ConnectionException('cURL error 28: Operation timed out');
    });

    expect(fn () => $this->client->completeJson('documents', messagesTexte(), schemaFacture()))
        ->toThrow(AiTimeout::class);

    expect($appels)->toBe(2);
});

it('réussit quand la seconde tentative aboutit après un délai dépassé', function () {
    $appels = 0;
    Http::fake(function () use (&$appels) {
        $appels++;

        if ($appels === 1) {
            throw new ConnectionException('timed out');
        }

        return Http::response(reponseCompletion());
    });

    expect($this->client->completeJson('documents', messagesTexte(), schemaFacture())->json())
        ->toBe(['montant' => '143.20']);
});

// =============================================================================
// Garde-fou de disponibilité
// =============================================================================

it('refuse d\'appeler quand l\'IA est indisponible pour ce compte', function () {
    config(['ai.enabled' => false]);

    expect(fn () => AiClient::for($this->user)->completeJson('documents', messagesTexte(), schemaFacture()))
        ->toThrow(AiUnavailable::class);

    Http::assertNothingSent();
});

it('refuse d\'appeler pour un compte de démonstration', function () {
    Http::fake();
    $demo = User::factory()->create(['is_demo' => true]);

    expect(fn () => AiClient::for($demo)->completeJson('documents', messagesTexte(), schemaFacture()))
        ->toThrow(AiUnavailable::class);

    Http::assertNothingSent();
});

// =============================================================================
// ⚠️ INVARIANT : la clé ne fuite JAMAIS
// =============================================================================

it('ne laisse jamais la clé apparaître dans un message d\'exception', function (int $status) {
    // La réponse du fournisseur contient la clé (cas réel : un fournisseur qui
    // renvoie la requête en écho) — elle ne doit pas ressortir.
    Http::fake([BASE . '/chat/completions' => Http::response([
        'error' => ['message' => 'Invalid key ' . CLE_FACTICE, 'metadata' => ['authorization' => 'Bearer ' . CLE_FACTICE]],
    ], $status)]);

    try {
        $this->client->completeJson('documents', messagesTexte(), schemaFacture());
        $this->fail("Une exception était attendue.");
    } catch (AiException $e) {
        expect($e->getMessage())->not->toContain(CLE_FACTICE)
            ->and($e->getMessage())->not->toContain('Bearer')
            // Ni la réponse brute du fournisseur.
            ->and($e->getMessage())->not->toContain('Invalid key')
            ->and($e->getTraceAsString())->not->toContain(CLE_FACTICE);
    }
})->with([400, 401, 402, 404, 429, 500]);

it('ne journalise jamais la clé', function () {
    Http::fake([BASE . '/chat/completions' => Http::response(reponseCompletion())]);

    $this->client->completeJson('documents', messagesTexte(), schemaFacture(), options: [
        'meta' => ['document_type' => 'expense_invoice', 'confidence_bucket' => 'high'],
    ]);

    $log = AiUsageLog::withoutGlobalScopes()->first();

    expect(json_encode($log->toArray()))->not->toContain(CLE_FACTICE)
        ->and($log->meta)->toBe(['document_type' => 'expense_invoice', 'confidence_bucket' => 'high']);
});

// =============================================================================
// Flux (assistant)
// =============================================================================

/** Construit un corps SSE à partir d'événements. */
function fluxSse(array $evenements, bool $termine = true): string
{
    $corps = ": OPENROUTER PROCESSING\n\n";

    foreach ($evenements as $evenement) {
        $corps .= 'data: ' . json_encode($evenement) . "\n\n";
    }

    return $termine ? $corps . "data: [DONE]\n\n" : $corps;
}

it('assemble les fragments de texte diffusés et les transmet au rappel', function () {
    Http::fake([BASE . '/chat/completions' => Http::response(fluxSse([
        ['id' => 'gen-stream', 'model' => 'google/gemini-3.7-flash', 'choices' => [['delta' => ['content' => 'Pour ']]]],
        ['choices' => [['delta' => ['content' => 'créer une charge']]]],
        ['choices' => [['delta' => ['content' => '…'], 'finish_reason' => 'stop']]],
        ['usage' => ['prompt_tokens' => 120, 'completion_tokens' => 30, 'cost' => 0.0001]],
    ]))]);

    $fragments = [];
    $result = $this->client->stream('chat', messagesTexte(), onDelta: function (string $delta) use (&$fragments) {
        $fragments[] = $delta;
    });

    expect($fragments)->toBe(['Pour ', 'créer une charge', '…'])
        ->and($result->text())->toBe('Pour créer une charge…')
        ->and($result->promptTokens)->toBe(120)
        ->and($result->completionTokens)->toBe(30)
        ->and($result->finishReason)->toBe('stop')
        ->and($result->providerRequestId)->toBe('gen-stream')
        ->and($result->hasToolCalls())->toBeFalse();
});

it('recompose les appels d\'outils diffusés fragment par fragment', function () {
    Http::fake([BASE . '/chat/completions' => Http::response(fluxSse([
        ['choices' => [['delta' => ['tool_calls' => [[
            'index' => 0, 'id' => 'call_1', 'function' => ['name' => 'list_expenses', 'arguments' => '{"year"'],
        ]]]]]],
        ['choices' => [['delta' => ['tool_calls' => [[
            'index' => 0, 'function' => ['arguments' => ':2026}'],
        ]]]]]],
        ['choices' => [['delta' => [], 'finish_reason' => 'tool_calls']]],
    ]))]);

    $result = $this->client->stream('chat', messagesTexte(), tools: [
        ['type' => 'function', 'function' => ['name' => 'list_expenses']],
    ]);

    expect($result->hasToolCalls())->toBeTrue()
        ->and($result->toolCalls)->toHaveCount(1)
        ->and($result->toolCalls[0]['id'])->toBe('call_1')
        ->and($result->toolCalls[0]['function']['name'])->toBe('list_expenses')
        ->and($result->toolCalls[0]['function']['arguments'])->toBe('{"year":2026}')
        // Le message assistant brut est réinjectable tel quel dans la conversation.
        ->and($result->message['role'])->toBe('assistant')
        ->and($result->message['tool_calls'])->toBe($result->toolCalls);

    Http::assertSent(fn ($request) => $request['stream'] === true && isset($request['tools']));
});

it('lit une dernière ligne non terminée par un saut de ligne', function () {
    Http::fake([BASE . '/chat/completions' => Http::response(
        'data: ' . json_encode(['choices' => [['delta' => ['content' => 'Réponse complète']]]])
    )]);

    expect($this->client->stream('chat', messagesTexte())->text())->toBe('Réponse complète');
});

it('rejette un flux totalement vide', function () {
    Http::fake([BASE . '/chat/completions' => Http::response(fluxSse([]))]);

    expect(fn () => $this->client->stream('chat', messagesTexte()))->toThrow(AiBadResponse::class);
});

it('traduit une coupure réseau en cours de flux en délai dépassé journalisé', function () {
    // Le 200 est arrivé, un premier fragment aussi, puis la connexion tombe :
    // l'exception de transport ne doit pas remonter brute (elle embarquerait
    // des détails de la requête) et l'appel doit être journalisé.
    $lectures = 0;
    $corps = new PumpStream(function () use (&$lectures) {
        if ($lectures++ === 0) {
            return 'data: ' . json_encode(['choices' => [['delta' => ['content' => 'Pour ']]]]) . "\n\n";
        }

        throw new RuntimeException('Connection reset by peer while reading ' . CLE_FACTICE);
    });

    Http::fake(fn () => new FulfilledPromise(new PsrResponse(200, ['Content-Type' => 'text/event-stream'], $corps)));

    try {
        $this->client->stream('chat', messagesTexte());
        $this->fail('Une AiTimeout était attendue.');
    } catch (AiTimeout $e) {
        expect($e->getMessage())->toContain('interrompue')
            ->and($e->getMessage())->not->toContain(CLE_FACTICE);
    }

    $log = AiUsageLog::withoutGlobalScopes()->sole();

    expect($log->status)->toBe('error')
        ->and($log->error_code)->toBe('AiTimeout');
});

// =============================================================================
// Vérification de clé
// =============================================================================

it('lit les informations de clé renvoyées par OpenRouter', function () {
    Http::fake([BASE . '/auth/key' => Http::response([
        'data' => ['label' => 'clé OpenLMNP', 'usage' => 1.25, 'limit_remaining' => 8.75],
    ])]);

    $status = $this->client->checkKey();

    expect($status->valid)->toBeTrue()
        ->and($status->label)->toBe('clé OpenLMNP')
        ->and($status->limitRemaining)->toBe('8.75')
        ->and($status->summary())->toContain('clé OpenLMNP')
        ->and($status->summary())->not->toContain(CLE_FACTICE);
});

it('se rabat sur /models quand /auth/key n\'existe pas', function () {
    Http::fake([
        BASE . '/auth/key' => Http::response(['error' => 'not found'], 404),
        BASE . '/models' => Http::response(['data' => [['id' => 'llama3']]]),
    ]);

    $status = $this->client->checkKey();

    expect($status->valid)->toBeTrue()
        ->and($status->label)->toContain('serveur configuré');
});

it('signale une clé refusée sans jamais la citer', function () {
    Http::fake([BASE . '/auth/key' => Http::response(['error' => 'invalid key ' . CLE_FACTICE], 401)]);

    $status = $this->client->checkKey();

    expect($status->valid)->toBeFalse()
        ->and($status->errorCode)->toBe('AiKeyInvalid')
        ->and($status->summary())->not->toContain(CLE_FACTICE);

    expect(AiUsageLog::withoutGlobalScopes()->where('feature', 'key_check')->first()->error_code)
        ->toBe('AiKeyInvalid');
});

it('ne tente aucune vérification sans clé', function () {
    Http::fake();
    config(['ai.api_key' => null]);

    $status = AiClient::for($this->user)->checkKey();

    expect($status->valid)->toBeFalse()
        ->and($status->errorCode)->toBe('AiUnavailable');

    Http::assertNothingSent();
});

// =============================================================================
// Coût
// =============================================================================

it('journalise le coût renvoyé par le fournisseur', function () {
    Http::fake([BASE . '/chat/completions' => Http::response(reponseCompletion(
        usage: ['prompt_tokens' => 4000, 'completion_tokens' => 500, 'cost' => 0.0024],
    ))]);

    $result = $this->client->completeJson('documents', messagesTexte(), schemaFacture());

    expect($result->costUsd)->toBe('0.002400');
});

it('gère un coût renvoyé en notation scientifique', function () {
    Http::fake([BASE . '/chat/completions' => Http::response(reponseCompletion(
        usage: ['prompt_tokens' => 10, 'completion_tokens' => 1, 'cost' => 1.0e-5],
    ))]);

    expect($this->client->completeJson('documents', messagesTexte(), schemaFacture())->costUsd)
        ->toBe('0.000010');
});

it('estime le coût en bcmath quand le fournisseur n\'en renvoie pas', function () {
    config(['ai.model_prices' => [
        'google/gemini-3.7-flash' => ['in' => '0.375', 'out' => '1.875'],
    ]]);

    Http::fake([BASE . '/chat/completions' => Http::response(reponseCompletion(
        usage: ['prompt_tokens' => 4000, 'completion_tokens' => 500],
    ))]);

    // 4000 × 0,375/M = 0,0015 ; 500 × 1,875/M = 0,0009375 → 0,0024375
    expect($this->client->completeJson('documents', messagesTexte(), schemaFacture())->costUsd)
        ->toBe('0.002437');
});

it('laisse le coût vide plutôt que d\'inventer un chiffre', function () {
    Http::fake([BASE . '/chat/completions' => Http::response(reponseCompletion(
        usage: ['prompt_tokens' => 4000, 'completion_tokens' => 500],
    ))]);

    $result = $this->client->completeJson('documents', messagesTexte(), schemaFacture());

    expect($result->costUsd)->toBeNull();
});

// =============================================================================
// Fournisseur amont (OpenRouter route la requête vers Google, Bedrock…)
// =============================================================================

it('journalise le fournisseur amont renvoyé par la passerelle', function () {
    Http::fake([BASE . '/chat/completions' => Http::response(reponseCompletion(
        extra: ['provider' => 'Google AI Studio'],
    ))]);

    $result = $this->client->completeJson('documents', messagesTexte(), schemaFacture());

    expect($result->upstreamProvider)->toBe('Google AI Studio')
        ->and(AiUsageLog::withoutGlobalScopes()->first()->meta['upstream_provider'])->toBe('Google AI Studio');
});

it('ne pose pas la clé upstream_provider quand la passerelle n\'en renvoie pas', function () {
    Http::fake([BASE . '/chat/completions' => Http::response(reponseCompletion())]);

    $this->client->completeJson('documents', messagesTexte(), schemaFacture());

    expect(AiUsageLog::withoutGlobalScopes()->first()->meta ?? [])->not->toHaveKey('upstream_provider');
});
