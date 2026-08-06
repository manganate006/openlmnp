<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * En-têtes de sécurité HTTP posés côté application (F8).
 *
 * En production ces en-têtes viennent habituellement du reverse proxy (NPM),
 * mais ils disparaissent en accès direct au conteneur. On les repose ici en
 * défense en profondeur. Pas de Content-Security-Policy : le panel Filament
 * (styles/scripts inline, Alpine, Livewire) casserait sous une CSP stricte —
 * elle est laissée au proxy, calibrée séparément.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $headers = $response->headers;

        $headers->set('X-Content-Type-Options', 'nosniff');
        $headers->set('X-Frame-Options', 'SAMEORIGIN');
        $headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');

        if (! $headers->has('Permissions-Policy')) {
            $headers->set('Permissions-Policy', 'geolocation=(), camera=(), microphone=(), payment=()');
        }

        return $response;
    }
}
