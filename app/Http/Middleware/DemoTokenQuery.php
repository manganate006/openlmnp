<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authentifie le token MCP démo public via le paramètre d'URL `?demo_token=<token>`
 * (en plus de l'en-tête `Authorization`).
 *
 * Utile pour les gateways/annuaires (ex. Smithery) qui **réservent** l'en-tête
 * `Authorization` et ne peuvent donc pas transmettre un Bearer à l'upstream : un
 * paramètre d'URL, lui, passe. Si le token démo (public, lecture seule) est fourni
 * en query et qu'aucun `Authorization` n'est déjà présent, on le promeut en Bearer
 * → le reste de la chaîne (`auth:sanctum` → `McpGuard`) fonctionne à l'identique
 * (résolution du PAT démo déterministe + gating lecture seule). Aucune nouvelle
 * logique d'auth. **Doit s'exécuter AVANT `auth:sanctum`.**
 */
class DemoTokenQuery
{
    public function handle(Request $request, Closure $next): Response
    {
        $configured = (string) config('mcp.demo.token');
        $provided = (string) $request->query('demo_token', '');

        if (config('mcp.demo.enabled')
            && $configured !== ''
            && ! $request->headers->has('Authorization')
            && $provided !== ''
            && hash_equals($configured, $provided)) {
            $request->headers->set('Authorization', 'Bearer ' . $configured);
        }

        return $next($request);
    }
}
