<?php

namespace App\Services\Ai\Providers;

use App\Services\Ai\KeyStatus;
use SensitiveParameter;

/**
 * Protocole natif de Google Gemini (`/models/{modèle}:generateContent`).
 *
 * Deux particularités qui n'existent nulle part ailleurs et qu'il faut avoir en
 * tête : le modèle fait partie de l'URL, et le schéma JSON n'accepte qu'un
 * sous-ensemble d'OpenAPI 3.0 — voir sanitizeSchema().
 */
final class GeminiProvider extends OpenAiProvider
{
    /** Efforts de raisonnement traduits en budget de jetons. `low` = aucun. */
    private const THINKING_BUDGET = ['low' => 0, 'medium' => 4_000, 'high' => 16_000];

    /**
     * Mots-clés qu'un schéma Gemini refuse. `additionalProperties` en fait
     * partie, et le schéma d'extraction de l'application en pose cinq : sans ce
     * nettoyage, CHAQUE analyse de document échouerait en 400.
     */
    private const SCHEMA_REJETES = ['additionalProperties', 'strict', '$schema', '$ref', 'definitions', '$defs'];

    public function supports(Capability $capability): bool
    {
        return match ($capability) {
            Capability::PdfUpload => true,
            // Le schéma passe par generationConfig.responseSchema : un refus est
            // une erreur de configuration, pas une lacune du serveur.
            Capability::StructuredOutput => false,
            Capability::FileParserPlugin,
            Capability::FileAnnotations,
            Capability::DataCollectionPolicy,
            Capability::AttributionHeaders,
            Capability::ReportedCost,
            Capability::KeyIntrospection,
            Capability::OauthKeyHandover => false,
        };
    }

