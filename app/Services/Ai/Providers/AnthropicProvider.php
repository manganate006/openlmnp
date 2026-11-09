<?php

namespace App\Services\Ai\Providers;

use App\Services\Ai\KeyStatus;
use SensitiveParameter;

/**
 * Protocole natif d'Anthropic (`/messages`).
 *
 * Le format canonique de l'application reste celui d'OpenAI : ce pilote traduit
 * aux deux frontières, aller et retour. Aucun consommateur n'a à savoir quel
 * fournisseur est configuré.
 *
 * Ce qu'on gagne sur la couche de compatibilité OpenAI : les PDF partent tels
 * quels, sans passer par l'extraction de leur couche texte.
 */
final class AnthropicProvider extends OpenAiProvider
{
    /** Version d'API exigée en en-tête. Constante : rien à régler pour l'exploitant. */
    private const API_VERSION = '2023-06-01';

    /** `max_tokens` est obligatoire chez Anthropic. Filet, jamais atteint en pratique. */
    private const DEFAULT_MAX_TOKENS = 4_096;

    /** Nom de l'outil qui porte le schéma d'extraction. */
    private const SCHEMA_TOOL = 'extraction';

    /** Budgets de raisonnement, en jetons, pour les efforts qui en demandent. */
    private const THINKING_BUDGET = ['medium' => 4_000, 'high' => 16_000];

