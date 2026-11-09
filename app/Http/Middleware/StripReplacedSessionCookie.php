<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Moitié extérieure de `GuardReplacedSession` (voir son commentaire) : posé AVANT
 * `StartSession`, il retire de la réponse le cookie de session que celui-ci vient d'y ajouter,
 * quand la requête portait une session remplacée par une bascule. Le navigateur garde ainsi le
 * cookie reçu avec la bascule, au lieu de revenir à l'ancien identifiant, vide.
 */
class StripReplacedSessionCookie
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if ($request->attributes->get(GuardReplacedSession::ATTRIBUTE) === true) {
            $response->headers->removeCookie(
                (string) config('session.cookie'),
                (string) (config('session.path') ?? '/'),
                config('session.domain'),
            );
        }

        return $response;
    }
}
