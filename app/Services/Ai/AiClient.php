<?php

namespace App\Services\Ai;

use App\Models\AiUsageLog;
use App\Models\User;
use App\Services\Ai\Exceptions\AiBadResponse;
use App\Services\Ai\Exceptions\AiException;
use App\Services\Ai\Exceptions\AiInsufficientCredits;
use App\Services\Ai\Exceptions\AiKeyInvalid;
use App\Services\Ai\Exceptions\AiModelUnavailable;
use App\Services\Ai\Exceptions\AiRateLimited;
use App\Services\Ai\Exceptions\AiTimeout;
use App\Services\Ai\Exceptions\AiUnavailable;
use App\Services\Ai\Providers\AiProvider;
use App\Services\Ai\Providers\Capability;
use App\Services\Ai\Providers\ChatRequest;
use App\Services\Ai\Providers\StreamState;
use App\Support\AiAccess;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Throwable;

/**
 * Client UNIQUE vers l'API d'IA.
 *
 * Toutes les fonctionnalités d'IA passent par ici : un seul endroit qui porte
 * la clé, un seul endroit qui journalise, un seul endroit à auditer.
 *
 * Ce qui est PROPRE À UN FOURNISSEUR — forme de l'URL, des en-têtes, du corps,
 * des événements diffusés — appartient au pilote (App\Services\Ai\Providers).
 * Ce qui doit rester en un seul exemplaire reste ici : la résolution de l'accès,
 * la politique de nouvelle tentative, la traduction d'un statut HTTP en
 * exception typée, le nettoyage de l'enveloppe Markdown, le calcul du coût et
 * la journalisation.
 *
 * Confidentialité — invariants tenus par cette classe :
 *   - la clé ne quitte jamais l'en-tête d'autorisation (jamais dans un log, une
 *     exception, un message utilisateur ni dans ai_usage_logs) ;
 *   - `Http::throw()` n'est JAMAIS utilisé : son RequestException embarquerait
 *     le corps de la réponse du fournisseur. On lit le statut et on lève nos
 *     propres exceptions à message fixe ;
 *   - `meta` du journal ne reçoit que des compteurs et des types, jamais de
 *     contenu.
 *
 * ⚠️ Les quotas ne sont PAS appliqués ici : chaque fonctionnalité appelle
 * App\Support\AiQuota::check() avant de solliciter le client.
 */
final class AiClient
{
    /** Nombre de nouvelles tentatives sur 429 / 5xx / délai dépassé. */
    private const MAX_RETRIES = 1;

    /** Pause (s) avant la nouvelle tentative, à défaut d'en-tête Retry-After. */
    private const DEFAULT_RETRY_DELAY_SECONDS = 1;

    /** Plafond (s) appliqué à Retry-After : la requête de l'utilisateur attend. */
    private const MAX_RETRY_DELAY_SECONDS = 5;

    public function __construct(private readonly AiAccess $access) {}

    public static function for(User $user): self
    {
        return new self(AiAccess::for($user));
    }

    public function access(): AiAccess
    {
        return $this->access;
    }

    /** Pilote du fournisseur configuré sur cette instance. */
    private function provider(): AiProvider
    {
        return $this->access->provider();
    }

    // =========================================================================
    // Appels
    // =========================================================================