    public function supports(Capability $capability): bool
    {
        return match ($capability) {
            // Anthropic lit un PDF nativement : pas de dégradation en texte.
            Capability::PdfUpload => true,
            // Le schéma passe par un outil forcé : un 400 serait une erreur de
            // configuration, pas une lacune — relancer un appel payant ne
            // réparerait rien.
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

    public function authHeaders(#[SensitiveParameter] string $key): array
    {
        return ['x-api-key' => $key];
    }

    public function headers(): array
    {
        return ['anthropic-version' => self::API_VERSION];
    }

    public function completionsPath(string $model, bool $stream): string
    {
        return '/messages';
    }

    public function normalizeModel(string $slug): string
    {
        // Retire un préfixe qui désigne CE fournisseur (habitude prise chez les
        // intermédiaires). Jamais de traduction depuis un autre éditeur.
        return str_starts_with($slug, 'anthropic/') ? substr($slug, strlen('anthropic/')) : $slug;
    }

    public function buildPayload(ChatRequest $request): array
    {
        [$system, $messages] = $this->splitSystem($request->messages);

        $payload = [
            'model' => $request->model,
            'messages' => $messages,
            'max_tokens' => $request->maxTokens ?? self::DEFAULT_MAX_TOKENS,
            'temperature' => $request->temperature,
        ];

        if ($system !== '') {
            $payload['system'] = $system;
        }

        if ($request->stream) {
            $payload['stream'] = true;
        }

        // Sortie contrainte : Anthropic n'a pas de `response_format`, le schéma
        // se sert par un outil dont l'usage est imposé.
        if ($request->jsonSchema !== null) {
            $payload['tools'] = [[
                'name' => self::SCHEMA_TOOL,
                'description' => 'Renvoie les données extraites du document.',
                'input_schema' => $request->jsonSchema['schema'] ?? ['type' => 'object'],
            ]];
            $payload['tool_choice'] = ['type' => 'tool', 'name' => self::SCHEMA_TOOL];
        } elseif ($request->tools !== []) {
            $payload['tools'] = array_map(
                fn (array $tool): array => [
                    'name' => (string) ($tool['function']['name'] ?? ''),
                    'description' => (string) ($tool['function']['description'] ?? ''),
                    'input_schema' => (array) ($tool['function']['parameters'] ?? ['type' => 'object']),
                ],
                $request->tools,
            );

            if ($choice = $this->toolChoice($request->toolChoice)) {
                $payload['tool_choice'] = $choice;
            }
        }

        if ($budget = self::THINKING_BUDGET[strtolower((string) ($request->reasoning['effort'] ?? ''))] ?? null) {
            $payload['thinking'] = ['type' => 'enabled', 'budget_tokens' => $budget];
        }

        return $payload;
    }

    /**
     * Extrait les consignes système et traduit les messages restants.
     *
     * @param  array<mixed>  $messages
     * @return array{0: string, 1: array<mixed>}
     */
    private function splitSystem(array $messages): array
    {
        $system = [];
        $traduits = [];

        foreach ($messages as $message) {
            $role = (string) ($message['role'] ?? 'user');

            if ($role === 'system') {
                $system[] = $this->flatten($message['content'] ?? '');

                continue;
            }

            // Un résultat d'outil se rattache à un message UTILISATEUR chez
            // Anthropic, là où OpenAI lui donne un rôle dédié.
            if ($role === 'tool') {
                $traduits[] = ['role' => 'user', 'content' => [[
                    'type' => 'tool_result',
                    'tool_use_id' => (string) ($message['tool_call_id'] ?? ''),
                    'content' => $this->flatten($message['content'] ?? ''),
                ]]];

                continue;
            }

            $traduits[] = ['role' => $role, 'content' => $this->contentBlocks($message)];
        }

        return [trim(implode("\n\n", array_filter($system))), $traduits];
    }

    /**
     * Blocs de contenu d'un message, dans le dialecte d'Anthropic.
     *
     * @param  array<mixed>  $message
     * @return array<mixed>|string
     */
    private function contentBlocks(array $message): array|string
    {
        $blocs = [];

        foreach ((array) ($message['content'] ?? []) as $part) {
            if (! is_array($part)) {
                continue;
            }

            $blocs[] = match ($part['type'] ?? null) {
                'text' => ['type' => 'text', 'text' => (string) ($part['text'] ?? '')],
                'image_url' => $this->sourceBlock('image', (string) ($part['image_url']['url'] ?? '')),
                'file' => $this->sourceBlock('document', (string) ($part['file']['file_data'] ?? '')),
                default => null,
            };
        }

        $blocs = array_values(array_filter($blocs));

        // Appels d'outils demandés au tour précédent, réinjectés par le
        // consommateur avec le message assistant tel qu'il l'a reçu.
        foreach ((array) ($message['tool_calls'] ?? []) as $call) {
            $blocs[] = [
                'type' => 'tool_use',
                'id' => (string) ($call['id'] ?? ''),
                'name' => (string) ($call['function']['name'] ?? ''),
                'input' => json_decode((string) ($call['function']['arguments'] ?? '{}'), associative: true) ?: [],
            ];
        }

        if ($blocs !== []) {
            return $blocs;
        }

        return is_string($message['content'] ?? null) ? $message['content'] : '';
    }

    /**
     * Traduit une data URL en bloc `source` base64.
     *
     * @return array<mixed>|null
     */
    private function sourceBlock(string $type, string $dataUrl): ?array
    {
        if (! preg_match('#^data:([^;]+);base64,(.*)$#s', $dataUrl, $matches)) {
            return null;
        }

        return [
            'type' => $type,
            'source' => ['type' => 'base64', 'media_type' => $matches[1], 'data' => $matches[2]],
        ];
    }

    /** @return array<mixed>|null */
    private function toolChoice(mixed $choice): ?array
    {
        if (is_string($choice)) {
            return match ($choice) {
                'required' => ['type' => 'any'],
                'auto' => ['type' => 'auto'],
                'none' => ['type' => 'none'],
                default => null,
            };
        }

        if (is_array($choice) && filled($choice['function']['name'] ?? null)) {
            return ['type' => 'tool', 'name' => (string) $choice['function']['name']];
        }

        return null;
    }

    public function parseResponse(array $body, string $requestedModel): ProviderResponse
    {
        $texte = [];
        $toolCalls = [];
        $extraction = null;

        foreach ((array) ($body['content'] ?? []) as $bloc) {
            if (($bloc['type'] ?? null) === 'text') {
                $texte[] = (string) ($bloc['text'] ?? '');

                continue;
            }

            if (($bloc['type'] ?? null) !== 'tool_use') {
                continue;
            }

            // ⚠️ Le JSON d'extraction arrive dans l'ENTRÉE d'un outil, jamais en
            // texte. Sans cette normalisation, AiClient jugerait la réponse
            // vide et déclencherait une passe de réparation — facturée — sur
            // chaque document analysé.
            if (($bloc['name'] ?? null) === self::SCHEMA_TOOL) {
                $extraction = json_encode($bloc['input'] ?? [], JSON_UNESCAPED_UNICODE);

                continue;
            }

            $toolCalls[] = [
                'id' => (string) ($bloc['id'] ?? ''),
                'type' => 'function',
                'function' => [
                    'name' => (string) ($bloc['name'] ?? ''),
                    'arguments' => json_encode($bloc['input'] ?? [], JSON_UNESCAPED_UNICODE),
                ],
            ];
        }

        $content = $extraction ?? implode('', $texte);

        return new ProviderResponse(
            content: $content !== '' ? $content : null,
            model: (string) ($body['model'] ?? $requestedModel),
            promptTokens: (int) ($body['usage']['input_tokens'] ?? 0),
            completionTokens: (int) ($body['usage']['output_tokens'] ?? 0),
            requestId: isset($body['id']) ? (string) $body['id'] : null,
            finishReason: isset($body['stop_reason']) ? (string) $body['stop_reason'] : null,
            // Message assistant rendu au format canonique : c'est lui que le
            // consommateur réinjecte dans la conversation.
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
        $type = $eventName ?? (string) ($data['type'] ?? '');
        $index = (int) ($data['index'] ?? 0);

        match ($type) {
            'message_start' => $this->startMessage($data, $state),
            'content_block_start' => $this->startBlock($data, $index, $state),
            'content_block_delta' => $this->applyDelta($data, $index, $state),
            'message_delta' => $this->finishMessage($data, $state),
            // Anthropic termine par un événement, jamais par « data: [DONE] ».
            'message_stop' => $state->done = true,
            default => null,
        };
    }

    /** @param array<mixed> $data */
    private function startMessage(array $data, StreamState $state): void
    {
        $message = (array) ($data['message'] ?? []);

        $state->requestId ??= isset($message['id']) ? (string) $message['id'] : null;

        if (isset($message['model'])) {
            $state->model = (string) $message['model'];
        }

        $state->usage['prompt_tokens'] = (int) ($message['usage']['input_tokens'] ?? 0);
    }

    /** @param array<mixed> $data */
    private function startBlock(array $data, int $index, StreamState $state): void
    {
        $bloc = (array) ($data['content_block'] ?? []);

        if (($bloc['type'] ?? null) !== 'tool_use') {
            return;
        }

        $state->mergeToolCallDeltas([[
            'index' => $index,
            'id' => (string) ($bloc['id'] ?? ''),
            'function' => ['name' => (string) ($bloc['name'] ?? '')],
        ]]);
    }

    /** @param array<mixed> $data */
    private function applyDelta(array $data, int $index, StreamState $state): void
    {
        $delta = (array) ($data['delta'] ?? []);

        match ($delta['type'] ?? null) {
            'text_delta' => $state->appendText((string) ($delta['text'] ?? '')),
            'input_json_delta' => $state->mergeToolCallDeltas([[
                'index' => $index,
                'function' => ['arguments' => (string) ($delta['partial_json'] ?? '')],
            ]]),
            default => null,
        };
    }

    /** @param array<mixed> $data */
    private function finishMessage(array $data, StreamState $state): void
    {
        if (isset($data['delta']['stop_reason'])) {
            $state->finishReason = (string) $data['delta']['stop_reason'];
        }

        if (isset($data['usage']['output_tokens'])) {
            $state->usage['completion_tokens'] = (int) $data['usage']['output_tokens'];
        }
    }

    public function keyCheckPath(): string
    {
        return '/models';
    }

    public function parseKeyCheck(array $body): KeyStatus
    {
        return new KeyStatus(valid: true, label: 'Clé acceptée par le service Anthropic.');
    }
}
