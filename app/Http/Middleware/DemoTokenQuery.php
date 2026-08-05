<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * En mode démo public, toute requête MCP **sans en-tête `Authorization`** est traitée
 * comme le **compte démo public en lecture seule** : le middleware injecte le Bearer du
 * token démo avant `auth:sanctum`.
 *
 * Conséquences :
 * - l'URL de base `…/mcp` répond aux **health-checks / inspecteurs anonymes** (Glama, etc.)
 *   → 200 + serverInfo + 44 outils (démo), plus de 401 ;
 * - la démo est **essayable sans configuration** (pas besoin de `?demo_token=`) ;
 * - un **vrai en-tête `Authorization`** (token perso) a la **priorité** → accès à ses
 *   propres données (aucune régression) ;
 * - hors mode démo (`MCP_DEMO_ENABLED=false`, ex. auto-hébergé), comportement inchangé (401).
 *
 * Réutilise le PAT démo déterministe + la barrière lecture seule de `McpGuard`.
 * Doit s'exécuter AVANT `auth:sanctum`.
 */
class DemoTokenQuery
{
    public function handle(Request $request, Closure $next): Response
    {
        $configured = (string) config('mcp.demo.token');

        if (config('mcp.demo.enabled')
            && $configured !== ''
            && ! $request->headers->has('Authorization')) {
            $request->headers->set('Authorization', 'Bearer ' . $configured);
        }

        return $next($request);
    }
}