    /**
     * Complétion JSON contrainte par un schéma (extraction de documents).
     *
     * Si le serveur rejette `json_schema` (serveur local compatible OpenAI qui
     * ne le gère pas), on retente automatiquement en `json_object`.
     *
     * @param  string        $feature     documents | chat | chat_tool
     * @param  array<mixed>  $messages    Messages au format OpenAI.
     * @param  array<mixed>  $jsonSchema  Corps de `json_schema` (name + schema).
     * @param  array<mixed>  $options     max_tokens, temperature, meta, file_annotations
     *                                    (annotations d'un parsing précédent, rejouées par le
     *                                    pilote chez les fournisseurs qui savent les relire)
     *
     * @throws AiException
     */
    public function completeJson(
        string $feature,
        array $messages,
        array $jsonSchema,
        ?string $model = null,
        array $options = [],
    ): AiResult {
        $model = $model ?: $this->defaultModelFor($feature);
        $meta = (array) ($options['meta'] ?? []);
        $started = microtime(true);

        try {
            // Dans le try : un accès indisponible ou un modèle non configuré
            // doivent aussi laisser une ligne de journal.
            $request = $this->chatRequest($feature, $messages, $model, $options, jsonSchema: $jsonSchema);

            try {
                $response = $this->sendWithRetry($request, $meta);
            } catch (AiBadResponse $e) {
                // Repli UNIQUEMENT si le serveur a explicitement refusé le
                // schéma : un contenu mal formé ne doit pas relancer un appel
                // payant. Et seulement chez un fournisseur où un schéma relâché
                // a un sens : là où le schéma passe par un autre mécanisme
                // (outil forcé, responseSchema), un 400 est une erreur de
                // configuration que ce second appel ne réparerait pas.
                if (! $e->schemaRejected || ! $this->provider()->supports(Capability::StructuredOutput)) {
                    throw $e;
                }

                $meta['response_format'] = 'json_object';
                $response = $this->sendWithRetry($request->withRelaxedJson(), $meta);
            }

            $result = $this->buildResult($response, $model, $started, decodeJson: true);
        } catch (AiException $e) {
            $this->journal($feature, $model ?: null, 'error', $started, meta: $meta, errorCode: $e->code());

            throw $e;
        }

        $this->journal($feature, $result->model, 'ok', $started, meta: $meta, result: $result);

        return $result;
    }

    /**
     * Complétion texte en flux (assistant), avec outils facultatifs.
     *
     * La BOUCLE d'appel d'outils appartient au consommateur : ce client renvoie
     * les `tool_calls` demandés par le modèle et le message assistant brut à
     * réinjecter dans la conversation.
     *
     * @param  array<mixed>   $messages
     * @param  array<mixed>   $tools     Définitions d'outils au format OpenAI.
     * @param  callable|null  $onDelta   Reçoit chaque fragment de texte.
     * @param  array<mixed>   $options   max_tokens, temperature, meta, tool_choice
     *
     * @throws AiException
     */
    public function stream(
        string $feature,
        array $messages,
        array $tools = [],
        ?callable $onDelta = null,
        ?string $model = null,
        array $options = [],
    ): AiResult {
        $model = $model ?: $this->defaultModelFor($feature);
        $meta = (array) ($options['meta'] ?? []);
        $started = microtime(true);

        try {
            $request = $this->chatRequest($feature, $messages, $model, $options, stream: true, tools: $tools);

            $response = $this->sendWithRetry($request, $meta);
            $result = $this->consumeStream($response, $model, $started, $onDelta);
        } catch (AiException $e) {
            $this->journal($feature, $model ?: null, 'error', $started, meta: $meta, errorCode: $e->code());

            throw $e;
        }

        $this->journal($feature, $result->model, 'ok', $started, meta: $meta, result: $result);

        return $result;
    }

