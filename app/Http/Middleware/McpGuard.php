<?php

namespace App\Http\Middleware;

use App\Models\McpAuditLog;
use Closure;
use Illuminate\Http\Request;
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

        $start = microtime(true);

        $response = $next($request);

        $durationMs = (int) ((microtime(true) - $start) * 1000);

        // Audit logging — extract tool name from JSON-RPC body
        $body = $request->json()->all();
        $method = $body['method'] ?? null;
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

        if ($toolName) {
            McpAuditLog::create([
                'user_id' => $user->id,
                'token_name' => $user->currentAccessToken()?->name,
                'tool_name' => $toolName,
                'parameters' => $params,
                'result_status' => $response->isSuccessful() ? 'success' : 'error',
                'ip_address' => $request->ip(),
                'duration_ms' => $durationMs,
                'created_at' => now(),
            ]);
        }

        return $response;
    }
}
