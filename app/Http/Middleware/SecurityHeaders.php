<?php

namespace App\Http\Middleware;

use App\Support\ContentSecurityPolicy;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * En-têtes de sécurité HTTP posés côté application (F8).
 *
 * En production ces en-têtes viennent habituellement du reverse proxy (NPM),
 * mais ils disparaissent en accès direct au conteneur. On les repose ici en
 * défense en profondeur.
 *
 * Content-Security-Policy (GHSA-j4gm-g8m8-93x2, point 20) : politique calculée par
 * App\Support\ContentSecurityPolicy, compatible avec le panel (Alpine standard,
 * scripts en ligne) — voir là-bas ce qu'elle protège et ce qu'elle ne protège pas.
 * Une réponse qui porte déjà sa propre politique (service des justificatifs,
 * DocumentController) la garde : la nôtre, plus large, l'affaiblirait.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        // Cookie de session « Secure » par défaut dès que l'instance est en HTTPS
        // (GHSA-j4gm-g8m8-93x2, point 20). SESSION_SECURE_COOKIE, s'il est réglé, l'emporte.
        // Une ligne « SESSION_SECURE_COOKIE= » laissée vide dans .env vaut « non réglé ».
        if (in_array(config('session.secure'), [null, ''], true) && self::shouldUseSecureCookie($request)) {
            config(['session.secure' => true]);
        }

        $response = $next($request);
        $headers = $response->headers;

        // HSTS seulement sur une réponse HTTPS, et sans écraser celui d'un proxy.
        // Jamais sur HTTP : un navigateur l'ignore, et une instance du réseau local servie
        // en clair ne doit pas se retrouver injoignable. HSTS_MAX_AGE=0 le coupe.
        $maxAge = (int) config('security.hsts_max_age', 31536000);
        if ($request->isSecure() && $maxAge > 0 && ! $headers->has('Strict-Transport-Security')) {
            $headers->set('Strict-Transport-Security', 'max-age='.$maxAge);
        }

        $csp = ContentSecurityPolicy::headerName();
        if ($csp !== null
            && ! $headers->has('Content-Security-Policy')
            && ! $headers->has('Content-Security-Policy-Report-Only')) {
            $headers->set($csp, ContentSecurityPolicy::policy());
        }

        $headers->set('X-Content-Type-Options', 'nosniff');
        $headers->set('X-Frame-Options', 'SAMEORIGIN');
        $headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');

        if (! $headers->has('Permissions-Policy')) {
            $headers->set('Permissions-Policy', 'geolocation=(), camera=(), microphone=(), payment=()');
        }

        return $response;
    }

    /**
     * Deux cas, et seulement ceux-là :
     *  - la requête arrive en HTTPS (directement, ou derrière un proxy de confiance qui
     *    l'annonce par X-Forwarded-Proto) ;
     *  - APP_URL est en https et la requête vise SON nom d'hôte : c'est l'adresse publique
     *    de l'instance, que le navigateur atteint en HTTPS même si le proxy qui termine TLS
     *    n'est pas dans TRUSTED_PROXIES (le cookie partait alors en clair).
     * Une instance ouverte AUSSI en HTTP sur le réseau local, par son IP ou un autre nom,
     * garde un cookie sans Secure sur ces adresses : sinon le navigateur le refuserait et
     * la connexion y deviendrait impossible.
     */
    private static function shouldUseSecureCookie(Request $request): bool
    {
        if ($request->isSecure()) {
            return true;
        }

        $appUrl = (string) config('app.url');

        return parse_url($appUrl, PHP_URL_SCHEME) === 'https'
            && strcasecmp((string) parse_url($appUrl, PHP_URL_HOST), $request->getHost()) === 0;
    }
}