    /**
     * Vérification de la clé (bouton « Vérifier la clé » de Mon profil).
     *
     * OpenRouter expose GET /auth/key (libellé, usage, crédit restant). Sur un
     * serveur qui ne connaît pas cette route, on se rabat sur GET /models : si
     * la clé est acceptée, elle est valide.
     */
    public function checkKey(): KeyStatus
    {
        $started = microtime(true);

        if (! $this->access->available()) {
            $this->journal('key_check', null, 'error', $started, errorCode: 'AiUnavailable');

            return KeyStatus::invalid('AiUnavailable', $this->access->unavailableMessage()
                ?? "Aucune clé d'intelligence artificielle n'est configurée.");
        }

        $provider = $this->provider();

        try {
            $response = $this->request()->get($provider->baseUrl() . $provider->keyCheckPath());

            // La route d'introspection n'existe pas partout : on se rabat sur
            // la liste des modèles, que tout serveur compatible expose.
            if ($provider->modelsPath() !== $provider->keyCheckPath()
                && in_array($response->status(), [404, 405], strict: true)) {
                $response = $this->request()->get($provider->baseUrl() . $provider->modelsPath());

                if ($response->successful()) {
                    $this->journal('key_check', null, 'ok', $started);

                    return new KeyStatus(valid: true, label: "Clé acceptée par le serveur configuré.");
                }
            }

            if (! $response->successful()) {
                $exception = $this->exceptionFor($response->status(), $response);
                $this->journal('key_check', null, 'error', $started, errorCode: $exception->code());

                return KeyStatus::invalid($exception->code(), $exception->getMessage());
            }

            $status = $provider->parseKeyCheck((array) $response->json());
        } catch (ConnectionException) {
            $exception = new AiTimeout;
            $this->journal('key_check', null, 'error', $started, errorCode: $exception->code());

            return KeyStatus::invalid($exception->code(), $exception->getMessage());
        }

        $this->journal('key_check', null, 'ok', $started);

        return $status;
    }

    // =========================================================================
    // Fabriques de parties de message (entrées binaires)
    // =========================================================================

    /** Partie « image » d'un message multimodal (data URL base64). */
    public static function imagePart(string $mimeType, string $base64): array
    {
        return [
            'type' => 'image_url',
            'image_url' => ['url' => "data:{$mimeType};base64,{$base64}"],
        ];
    }

    /**
     * Partie « fichier » (PDF) d'un message multimodal.
     *
     * Déclenche l'ajout du plugin OpenRouter `file-parser` avec un moteur
     * TOUJOURS explicite (AI_PDF_ENGINE) : sans moteur, OpenRouter bascule sur
     * `mistral-ocr`, facturé 2 $ / 1 000 pages.
     */
    public static function filePart(string $filename, string $base64, string $mimeType = 'application/pdf'): array
    {
        return [
            'type' => 'file',
            'file' => [
                'filename' => $filename,
                'file_data' => "data:{$mimeType};base64,{$base64}",
            ],
        ];
    }

    // =========================================================================
    // Interne
    // =========================================================================

    private function defaultModelFor(string $feature): string
    {
        return $this->access->model(
            in_array($feature, ['chat', 'chat_tool'], strict: true) ? 'chat' : 'documents'
        );
    }

    /**
     * Demande de complétion sous forme canonique, commune à tous les appels.
     *
     * C'est ici que sont refusés un accès indisponible et un modèle non
     * configuré : le pilote ne reçoit que des requêtes exploitables.
     *
     * @param  array<mixed>  $messages
     * @param  array<mixed>  $options
     * @param  array<mixed>  $tools
     * @param  array<mixed>|null  $jsonSchema
     */
    private function chatRequest(
        string $feature,
        array $messages,
        string $model,
        array $options,
        bool $stream = false,
        array $tools = [],
        ?array $jsonSchema = null,
    ): ChatRequest {
        if (! $this->access->available()) {
            throw new AiUnavailable($this->access->unavailableMessage()
                ?? "Les fonctions d'intelligence artificielle ne sont pas disponibles pour ce compte.");
        }

        if ($model === '') {
            throw new AiModelUnavailable("Aucun modèle d'intelligence artificielle n'est configuré pour cette fonction.");
        }

        // Budget de raisonnement. Sur un modèle « thinking » (Gemini 3.x Flash en
        // fait partie), le raisonnement est facturé dans `completion_tokens` sans
        // jamais atteindre l'utilisateur : mesuré à 532 jetons sur 761 pour une
        // question simple, et surtout il consomme `max_tokens`, ce qui tronque la
        // réponse en plein mot. Par défaut, l'effort vient de config('ai.reasoning')
        // selon la fonction (`low` ramène le raisonnement à 0 jeton, mesuré) ; une
        // option `reasoning` explicite prime (null = ne rien envoyer).
        // ⚠️ Jamais `['enabled' => false]` : Gemini 3.7 Flash le refuse en 400
        // (« Reasoning is mandatory for this endpoint ») ; et `exclude: true` masque
        // le raisonnement sans rien économiser — seul `effort` agit.
        $reasoning = array_key_exists('reasoning', $options)
            ? $options['reasoning']
            : $this->defaultReasoning($feature);

        return new ChatRequest(
            feature: $feature,
            model: $model,
            messages: $messages,
            temperature: (float) ($options['temperature'] ?? 0),
            maxTokens: isset($options['max_tokens']) ? (int) $options['max_tokens'] : null,
            reasoning: is_array($reasoning) && $reasoning !== [] ? $reasoning : null,
            stream: $stream,
            tools: $tools,
            toolChoice: $tools !== [] ? ($options['tool_choice'] ?? null) : null,
            jsonSchema: $jsonSchema,
            fileAnnotations: (array) ($options['file_annotations'] ?? []),
        );
    }

