<?php

namespace App\Services\Ai\Providers;

/**
 * Une demande de complétion, sous forme CANONIQUE.
 *
 * Le format canonique de l'application est celui d'OpenAI : c'est déjà celui que
 * produisent DocumentAnalysisService, AssistantService, ToolBridge et les jeux
 * d'évaluation. Les pilotes non-OpenAI traduisent aux frontières ; aucun
 * consommateur n'a à savoir quel fournisseur est configuré.
 */
final class ChatRequest
{
    /**
     * @param  string       $feature     documents | documents_auto | chat | chat_tool
     * @param  array<mixed> $messages    Messages au format OpenAI.
     * @param  array<mixed>|null $reasoning  Budget de raisonnement au format OpenRouter
     *                                        (`['effort' => 'low']`), déjà résolu par
     *                                        AiClient ; null = ne rien demander.
     * @param  array<mixed> $tools       Définitions d'outils au format OpenAI.
     * @param  array<mixed>|null $jsonSchema  Corps de `json_schema` (name + schema).
     * @param  array<mixed> $fileAnnotations  Annotations d'un parsing déjà facturé.
     */
    public function __construct(
        public readonly string $feature,
        public readonly string $model,
        public readonly array $messages,
        public readonly float $temperature = 0.0,
        public readonly ?int $maxTokens = null,
        public readonly ?array $reasoning = null,
        public readonly bool $stream = false,
        public readonly array $tools = [],
        public readonly mixed $toolChoice = null,
        public readonly ?array $jsonSchema = null,
        public readonly bool $relaxedJson = false,
        public readonly array $fileAnnotations = [],
    ) {}

    /** Copie avec un schéma relâché, pour le repli d'un serveur qui l'a refusé. */
    public function withRelaxedJson(): self
    {
        return new self(
            feature: $this->feature,
            model: $this->model,
            messages: $this->messages,
            temperature: $this->temperature,
            maxTokens: $this->maxTokens,
            reasoning: $this->reasoning,
            stream: $this->stream,
            tools: $this->tools,
            toolChoice: $this->toolChoice,
            jsonSchema: $this->jsonSchema,
            relaxedJson: true,
            fileAnnotations: $this->fileAnnotations,
        );
    }

    /** Un PDF est-il joint ? (déclenche le plugin de lecture chez qui en a un) */
    public function hasFilePart(): bool
    {
        foreach ($this->messages as $message) {
            foreach ((array) ($message['content'] ?? []) as $part) {
                if (is_array($part) && ($part['type'] ?? null) === 'file') {
                    return true;
                }
            }
        }

        return false;
    }
}