    /**
     * ⚠️ En-tête, JAMAIS `?key=` dans l'URL : une clé en chaîne de requête
     * atterrit dans les journaux d'accès de Google, dans ceux de tout proxy
     * traversé, et dans l'URL que les tests inspectent.
     */
    public function authHeaders(#[SensitiveParameter] string $key): array
    {
        return ['x-goog-api-key' => $key];
    }

    public function completionsPath(string $model, bool $stream): string
    {
        return $stream
            ? "/models/{$model}:streamGenerateContent?alt=sse"
            : "/models/{$model}:generateContent";
    }

    public function normalizeModel(string $slug): string
    {
        foreach (['google/', 'models/'] as $prefixe) {
            if (str_starts_with($slug, $prefixe)) {
                return substr($slug, strlen($prefixe));
            }
        }

        return $slug;
    }

    public function buildPayload(ChatRequest $request): array
    {
        [$system, $contents] = $this->splitSystem($request->messages);

        $payload = ['contents' => $contents];

        if ($system !== '') {
            $payload['systemInstruction'] = ['parts' => [['text' => $system]]];
        }

        $generation = ['temperature' => $request->temperature];

        if ($request->maxTokens !== null) {
            $generation['maxOutputTokens'] = $request->maxTokens;
        }

        if ($request->jsonSchema !== null) {
            $generation['responseMimeType'] = 'application/json';
            $generation['responseSchema'] = self::sanitizeSchema((array) ($request->jsonSchema['schema'] ?? []));
        }

        $effort = strtolower((string) ($request->reasoning['effort'] ?? ''));

        if (array_key_exists($effort, self::THINKING_BUDGET)) {
            // `low` vaut 0 : c'est la mesure qui a fait disparaître les 532
            // jetons de raisonnement facturés d'une question triviale.
            $generation['thinkingConfig'] = ['thinkingBudget' => self::THINKING_BUDGET[$effort]];
        }

        $payload['generationConfig'] = $generation;

        if ($request->tools !== []) {
            $payload['tools'] = [['functionDeclarations' => array_map(
                fn (array $tool): array => array_filter([
                    'name' => (string) ($tool['function']['name'] ?? ''),
                    'description' => (string) ($tool['function']['description'] ?? ''),
                    'parameters' => self::sanitizeSchema((array) ($tool['function']['parameters'] ?? [])),
                ], fn ($valeur) => $valeur !== '' && $valeur !== []),
                $request->tools,
            )]];
        }

        return $payload;
    }

    /**
     * Retire d'un schéma les mots-clés que Gemini refuse.
     *
     * `responseSchema` n'accepte qu'un sous-ensemble d'OpenAPI 3.0. Le schéma
     * d'extraction de l'application pose cinq `additionalProperties`, et
     * AiClient y ajoute `strict` : sans ce nettoyage récursif, l'analyse échoue
     * en 400 avant même d'avoir lu la première facture.
     *
     * @param  array<mixed>  $schema
     * @return array<mixed>
     */
    public static function sanitizeSchema(array $schema): array
    {
        $propre = [];

        foreach ($schema as $cle => $valeur) {
            if (in_array($cle, self::SCHEMA_REJETES, strict: true)) {
                continue;
            }

            $propre[$cle] = is_array($valeur) ? self::sanitizeSchema($valeur) : $valeur;
        }

        return $propre;
    }

    /**
     * @param  array<mixed>  $messages
     * @return array{0: string, 1: array<mixed>}
     */
    private function splitSystem(array $messages): array
    {
        $system = [];
        $contents = [];

        foreach ($messages as $message) {
            $role = (string) ($message['role'] ?? 'user');

            if ($role === 'system') {
                $system[] = $this->flatten($message['content'] ?? '');

                continue;
            }

            if ($role === 'tool') {
                $contents[] = ['role' => 'user', 'parts' => [[
                    'functionResponse' => [
                        'name' => (string) ($message['name'] ?? $message['tool_call_id'] ?? ''),
                        'response' => ['content' => $this->flatten($message['content'] ?? '')],
                    ],
                ]]];

                continue;
            }

            $contents[] = [
                // Gemini ne connaît que `user` et `model`.
                'role' => $role === 'assistant' ? 'model' : 'user',
                'parts' => $this->parts($message),
            ];
        }

        return [trim(implode("\n\n", array_filter($system))), $contents];
    }

    /**
     * @param  array<mixed>  $message
     * @return array<mixed>
     */
    private function parts(array $message): array
    {
        $parts = [];
        $content = $message['content'] ?? '';

        if (is_string($content) && $content !== '') {
            $parts[] = ['text' => $content];
        }

        foreach ((array) (is_array($content) ? $content : []) as $part) {
            if (! is_array($part)) {
                continue;
            }

            $traduit = match ($part['type'] ?? null) {
                'text' => ['text' => (string) ($part['text'] ?? '')],
                'image_url' => $this->inlineData((string) ($part['image_url']['url'] ?? '')),
                'file' => $this->inlineData((string) ($part['file']['file_data'] ?? '')),
                default => null,
            };

            if ($traduit !== null) {
                $parts[] = $traduit;
            }
        }

        foreach ((array) ($message['tool_calls'] ?? []) as $call) {
            $parts[] = ['functionCall' => [
                'name' => (string) ($call['function']['name'] ?? ''),
                'args' => json_decode((string) ($call['function']['arguments'] ?? '{}'), associative: true) ?: [],
            ]];
        }

        return $parts === [] ? [['text' => '']] : $parts;
    }

    /** @return array<mixed>|null */
    private function inlineData(string $dataUrl): ?array
    {
        if (! preg_match('#^data:([^;]+);base64,(.*)$#s', $dataUrl, $matches)) {
            return null;
        }

        return ['inline_data' => ['mime_type' => $matches[1], 'data' => $matches[2]]];
    }

    public function parseResponse(array $body, string $requestedModel): ProviderResponse
    {
        $candidat = (array) ($body['candidates'][0] ?? []);
        $texte = [];
        $toolCalls = [];

        foreach ((array) ($candidat['content']['parts'] ?? []) as $index => $part) {
            if (isset($part['text'])) {
                $texte[] = (string) $part['text'];

                continue;
            }

            if (! isset($part['functionCall'])) {
                continue;
            }

            $toolCalls[] = [
                // Gemini ne donne pas d'identifiant d'appel : on en fabrique un
                // stable, le consommateur en a besoin pour rattacher le résultat.
                'id' => 'call_' . $index,
                'type' => 'function',
                'function' => [
                    'name' => (string) ($part['functionCall']['name'] ?? ''),
                    'arguments' => json_encode($part['functionCall']['args'] ?? [], JSON_UNESCAPED_UNICODE),
                ],
            ];
        }

        $content = implode('', $texte);

        return new ProviderResponse(
            content: $content !== '' ? $content : null,
            model: (string) ($body['modelVersion'] ?? $requestedModel),
            promptTokens: (int) ($body['usageMetadata']['promptTokenCount'] ?? 0),
            completionTokens: (int) ($body['usageMetadata']['candidatesTokenCount'] ?? 0),
            requestId: isset($body['responseId']) ? (string) $body['responseId'] : null,
            finishReason: isset($candidat['finishReason']) ? (string) $candidat['finishReason'] : null,
            message: array_filter([
                'role' => 'assistant',
                'content' => $content !== '' ? $content : null,
                'tool_calls' => $toolCalls !== [] ? $toolCalls : null,
            ], fn ($valeur) => $valeur !== null),
            toolCalls: $toolCalls,
        );
    }

    public function decodeStreamEvent(?string $eventName, array $data, StreamState $state): void
    {
        $state->requestId ??= isset($data['responseId']) ? (string) $data['responseId'] : null;

        if (isset($data['modelVersion'])) {
            $state->model = (string) $data['modelVersion'];
        }

        if (isset($data['usageMetadata'])) {
            $state->usage = [
                'prompt_tokens' => (int) ($data['usageMetadata']['promptTokenCount'] ?? 0),
                'completion_tokens' => (int) ($data['usageMetadata']['candidatesTokenCount'] ?? 0),
            ];
        }

        $candidat = (array) ($data['candidates'][0] ?? []);

        if (filled($candidat['finishReason'] ?? null)) {
            $state->finishReason = (string) $candidat['finishReason'];
        }

        foreach ((array) ($candidat['content']['parts'] ?? []) as $index => $part) {
            if (isset($part['text'])) {
                $state->appendText((string) $part['text']);

                continue;
            }

            if (! isset($part['functionCall'])) {
                continue;
            }

            // Gemini envoie un appel d'outil d'un bloc, jamais en fragments :
            // on le pose entier, arguments compris.
            $state->mergeToolCallDeltas([[
                'index' => $index,
                'id' => 'call_' . $index,
                'function' => [
                    'name' => (string) ($part['functionCall']['name'] ?? ''),
                    'arguments' => json_encode($part['functionCall']['args'] ?? [], JSON_UNESCAPED_UNICODE),
                ],
            ]]);
        }
    }

    public function keyCheckPath(): string
    {
        return '/models';
    }

    public function parseKeyCheck(array $body): KeyStatus
    {
        return new KeyStatus(valid: true, label: 'Clé acceptée par le service Google Gemini.');
    }
}