    /**
     * Effort de raisonnement par défaut pour une fonction : `chat`/`chat_tool`
     * → config('ai.reasoning.chat'), tout le reste → config('ai.reasoning.documents').
     * `none` (ou vide) = aucun paramètre envoyé, pour un serveur qui le refuserait.
     *
     * @return array<string, string>|null
     */
    private function defaultReasoning(string $feature): ?array
    {
        $key = in_array($feature, ['chat', 'chat_tool'], strict: true) ? 'chat' : 'documents';
        $effort = strtolower(trim((string) config("ai.reasoning.{$key}", 'low')));

        if ($effort === '' || $effort === 'none') {
            return null;
        }

        return ['effort' => $effort];
    }

    /**
     * Requête HTTP préconfigurée (porte-clé du fournisseur, en-têtes, délai).
     *
     * Le porte-clé varie : `Authorization: Bearer` chez OpenRouter et OpenAI,
     * `x-api-key` chez Anthropic, `x-goog-api-key` chez Google — d'où la
     * délégation, plutôt qu'un `withToken()` en dur.
     */
    private function request(): PendingRequest
    {
        $provider = $this->provider();

        return Http::withHeaders(
            $provider->authHeaders((string) $this->access->key()) + $provider->headers()
        )
            ->timeout((int) config('ai.timeout', 60))
            ->acceptJson();
    }

    /**
     * Envoie la requête, avec UNE nouvelle tentative sur 429 / 5xx / délai.
     * Jamais de nouvelle tentative sur 400 / 401 / 402 : ce sont des erreurs
     * définitives, réessayer ne ferait que doubler le coût.
     *
     * @param  array<mixed>  $meta  Renseigné par référence (`retried`).
     *
     * @throws AiException
     */
    private function sendWithRetry(ChatRequest $chatRequest, array &$meta): Response
    {
        $provider = $this->provider();
        $payload = $provider->buildPayload($chatRequest);
        $url = $provider->baseUrl() . $provider->completionsPath($chatRequest->model, $chatRequest->stream);

        $attempt = 0;

        while (true) {
            try {
                $request = $this->request();

                if ($chatRequest->stream) {
                    $request = $request->withOptions(['stream' => true]);
                }

                $response = $request->post($url, $payload);

                if ($response->successful()) {
                    return $response;
                }

                $retryable = $response->status() === 429 || $response->status() >= 500;

                if ($retryable && $attempt < self::MAX_RETRIES) {
                    $attempt++;
                    $meta['retried'] = true;
                    $this->pauseBeforeRetry($response);

                    continue;
                }

                throw $this->exceptionFor($response->status(), $response);
            } catch (ConnectionException) {
                if ($attempt < self::MAX_RETRIES) {
                    $attempt++;
                    $meta['retried'] = true;
                    $this->pauseBeforeRetry(null);

                    continue;
                }

                throw new AiTimeout;
            }
        }
    }

