<?php

namespace App\Services\Ai\Providers;

use App\Services\Ai\KeyStatus;
use SensitiveParameter;

/**
 * Ce qu'un fournisseur d'IA doit savoir dire pour être utilisable.
 *
 * ⚠️ Un pilote NE FAIT AUCUN APPEL HTTP : il expose des fonctions pures sur des
 * tableaux. Les invariants de confidentialité (jamais `Http::throw()`, jamais le
 * corps du fournisseur relayé, jamais la clé journalisée) restent ainsi tenus
 * par un seul fichier, AiClient — au lieu d'être à retenir dans chaque pilote.
 */
interface AiProvider
{
    /** Identifiant technique : openrouter | openai | anthropic | gemini | local. */
    public function key(): string;

    /** Nom affiché : « OpenRouter », « Google Gemini », « Serveur compatible OpenAI ». */
    public function label(): string;

    /**
     * Groupe nominal qui suit « clé » à l'écran (« clé OpenRouter »). Vide quand
     * le fournisseur n'a pas de nom à donner : on dit alors « clé d'API ».
     */
    public function keyLabel(): string;

    /** Point d'entrée effectif, sans barre oblique finale. */
    public function baseUrl(): string;

    public function supports(Capability $capability): bool;

    /**
     * Porte-clé : `Authorization: Bearer`, `x-api-key`, `x-goog-api-key`…
     *
     * @return array<string, string>
     */
    public function authHeaders(#[SensitiveParameter] string $key): array;

    /**
     * En-têtes non secrets propres au fournisseur (attribution, version d'API).
     *
     * @return array<string, string>
     */
    public function headers(): array;

    /** Chemin de complétion, relatif à baseUrl() (le modèle entre dans l'URL chez certains). */
    public function completionsPath(string $model, bool $stream): string;

    /**
     * Corps de requête complet, dans le dialecte du fournisseur.
     *
     * @return array<mixed>
     */
    public function buildPayload(ChatRequest $request): array;

    /** @param array<mixed> $body */
    public function parseResponse(array $body, string $requestedModel): ProviderResponse;

    /**
     * Un événement SSE décodé met à jour l'état d'accumulation.
     *
     * @param  string|null   $eventName  Ligne `event:` précédente (Anthropic la nomme).
     * @param  array<mixed>  $data       Charge utile JSON de la ligne `data:`.
     */
    public function decodeStreamEvent(?string $eventName, array $data, StreamState $state): void;

    /** Route de vérification de clé, relative à baseUrl(). */
    public function keyCheckPath(): string;

    /** Route de repli quand la précédente n'existe pas (404/405). */
    public function modelsPath(): string;

    /** @param array<mixed> $body */
    public function parseKeyCheck(array $body): KeyStatus;

    /** Un 400 vient-il du refus de la sortie structurée ? (sinon : pas de second appel payant) */
    public function rejectsStructuredOutput(string $lowercasedBody): bool;

    /**
     * Slug par défaut pour un rôle : documents | documents_fallback | chat.
     * Vide = aucun défaut (serveur local : cela dépend de ce qui y est installé).
     */
    public function defaultModel(string $feature): string;

    /**
     * Corrige le préfixe éditeur quand il correspond au fournisseur visé.
     *
     * Correction minimale et jamais de traduction inter-éditeurs : un slug Google
     * envoyé à Anthropic doit échouer en 404, pas être « deviné ». Deviner
     * enverrait les documents d'un utilisateur à un modèle qu'il n'a pas choisi.
     */
    public function normalizeModel(string $slug): string;

    // =========================================================================
    // Remise de clé et crédits (voir Capability::OauthKeyHandover)
    // =========================================================================

    /** URL d'autorisation OAuth PKCE, ou null si le fournisseur n'en propose pas. */
    public function authorizeUrl(): ?string;

    /** URL d'échange du code contre une clé, ou null. */
    public function keyExchangeUrl(): ?string;

    /** Page de recharge de crédits, ou null. */
    public function creditsUrl(): ?string;

    /** Page de création de clé à la main, ou null. */
    public function keysUrl(): ?string;
}
