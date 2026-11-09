<?php

namespace App\Services\Ai\Providers;

use App\Services\Ai\KeyStatus;
use SensitiveParameter;

/**
 * Protocole OpenAI « nu ».
 *
 * C'est le plus petit dénominateur commun : `POST /chat/completions`, clé en
 * Bearer, messages au format canonique, aucun champ propriétaire. Il sert tel
 * quel aux serveurs auto-hébergés (Ollama, LM Studio, vLLM, llama.cpp) et à
 * l'API d'OpenAI, et il est la classe mère d'OpenRouterProvider.
 */
class OpenAiProvider implements AiProvider
{
    /**
     * @param  array<mixed>  $definition  Entrée de config('ai.providers').
     */
    public function __construct(
        protected readonly string $key,
        protected readonly string $baseUrl,
        protected readonly array $definition = [],
    ) {}

    public function key(): string
    {
        return $this->key;
    }

    public function label(): string
    {
        return (string) ($this->definition['label'] ?? $this->key);
    }

    public function keyLabel(): string
    {
        return (string) ($this->definition['key_label'] ?? '');
    }

    public function baseUrl(): string
    {
        return rtrim($this->baseUrl, '/');
    }

    public function supports(Capability $capability): bool
    {
        return match ($capability) {
            // Un serveur compatible OpenAI reçoit une partie `file` sans savoir
            // la lire : les PDF lui arrivent sous forme de texte extrait.
            Capability::PdfUpload => false,
            Capability::FileParserPlugin => false,
            Capability::FileAnnotations => false,
            Capability::DataCollectionPolicy => false,
            Capability::AttributionHeaders => false,
            Capability::ReportedCost => false,
            Capability::KeyIntrospection => false,
            // Le schéma strict est tenté, et le refus déclenche un repli en
            // `json_object` : c'est exactement le cas d'un serveur local.
            Capability::StructuredOutput => true,
            Capability::OauthKeyHandover => false,
        };
    }