    /**
     * Temporise avant la nouvelle tentative : sans pause, un second appel
     * immédiat après un 429 échoue presque à coup sûr. `Retry-After` est
     * respecté s'il est exprimé en secondes, plafonné pour ne pas bloquer la
     * requête HTTP de l'utilisateur (l'app n'a pas de file d'attente).
     */
    private function pauseBeforeRetry(?Response $response): void
    {
        $seconds = self::DEFAULT_RETRY_DELAY_SECONDS;
        $retryAfter = $response?->header('Retry-After');

        if ($retryAfter !== null && $retryAfter !== '' && ctype_digit($retryAfter)) {
            $seconds = min((int) $retryAfter, self::MAX_RETRY_DELAY_SECONDS);
        }

        Sleep::for($seconds)->seconds();
    }

    /**
     * Traduction d'un statut HTTP en exception typée.
     *
     * Le corps de la réponse est INSPECTÉ localement (pour reconnaître un refus
     * de `json_schema`) mais n'est jamais relayé : les messages restent fixes,
     * et rien n'en sort vers un log, une vue ou le journal d'usage.
     */
    private function exceptionFor(int $status, ?Response $response = null): AiException
    {
        return match (true) {
            $status === 401 || $status === 403 => new AiKeyInvalid,
            $status === 402 => new AiInsufficientCredits,
            $status === 429 => new AiRateLimited,
            // 404 = modèle inconnu ; tout 5xx = panne côté fournisseur, pas une
            // réponse inexploitable du modèle.
            $status === 404 || $status >= 500 => new AiModelUnavailable,
            $status === 400 => new AiBadResponse(schemaRejected: $this->mentionsJsonSchema($response)),
            default => new AiBadResponse,
        };
    }

    /**
     * Le refus vient-il de la sortie structurée (serveur qui ne la gère pas) ?
     * Seul cas justifiant un second appel, avec un schéma relâché.
     *
     * Le corps est lu ici mais n'en ressort jamais : seule la réponse
     * booléenne du pilote franchit cette frontière.
     */
    private function mentionsJsonSchema(?Response $response): bool
    {
        if ($response === null) {
            return false;
        }

        return $this->provider()->rejectsStructuredOutput(strtolower($response->body()));
    }

    /**
     * Construit l'AiResult à partir d'une réponse non diffusée.
     *
     * @throws AiBadResponse
     */
    private function buildResult(Response $response, string $model, float $started, bool $decodeJson): AiResult
    {
        $parsed = $this->provider()->parseResponse((array) $response->json(), $model);
        $raw = $parsed->content;

        // Le parsing du fichier est déjà facturé : on le transporte jusque dans
        // l'exception pour qu'une passe de réparation puisse le rejouer.
        if (! is_string($raw) || trim($raw) === '') {
            throw new AiBadResponse(fileAnnotations: $parsed->fileAnnotations);
        }

        $content = $raw;

        if ($decodeJson) {
            $decoded = json_decode(self::unwrapJson($raw), associative: true);

            if (! is_array($decoded)) {
                throw new AiBadResponse(fileAnnotations: $parsed->fileAnnotations);
            }

            $content = $decoded;
        }

        return new AiResult(
            content: $content,
            model: $parsed->model,
            promptTokens: $parsed->promptTokens,
            completionTokens: $parsed->completionTokens,
            costUsd: $this->resolveCost(
                $parsed->reportedCost,
                $parsed->model,
                $parsed->promptTokens,
                $parsed->completionTokens,
            ),
            durationMs: $this->elapsedMs($started),
            providerRequestId: $parsed->requestId,
            finishReason: $parsed->finishReason,
            message: $parsed->message,
            toolCalls: $parsed->toolCalls,
            fileAnnotations: $parsed->fileAnnotations,
            upstreamProvider: $parsed->upstreamProvider,
        );
    }

