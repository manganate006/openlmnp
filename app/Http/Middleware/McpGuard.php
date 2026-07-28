<?php

namespace App\Http\Middleware;

use App\Models\McpAuditLog;
use App\Models\User;
use App\Support\McpDemo;
use Closure;
use Illuminate\Http\Request;
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
            $params = $body['params']['arguments'] ?? null;

            // Redact sensitive binary data from audit logs
            if (is_array($params) && isset($params['file_base64'])) {
                $params['file_base64'] = '[REDACTED — ' . strlen($params['file_base64']) . ' chars]';
            }
        } elseif ($method) {
            $toolName = $method;
        }

        // === Garde démo publique (compte à identifiants publics) ===
        if (McpDemo::isDemoRequest($request)) {
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
            );
        }

        return $response;
    }

    private function audit(
        User $user,
        ?string $toolName,
        mixed $params,
        string $status,
        Request $request,
        int $durationMs,
    ): void {
        McpAuditLog::create([
            'user_id' => $user->id,
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
