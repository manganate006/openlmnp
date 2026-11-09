<?php

namespace App\Http\Middleware;

use App\Exceptions\StaleDossierContextException;
use App\Support\DossierContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Déconnexion intermittente en deux onglets (test bêta du 2026-10-15) : voir
 * `App\Support\DossierContext::markSessionReplaced()` pour la cause, et
 * tests/Feature/Dossiers/SessionRemplaceeTest.php pour sa reproduction.
 *
 * Posé JUSTE APRÈS `StartSession` (liste de priorité, `bootstrap/app.php`), sur le panel et sur
 * le groupe `web` (donc la route de mise à jour de Livewire). Deux cas :
 *
 *  - la session chargée est une session REMPLACÉE par une bascule (elle ne porte que la
 *    marque) : la requête vient d'un onglet resté sur l'ancien cookie. Refus immédiat, par le
 *    même 419 que le vieil onglet, sans rien exécuter ;
 *  - la session a été remplacée PENDANT la requête (bascule concurrente dans l'autre onglet,
 *    terminée entre-temps) : la requête a lu l'ancienne session avant sa destruction, et
 *    `StartSession` s'apprête à la réécrire telle quelle, avec son cookie. On remet la marque
 *    à la place de son contenu, pour que l'écriture finale ne ressuscite pas l'ancienne
 *    identité.
 *
 * Dans les deux cas, la requête est signalée à `StripReplacedSessionCookie`, posé AVANT
 * `StartSession`, qui retire de la réponse le cookie de session : c'est ce cookie, arrivé
 * après celui de la bascule, qui faisait abandonner au navigateur la bonne session.
 */
class GuardReplacedSession
{
    public const ATTRIBUTE = 'openlmnp.session_replaced';

    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->hasSession()) {
            return $next($request);
        }

        $session = $request->session();

        if ($session->has(DossierContext::SESSION_REPLACED)) {
            $request->attributes->set(self::ATTRIBUTE, true);

            return (new StaleDossierContextException)->render($request);
        }

        $sessionId = $session->getId();

        $response = $next($request);

        // Même identifiant qu'au début : cette requête n'a pas elle-même basculé. Si la
        // session stockée porte désormais la marque, une bascule l'a remplacée pendant ce
        // temps.
        if ($session->getId() === $sessionId && DossierContext::isSessionReplaced($sessionId)) {
            $session->flush();
            $session->put(DossierContext::SESSION_REPLACED, time());
            $request->attributes->set(self::ATTRIBUTE, true);
        }

        return $response;
    }
}