    /**
     * Retire l'éventuel bloc de code Markdown autour d'un JSON.
     *
     * `response_format: json_schema` n'est pas honoré par tous les fournisseurs,
     * quoi qu'annonce leur fiche : mesuré le 2026-08-21, `anthropic/claude-sonnet-5`
     * servi par Amazon Bedrock renvoie son JSON encadré de ```json … ` alors que le
     * catalogue le déclare compatible. Sans ce nettoyage, le décodage échoue et tout
     * modèle qui se comporte ainsi devient inutilisable.
     *
     * La capacité réelle dépend du FOURNISSEUR qui sert la requête, pas seulement du
     * modèle : valider tout nouveau modèle par un appel réel, jamais par un mock.
     *
     * On ne touche qu'à l'enveloppe : si le contenu n'est pas encadré, il est
     * renvoyé tel quel et un vrai JSON invalide échoue comme avant.
     */
    private static function unwrapJson(string $raw): string
    {
        $trimmed = trim($raw);

        if (! str_starts_with($trimmed, '```')) {
            return $trimmed;
        }

        // ```json\n{…}\n``` — la langue est facultative.
        if (preg_match('/^```[a-zA-Z]*\s*\R(.*)\R\s*```$/s', $trimmed, $matches)) {
            return trim($matches[1]);
        }

        return trim(preg_replace('/^```[a-zA-Z]*|```$/', '', $trimmed) ?? $trimmed);
    }

    /**
     * Lit un flux SSE.
     *
     * Le TRANSPORT est ici — découpage en lignes, commentaires de maintien de
     * connexion, lignes `event:` nommées, fin de flux — et le DÉCODAGE d'un
     * événement appartient au pilote : les dialectes diffèrent (OpenAI et
     * OpenRouter terminent par `data: [DONE]`, Anthropic par un événement
     * `message_stop`).
     *
     * @throws AiException
     */
    private function consumeStream(Response $response, string $model, float $started, ?callable $onDelta): AiResult
    {
        $provider = $this->provider();
        $state = new StreamState($model, $onDelta);
        $stream = $response->toPsrResponse()->getBody();

        $buffer = '';
        $eventName = null;

        try {
            while (! $stream->eof() && ! $state->done) {
                $chunk = $stream->read(8192);

                if ($chunk === '') {
                    break;
                }

                $buffer .= $chunk;

                while (($newline = strpos($buffer, "\n")) !== false) {
                    $line = substr($buffer, 0, $newline);
                    $buffer = substr($buffer, $newline + 1);

                    $this->processSseLine($line, $eventName, $state, $provider);

                    if ($state->done) {
                        break;
                    }
                }
            }

            // Dernière ligne éventuellement non terminée par un saut de ligne.
            if (! $state->done && trim($buffer) !== '') {
                $this->processSseLine($buffer, $eventName, $state, $provider);
            }
        } catch (Throwable $e) {
            // Coupure réseau APRÈS le 200 : l'exception Guzzle brute embarquerait
            // des détails de transport ; on la traduit en délai dépassé, journalisé
            // par l'appelant comme toute AiException.
            if ($e instanceof AiException) {
                throw $e;
            }

            throw new AiTimeout("La connexion avec le service d'intelligence artificielle a été interrompue.");
        }

        if ($state->text === '' && $state->toolCalls === []) {
            throw new AiBadResponse;
        }

        $toolCalls = $state->orderedToolCalls();

        return new AiResult(
            content: $state->text,
            model: $state->model,
            promptTokens: (int) ($state->usage['prompt_tokens'] ?? 0),
            completionTokens: (int) ($state->usage['completion_tokens'] ?? 0),
            costUsd: $this->resolveCost(
                $state->reportedCost,
                $state->model,
                (int) ($state->usage['prompt_tokens'] ?? 0),
                (int) ($state->usage['completion_tokens'] ?? 0),
            ),
            durationMs: $this->elapsedMs($started),
            providerRequestId: $state->requestId,
            finishReason: $state->finishReason,
            message: array_filter([
                'role' => 'assistant',
                'content' => $state->text !== '' ? $state->text : null,
                'tool_calls' => $toolCalls !== [] ? $toolCalls : null,
            ], fn ($value) => $value !== null),
            toolCalls: $toolCalls,
        );
    }

