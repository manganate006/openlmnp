<?php

namespace App\Http\Middleware;

use App\Support\DossierContext;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Garde à CHAQUE requête d'un dossier lié : principal présent et utilisable,
 * empreinte de mot de passe inchangée, lien toujours valide, dossier non archivé
 * (voir `App\Support\DossierContext::validate()` pour le détail).
 *
 * Posée en `isPersistent: true` sur le panel (donc revérifiée à chaque mise à jour
 * Livewire, pas seulement au chargement d'une page) ET explicitement sur les routes
 * sensibles du groupe `web` qui ne passent pas par le panel (`/d/{path}`,
 * `/vault/key-material`, `/adresse/recherche`).
 *
 * Sans invalidité : ne fait RIEN (pas de requête supplémentaire pour un compte
 * normal — un seul accès à `is_linked_dossier`, déjà chargé avec l'utilisateur).
 */
class EnsureDossierContext
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::user();

        if ($user === null || ! $user->is_linked_dossier) {
            return $next($request);
        }

        if (DossierContext::validate($user) === null) {
            return $next($request);
        }

        // Fait sortir du contexte AVANT de lever l'exception : si le principal est
        // encore utilisable, la session porte déjà sa bonne identité quand la
        // redirection vers la page de connexion atterrit — Filament y renvoie
        // aussitôt un utilisateur déjà authentifié vers le tableau de bord.
        DossierContext::recoverFrom($user);

        throw new AuthenticationException('Contexte de dossier invalide.');
    }
}