    public function authHeaders(#[SensitiveParameter] string $key): array
    {
        return ['Authorization' => 'Bearer ' . $key];
    }

    public function headers(): array
    {
        return [];
    }

    public function completionsPath(string $model, bool $stream): string
    {
        return '/chat/completions';
    }

    public function buildPayload(ChatRequest $request): array
    {
        $payload = [
            'model' => $request->model,
            'messages' => $request->messages,
            'temperature' => $request->temperature,
        ];

        $payload = $this->decorate($payload, $request);

        if ($request->maxTokens !== null) {
            $payload['max_tokens'] = $request->maxTokens;
        }

        if ($request->reasoning !== null && $request->reasoning !== []) {
            $payload['reasoning'] = $request->reasoning;
        }

        $payload = $this->decorateFiles($payload, $request);

        if ($request->stream) {
            $payload['stream'] = true;
        }

        if ($request->tools !== []) {
            $payload['tools'] = $request->tools;

            if ($request->toolChoice !== null) {
                $payload['tool_choice'] = $request->toolChoice;
            }
        }

        if ($request->jsonSchema !== null) {
            $payload['response_format'] = $request->relaxedJson
                ? ['type' => 'json_object']
                : ['type' => 'json_schema', 'json_schema' => $request->jsonSchema + ['strict' => true]];
        }

        return $payload;
    }

    /**
     * Champs propriétaires insérés juste après `temperature`. Rien ici : c'est
     * le point d'accroche d'OpenRouterProvider.
     *
     * @param  array<mixed>  $payload
     * @return array<mixed>
     */
    protected function decorate(array $payload, ChatRequest $request): array
    {
        return $payload;
    }

    /**
     * Traitement des pièces jointes propre au fournisseur (plugin de lecture,
     * rejeu d'annotations). Rien ici non plus.
     *
     * @param  array<mixed>  $payload
     * @return array<mixed>
     */
    protected function decorateFiles(array $payload, ChatRequest $request): array
    {
        return $payload;
    }

    /**
     * Réduit un contenu canonique — chaîne, ou liste de parties — à du texte
     * simple. Sert aux protocoles qui attendent une consigne système ou un
     * résultat d'outil sous forme de texte.
     */
    protected function flatten(mixed $content): string
    {
        if (is_string($content)) {
            return $content;
        }

        $morceaux = [];

        foreach ((array) $content as $part) {
            if (is_string($part)) {
                $morceaux[] = $part;
            } elseif (is_array($part) && isset($part['text'])) {
                $morceaux[] = (string) $part['text'];
            }
        }

        return implode("\n", $morceaux);
    }

    public function parseResponse(array $body, string $requestedModel): ProviderResponse
    {
        $choice = (array) ($body['choices'][0] ?? []);
        $message = (array) ($choice['message'] ?? []);
        $content = $message['content'] ?? null;

        return new ProviderResponse(
            content: is_string($content) ? $content : null,
            model: (string) ($body['model'] ?? $requestedModel),
            promptTokens: (int) ($body['usage']['prompt_tokens'] ?? 0),
            completionTokens: (int) ($body['usage']['completion_tokens'] ?? 0),
            reportedCost: $this->reportedCost($body),
            requestId: isset($body['id']) ? (string) $body['id'] : null,
            finishReason: isset($choice['finish_reason']) ? (string) $choice['finish_reason'] : null,
            message: $message,
            toolCalls: (array) ($message['tool_calls'] ?? []),
            fileAnnotations: (array) ($message['annotations'] ?? $body['file_annotations'] ?? []),
            // OpenRouter nomme le fournisseur amont qui a servi l'appel ; les
            // services compatibles OpenAI ne renvoient rien ici (null).
            upstreamProvider: isset($body['provider']) && is_string($body['provider']) ? $body['provider'] : null,
        );
    }

    /** @param array<mixed> $body */
    protected function reportedCost(array $body): mixed
    {
        return null;
    }

    public function decodeStreamEvent(?string $eventName, array $data, StreamState $state): void
    {
        $state->requestId ??= isset($data['id']) ? (string) $data['id'] : null;

        if (isset($data['model'])) {
            $state->model = (string) $data['model'];
        }

        if (isset($data['usage']) && is_array($data['usage'])) {
            $state->usage = $data['usage'];
            // Même extraction que hors flux : null ici, `usage.cost` chez
            // OpenRouter, sans dupliquer la connaissance du champ.
            $state->reportedCost = $this->reportedCost(['usage' => $state->usage]);
        }

        $choice = (array) ($data['choices'][0] ?? []);

        if (isset($choice['finish_reason']) && $choice['finish_reason'] !== null) {
            $state->finishReason = (string) $choice['finish_reason'];
        }

        $delta = (array) ($choice['delta'] ?? []);

        if (isset($delta['content']) && is_string($delta['content'])) {
            $state->appendText($delta['content']);
        }

        $state->mergeToolCallDeltas((array) ($delta['tool_calls'] ?? []));
    }

    public function keyCheckPath(): string
    {
        return '/models';
    }

    public function modelsPath(): string
    {
        return '/models';
    }

    public function parseKeyCheck(array $body): KeyStatus
    {
        return new KeyStatus(valid: true, label: "Clé acceptée par le serveur configuré.");
    }

    public function rejectsStructuredOutput(string $lowercasedBody): bool
    {
        foreach (['json_schema', 'response_format', 'structured output'] as $marker) {
            if (str_contains($lowercasedBody, $marker)) {
                return true;
            }
        }

        return false;
    }

    public function defaultModel(string $feature): string
    {
        return (string) ($this->definition['models'][$feature] ?? '');
    }

    public function normalizeModel(string $slug): string
    {
        return $slug;
    }

    public function authorizeUrl(): ?string
    {
        return null;
    }

    public function keyExchangeUrl(): ?string
    {
        return null;
    }

    public function creditsUrl(): ?string
    {
        return null;
    }

    public function keysUrl(): ?string
    {
        $url = $this->definition['key_hint']['url'] ?? null;

        return filled($url) ? (string) $url : null;
    }
}