    /**
     * Découpe une ligne SSE et confie sa charge utile au pilote.
     *
     * @param  string|null  $eventName  Nom du dernier `event:` lu, par référence :
     *                                  Anthropic s'en sert pour typer ses fragments.
     */
    private function processSseLine(string $line, ?string &$eventName, StreamState $state, AiProvider $provider): void
    {
        $line = trim($line);

        // Ligne vide = fin d'un événement : le nom ne vaut plus pour le suivant.
        if ($line === '') {
            $eventName = null;

            return;
        }

        // Commentaire SSE : maintien de connexion (OpenRouter envoie « : PROCESSING »).
        if (str_starts_with($line, ':')) {
            return;
        }

        if (str_starts_with($line, 'event:')) {
            $eventName = trim(substr($line, 6));

            return;
        }

        if (! str_starts_with($line, 'data:')) {
            return;
        }

        $data = trim(substr($line, 5));

        if ($data === '[DONE]') {
            $state->done = true;

            return;
        }

        $decoded = json_decode($data, associative: true);

        if (! is_array($decoded)) {
            return;
        }

        $provider->decodeStreamEvent($eventName, $decoded, $state);
    }

    /**
     * Coût de l'appel en dollars.
     *
     * Quand le fournisseur rend le coût réel (OpenRouter), c'est lui qui fait
     * foi. À défaut — serveur local, mais aussi tout protocole natif, qui n'en
     * renvoie aucun — estimation bcmath depuis config('ai.model_prices') si
     * l'administrateur l'a renseignée, sinon aucun coût journalisé plutôt qu'un
     * chiffre inventé.
     *
     * @param  mixed  $reportedCost  Valeur brute rendue par le fournisseur, ou null.
     */
    private function resolveCost(mixed $reportedCost, string $model, int $promptTokens, int $completionTokens): ?string
    {
        if (is_numeric($reportedCost)) {
            // sprintf plutôt qu'un cast : json_decode rend un float, dont la
            // représentation textuelle peut être en notation scientifique
            // (1.0E-5) que bcmath ne sait pas lire.
            return bcadd(sprintf('%.6F', (float) $reportedCost), '0', 6);
        }

        // Accès direct au tableau : un slug de modèle contient des points
        // (« gemini-3.7-flash ») que la notation pointée de config() couperait.
        $prices = config('ai.model_prices')[$model] ?? null;

        if (! is_array($prices) || ! isset($prices['in'], $prices['out'])) {
            return null;
        }

        $inCost = bcdiv(bcmul((string) $promptTokens, (string) $prices['in'], 8), '1000000', 8);
        $outCost = bcdiv(bcmul((string) $completionTokens, (string) $prices['out'], 8), '1000000', 8);

        return bcadd($inCost, $outCost, 6);
    }

    private function elapsedMs(float $started): int
    {
        return (int) round((microtime(true) - $started) * 1000);
    }

    /**
     * Écrit UNE ligne de journal par appel logique.
     *
     * `meta` ne reçoit que des compteurs et des types : jamais de texte extrait,
     * jamais de base64, jamais la clé.
     *
     * @param  array<mixed>  $meta
     */
    private function journal(
        string $feature,
        ?string $model,
        string $status,
        float $started,
        array $meta = [],
        ?string $errorCode = null,
        ?AiResult $result = null,
    ): void {
        // Le fournisseur amont réellement servi (métadonnée, aucun contenu) :
        // transforme la promesse de routage en fait constatable.
        if ($result?->upstreamProvider !== null) {
            $meta['upstream_provider'] = $result->upstreamProvider;
        }

        AiUsageLog::create([
            'user_id' => $this->access->user()->id,
            'feature' => $feature,
            'model' => $model,
            'key_source' => $this->access->source(),
            'status' => $status,
            'error_code' => $errorCode,
            'prompt_tokens' => $result?->promptTokens ?? 0,
            'completion_tokens' => $result?->completionTokens ?? 0,
            'cost_usd' => $result?->costUsd,
            'duration_ms' => $result?->durationMs ?? $this->elapsedMs($started),
            'meta' => $meta === [] ? null : $meta,
            'created_at' => now(),
        ]);
    }
}
