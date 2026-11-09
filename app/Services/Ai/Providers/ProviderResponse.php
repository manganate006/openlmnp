<?php

namespace App\Services\Ai\Providers;

/**
 * Une réponse de complétion non diffusée, ramenée à la forme canonique.
 *
 * Le pilote décode le dialecte du fournisseur ; AiClient garde ce qui doit
 * rester en un seul endroit : le nettoyage de l'enveloppe Markdown, le calcul
 * du coût en bcmath et la journalisation.
 */
final class ProviderResponse
{
    /**
     * @param  string|null   $content          Texte brut de la réponse (null = rien d'exploitable).
     * @param  mixed         $reportedCost     Coût rendu par le fournisseur, brut ; null s'il n'en rend pas.
     * @param  array<mixed>  $message          Message assistant à réinjecter dans la conversation.
     * @param  array<mixed>  $toolCalls        Appels d'outils au format OpenAI.
     * @param  array<mixed>  $fileAnnotations  Parsing déjà facturé, rejouable.
     * @param  string|null   $upstreamProvider Fournisseur AMONT qui a réellement servi l'appel
     *                                         (champ `provider` d'OpenRouter) : rend le routage
     *                                         observable au lieu d'être seulement déclaré.
     */
    public function __construct(
        public readonly ?string $content,
        public readonly string $model,
        public readonly int $promptTokens = 0,
        public readonly int $completionTokens = 0,
        public readonly mixed $reportedCost = null,
        public readonly ?string $requestId = null,
        public readonly ?string $finishReason = null,
        public readonly array $message = [],
        public readonly array $toolCalls = [],
        public readonly array $fileAnnotations = [],
        public readonly ?string $upstreamProvider = null,
    ) {}
}
