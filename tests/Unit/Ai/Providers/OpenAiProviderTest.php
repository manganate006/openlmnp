<?php

use App\Models\User;
use App\Services\Ai\AiClient;
use App\Services\Ai\Providers\AiProviders;
use Illuminate\Support\Facades\Http;

/**
 * Ce qu'un serveur compatible OpenAI reçoit — et surtout ce qu'il ne reçoit
 * plus.
 *
 * Avant l'abstraction, un Ollama recevait `provider.data_collection`, le plugin
 * `file-parser` et les en-têtes d'attribution d'OpenRouter : des champs qu'il
 * ignore, envoyés à un serveur qui tourne chez l'utilisateur. La charge utile
 * rétrécit désormais au strict nécessaire.
 */
const BASE_LOCALE = 'http://ollama.test:11434/v1';

beforeEach(function () {
    config([
        'ai.enabled' => true,
        'ai.api_key' => 'cle-locale',
        'ai.provider' => 'auto',
        'ai.base_url' => BASE_LOCALE,
        'ai.models.documents' => 'qwen3:8b',
        'ai.model_prices' => [],
        'demo.email' => 'demo@openlmnp.fr',
    ]);

    $this->client = AiClient::for(User::factory()->create());
});

function reponseLocale(): array
{
    return [
        'id' => 'chatcmpl-1',
        'model' => 'qwen3:8b',
        'choices' => [['message' => ['role' => 'assistant', 'content' => '{"montant":"12.00"}'], 'finish_reason' => 'stop']],
        'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 20],
    ];
}

it('n\'envoie aucun champ propriétaire à un serveur compatible OpenAI', function () {
    Http::fake([BASE_LOCALE . '/chat/completions' => Http::response(reponseLocale())]);

    $this->client->completeJson(
        'documents',
        [['role' => 'user', 'content' => [AiClient::filePart('facture.pdf', 'JVBERi0K')]]],
        ['name' => 'facture', 'schema' => ['type' => 'object']],
        null,
        ['file_annotations' => [['type' => 'file', 'file' => ['hash' => 'abc']]]],
    );

    Http::assertSent(function ($request) {
        $corps = $request->data();

        return ! array_key_exists('provider', $corps)
            && ! array_key_exists('plugins', $corps)
            // Les annotations n'ont de sens que là où elles évitent une
            // refacturation : ailleurs, elles n'ajouteraient qu'un message.
            && count($corps['messages']) === 1
            && $corps['model'] === 'qwen3:8b';
    });
});

it('n\'envoie pas les en-têtes d\'attribution d\'OpenRouter à un serveur local', function () {
    Http::fake([BASE_LOCALE . '/chat/completions' => Http::response(reponseLocale())]);

    $this->client->completeJson('documents', [['role' => 'user', 'content' => 'Bonjour']], ['name' => 'x', 'schema' => []]);

    Http::assertSent(fn ($request) => ! $request->hasHeader('HTTP-Referer')
        && ! $request->hasHeader('X-Title')
        && $request->hasHeader('Authorization', 'Bearer cle-locale'));
});

it('ne journalise aucun coût quand le serveur n\'en renvoie pas', function () {
    // Mieux vaut un coût vide qu'un chiffre inventé : c'est ce que verra
    // l'écran d'administration tant qu'aucun tarif n'est renseigné.
    Http::fake([BASE_LOCALE . '/chat/completions' => Http::response(reponseLocale())]);

    $result = $this->client->completeJson('documents', [['role' => 'user', 'content' => 'Bonjour']], ['name' => 'x', 'schema' => []]);

    expect($result->costUsd)->toBeNull()
        ->and($result->promptTokens)->toBe(100);
});

it('vérifie la clé sur la liste des modèles, faute de route d\'introspection', function () {
    Http::fake([BASE_LOCALE . '/models' => Http::response(['data' => []])]);

    $status = $this->client->checkKey();

    expect($status->valid)->toBeTrue()
        // Rien à dire de plus : ce serveur n'expose ni libellé ni crédit.
        ->and($status->limitRemaining)->toBeNull()
        ->and($status->summary())->toBe('Clé acceptée par le serveur configuré.');
});

it('estime le coût depuis les tarifs quand l\'administrateur les a renseignés', function () {
    config(['ai.model_prices' => ['qwen3:8b' => ['in' => '0.300', 'out' => '1.500']]]);
    Http::fake([BASE_LOCALE . '/chat/completions' => Http::response(reponseLocale())]);

    $result = $this->client->completeJson('documents', [['role' => 'user', 'content' => 'Bonjour']], ['name' => 'x', 'schema' => []]);

    // 100 jetons à 0,300 $/M + 20 jetons à 1,500 $/M = 0,00006 $.
    expect($result->costUsd)->toBe('0.000060');
});

it('résout le pilote générique pour tout hôte non déclaré', function () {
    expect(AiProviders::resolveKey())->toBe('local');
});
