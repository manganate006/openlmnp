<?php

namespace App\Services\Ai;

/**
 * Résultat d'un appel au fournisseur d'IA.
 *
 * Objet simple et sérialisable : il peut être stocké tel quel dans l'état d'un
 * composant Livewire, et le jour où l'analyse passera en file d'attente, rien
 * ne changera pour les appelants.
 */
final class AiResult
{
    /**
     * @param  string|array<mixed>  $content       Texte, ou tableau décodé pour completeJson().
     * @param  array<mixed>         $message       Message assistant brut (à renvoyer tel quel dans la boucle d'outils).
     * @param  array<mixed>         $toolCalls     Appels d'outils demandés par le modèle (le consommateur boucle).
     * @param  array<mixed>         $fileAnnotations Résultat du parsing de fichier, à renvoyer pour ne pas re-parser ni repayer.
     */
    public function __construct(
        public readonly string|array $content,
        public readonly string $model,
        public readonly int $promptTokens = 0,
        public readonly int $completionTokens = 0,
        public readonly ?string $costUsd = null,
        public readonly int $durationMs = 0,
        public readonly ?string $providerRequestId = null,
        public readonly ?string $finishReason = null,
        public readonly array $message = [],
        public readonly array $toolCalls = [],
        public readonly array $fileAnnotations = [],
        public readonly ?string $upstreamProvider = null,
    ) {}

    public function hasToolCalls(): bool
    {
        return $this->toolCalls !== [];
    }

    /** Contenu textuel, quelle que soit la forme renvoyée. */
    public function text(): string
    {
        return is_string($this->content)
            ? $this->content
            : (string) json_encode($this->content, JSON_UNESCAPED_UNICODE);
    }

    /** Contenu décodé, pour completeJson(). */
    public function json(): array
    {
        return is_array($this->content) ? $this->content : [];
    }

    public function totalTokens(): int
    {
        return $this->promptTokens + $this->completionTokens;
    }
}
