<?php

namespace App\Http\Middleware;

use App\Models\McpAuditLog;
use App\Models\User;
use App\Support\DossierContext;
use App\Support\McpAuditRedactor;
use App\Support\McpDemo;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

class McpGuard
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! config('mcp.enabled')) {
            abort(404);
        }

        $user = $request->user();

        if (! $user || ! $user->mcp_enabled) {
            abort(403, 'MCP access is not enabled for this account.');
        }

        if ($user->isSuspended()) {
            abort(403, 'This account is suspended.');
        }

        // Décodage JSON-RPC (méthode, outil, id) — utilisé par le garde démo et l'audit.
        $body = $request->json()->all();
        $method = $body['method'] ?? null;
        $rpcId = $body['id'] ?? null;
        $toolName = null;
        $params = null;

        if ($method === 'tools/call') {
            $toolName = $body['params']['name'] ?? 'unknown';
            // Masqués avant journalisation : contenus de fichiers, URL signées,
            // chaînes longues (voir McpAuditRedactor).
            $params = McpAuditRedactor::redact($body['params']['arguments'] ?? null);
        } elseif ($method) {
            $toolName = $method;
        }

        $isDemoRequest = McpDemo::isDemoRequest($request);

        // === Garde démo publique (compte à identifiants publics) ===
        if ($isDemoRequest) {
            // Rate-limiting par IP (le token est partagé).
            $limiterKey = 'mcp-demo:' . $request->ip();
            $maxPerMinute = max(1, (int) config('mcp.demo.rate_limit_per_minute', 20));

            if (RateLimiter::tooManyAttempts($limiterKey, $maxPerMinute)) {
                return response()->json([
                    'jsonrpc' => '2.0',
                    'id' => $rpcId,
                    'error' => [
                        'code' => -32000,
                        'message' => 'Démo publique OpenLMNP : trop de requêtes, réessayez dans un instant.',
                    ],
                ], 429);
            }
            RateLimiter::hit($limiterKey, 60);

            // Outils d'écriture : visibles dans tools/list mais NON exécutables.
            if ($method === 'tools/call' && ! McpDemo::allows($toolName, $request)) {
                $this->audit($user, $toolName, $params, 'error', $request, 0);

                return response()->json([
                    'jsonrpc' => '2.0',
                    'id' => $rpcId,
                    'result' => [
                        'content' => [[
                            'type' => 'text',
                            'text' => McpDemo::blockedMessage(),
                        ]],
                        'isError' => true,
                    ],
                ]);
            }
        }

        // === Limite de débit des autres comptes (MCP_RATE_LIMIT appels par minute et par
        // compte, 0 = aucune). Avant ce garde, la valeur n'était lue que pour l'affichage
        // (GHSA-j4gm-g8m8-93x2, point 10).
        $perMinute = (int) config('mcp.rate_limit', 60);

        // La limite de débit est COMPTÉE SUR LE PRINCIPAL ($user, pas un
        // éventuel dossier basculé plus bas) : c'est son jeton, son compte, son
        // abonnement.
        if (! $isDemoRequest && $perMinute > 0) {
            $limiterKey = 'mcp-user:' . $user->id;

            if (RateLimiter::tooManyAttempts($limiterKey, $perMinute)) {
                return response()->json([
                    'jsonrpc' => '2.0',
                    'id' => $rpcId,
                    'error' => [
                        'code' => -32000,
                        'message' => 'Trop de requêtes MCP : ' . $perMinute . ' par minute au plus. Réessayez dans un instant.',
                    ],
                ], 429);
            }
            RateLimiter::hit($limiterKey, 60);
        }

        // === Jeton rattaché à un dossier lié (lot 5 des dossiers liés) ===
        //
        // Le jeton reste celui du PRINCIPAL ($user, ci-dessus — débit et
        // suspension déjà contrôlés sur LUI, jamais sur un dossier). S'il porte
        // `dossier_user_id`, REVÉRIFIÉ À CHAQUE APPEL (jamais seulement à sa
        // création dans McpTokens) : appartenance toujours active, dossier ni
        // archivé ni fermé, et pour un compte membre, garde anti-détournement
        // (App\Support\DossierContext::checkMembership(), point commun avec la
        // bascule web). Un échec est une ERREUR EXPLICITE — JAMAIS un repli
        // silencieux sur le principal : un jeton « dossier » ne doit jamais se
        // mettre à écrire dans le dossier personnel.
        //
        // ⚠️ Jamais pour une requête DÉMO : le jeton public démo n'a de toute
        // façon jamais de `dossier_user_id` (McpTokens ferme la création d'un
        // jeton de dossier pour ce compte) — cette ligne est une sécurité EN
        // PROFONDEUR, pas le mécanisme qui l'empêche à la source.
        $dossierUserId = null;

        if (! $isDemoRequest) {
            $dossierUserId = $user->currentAccessToken()?->dossier_user_id;

            if ($dossierUserId !== null) {
                $failure = $this->dossierFailure($user, $dossierUserId);

                if ($failure !== null) {
                    $this->audit($user, $toolName, $params, 'error', $request, 0, $dossierUserId);

                    return response()->json([
                        'jsonrpc' => '2.0',
                        'id' => $rpcId,
                        'error' => [
                            'code' => -32001,
                            'message' => $failure,
                        ],
                    ], 403);
                }

                // Bascule : à partir d'ici, l'identité des OUTILS devient le
                // dossier (scopes d'isolation compris). $user, lui, continue de
                // désigner le PRINCIPAL pour tout le reste de cette méthode
                // (variable PHP locale, non affectée par ce changement d'état
                // d'authentification) — c'est lui que l'audit journalise.
                $dossierUser = User::find($dossierUserId);
                Auth::setUser($dossierUser->withAccessToken($user->currentAccessToken()));
            }
        }

        $start = microtime(true);

        $response = $next($request);

        $durationMs = (int) ((microtime(true) - $start) * 1000);

        if ($toolName) {
            $this->audit(
                $user,
                $toolName,
                $params,
                $response->isSuccessful() ? 'success' : 'error',
                $request,
                $durationMs,
                $dossierUserId,
            );
        }

        return $response;
    }

    /**
     * Le PRINCIPAL ($principal) a-t-il toujours accès au dossier désigné par le
     * jeton ($dossierUserId) ? Message d'erreur EXPLICITE sinon, jamais `null`
     * par défaut — un code de garde oublié ici serait un repli silencieux.
     */
    private function dossierFailure(User $principal, int $dossierUserId): ?string
    {
        $dossierUser = User::find($dossierUserId);

        if ($dossierUser === null || ! $dossierUser->is_linked_dossier) {
            return 'Ce dossier n\'existe plus : ce jeton ne donne plus accès à aucune donnée.';
        }

        $linkedDossier = $dossierUser->linkedDossier;

        $failureCode = $linkedDossier === null
            ? DossierContext::FAILURE_DOSSIER_ARCHIVED
            : DossierContext::checkMembership($principal, $linkedDossier);

        return match ($failureCode) {
            null => null,
            DossierContext::FAILURE_DOSSIER_ARCHIVED => 'Ce dossier est archivé ou fermé : ce jeton ne donne plus accès à aucune donnée.',
            DossierContext::FAILURE_MEMBERSHIP_REVOKED => 'Vous n\'êtes plus membre de ce dossier : ce jeton est révoqué.',
            DossierContext::FAILURE_NO_ACTIVE_BACKER => 'Ce dossier n\'a plus aucun membre abonné ou en compte standard actif : il n\'est plus accessible.',
            default => 'Ce dossier n\'est plus accessible.',
        };
    }

    private function audit(
        User $user,
        ?string $toolName,
        mixed $params,
        string $status,
        Request $request,
        int $durationMs,
        ?int $dossierUserId = null,
    ): void {
        McpAuditLog::create([
            'user_id' => $user->id,
            'dossier_user_id' => $dossierUserId,
            'token_name' => $user->currentAccessToken()?->name,
            'tool_name' => $toolName,
            'parameters' => $params,
            'result_status' => $status,
            'ip_address' => $request->ip(),
            'duration_ms' => $durationMs,
            'created_at' => now(),
        ]);
    }
}
