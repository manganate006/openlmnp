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
        // Requête servie en HTTPS (directement ou derrière un proxy de confiance) : le
        // cookie de session ne doit plus partir en clair. Décidé à la requête plutôt que
        // d'après APP_URL, pour qu'une instance aussi ouverte en HTTP sur le réseau local
        // garde une connexion possible. SESSION_SECURE_COOKIE, s'il est réglé, l'emporte
        // (GHSA-j4gm-g8m8-93x2, point 20).
        if ($request->isSecure() && config('session.secure') === null) {
            config(['session.secure' => true]);
        }

        $response = $next($request);
        $headers = $response->headers;

        // HSTS seulement sur une réponse HTTPS, et sans écraser celui d'un proxy.
        if ($request->isSecure() && ! $headers->has('Strict-Transport-Security')) {
            $headers->set('Strict-Transport-Security', 'max-age=31536000');
        }

        $headers->set('X-Content-Type-Options', 'nosniff');
        $headers->set('X-Frame-Options', 'SAMEORIGIN');
        $headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');

        if (! $headers->has('Permissions-Policy')) {
            $headers->set('Permissions-Policy', 'geolocation=(), camera=(), microphone=(), payment=()');
        }

        return $response;
    }
}
