<?php

namespace App\Http\Middleware;

use App\Exceptions\StaleDossierContextException;
use App\Support\DossierContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Corrige le « vieil onglet » (voir le commentaire de classe de `App\Support\DossierContext`
 * et `CLAUDE.md` § « Dossiers liés ») : en Laravel 13, `PreventRequestForgery::hasValidOrigin()`
 * laisse passer toute requête `Sec-Fetch-Site: same-origin` SANS comparer le jeton CSRF — ce que
 * tout navigateur envoie pour une requête faite depuis le site lui-même. Un onglet resté ouvert
 * sur une identité périmée (bascule vers un dossier lié, retour au principal, dans un AUTRE
 * onglet de la MÊME session) continuait donc d'écrire, sans la moindre erreur, dans l'identité
 * ACTUELLE de la session — pas celle avec laquelle sa page avait été rendue.
 *
 * Le correctif : un identifiant de CONTEXTE, distinct du jeton CSRF, posé dans chaque page
 * rendue (balise `<meta name="openlmnp-context">`, hook HEAD_END) et renvoyé par chaque requête
 * d'écriture — en-tête `X-OpenLMNP-Context` pour Livewire (hook JS sur `livewire:init`), champ
 * caché `openlmnp_context` pour les formulaires POST classiques (bascule/retour de dossier). Il
 * change à chaque `Auth::login()` qui modifie l'identité d'une session DÉJÀ établie (`switchTo()`,
 * `leave()`, `recoverFrom()` — voir leurs commentaires). Une différence = `StaleDossierContextException`
 * (sous-classe de `TokenMismatchException`), donc un 419 comme le CSRF, avec l'en-tête
 * `X-OpenLMNP-Context-Stale` : le script de `dossier-context-meta.blade.php` affiche alors un
 * message français qui recharge la page, à la place de la boîte native de Livewire, en anglais.
 *
 * Portée : posée en `isPersistent: true` sur le panel (`AdminPanelProvider::panel()`), comme
 * `EnsureDossierContext`, donc appliquée (1) à TOUTES les routes authentifiées du panel — pages
 * ET routes POST, dossiers compris — via le groupe de routes Filament, et (2) à CHAQUE requête
 * de mise à jour Livewire, via le mécanisme de middleware persistant de Livewire (seul moyen
 * d'atteindre cette route : elle est enregistrée par Livewire lui-même, hors du groupe de routes
 * du panel).
 *
 * ⚠️ **Piège vérifié — la vraie méthode HTTP n'est pas fiable ici.**
 * `Livewire\Mechanisms\PersistentMiddleware\PersistentMiddleware::makeFakeRequest()` ne
 * rejoue PAS ce middleware sur une requête pointant vers le VRAI endpoint Livewire
 * (`/livewire-{hash}/update`, enregistré hors du groupe de routes du panel, donc jamais
 * couvert par le routage normal) : il clone la requête réelle en substituant `REQUEST_URI`
 * et `REQUEST_METHOD` par ceux du tout PREMIER montage du composant (typiquement un `GET`
 * de page) — mémorisés dans le `memo` du composant depuis le hook `dehydrate`, propagés
 * identiques à chaque mise à jour suivante. Résultat : `$request->isMethod('post')` renvoie
 * `false` pour une authentique écriture Livewire si la page qui la porte a été chargée en
 * `GET` (le cas normal). Se fier à la méthode aurait rendu ce garde-fou silencieusement
 * inopérant sur tout le canal Livewire. En revanche `duplicate()` ne touche QUE `server`
 * (et seulement ces deux clés) : tous les autres en-têtes de la VRAIE requête survivent,
 * `X-Livewire` compris — c'est donc lui, et non la méthode, qui distingue de façon fiable
 * une authentique interaction Livewire d'une simple navigation.
 *
 * Exclusions, toutes volontaires :
 *  - les requêtes GET sans en-tête `X-Livewire` (lecture, navigation normale) : rien à
 *    vérifier, aucune identité n'est engagée par une simple lecture, et une navigation n'a
 *    de toute façon ni en-tête ni champ à fournir ;
 *  - le téléversement Livewire (`*livewire.upload-file`) : il ne fait que déposer un fichier
 *    TEMPORAIRE sur le disque, hors de toute donnée de dossier — l'enregistrement réel du
 *    justificatif passe par une mise à jour Livewire normale, donc déjà vérifiée. (Cette
 *    route est de toute façon enregistrée hors du groupe du panel, donc jamais soumise à ce
 *    middleware par le routage direct ; l'exclusion ne protège qu'un futur branchement.)
 *  - la déconnexion (`*.auth.logout`) : Filament rend elle-même ce formulaire (vendor, action
 *    `postToUrl()`), sans point d'insertion pour notre champ caché ; et se déconnecter n'écrit
 *    aucune donnée de dossier, seulement l'identité en session — rien à protéger ici. Un onglet
 *    périmé qui se déconnecte ne fait de mal à personne.
 *  - une requête AUTHENTIFIÉE PAR JETON (MCP, provisioning, `/api/*`) ne passe jamais par ici :
 *    elle n'a pas de session, et `$request->user()` y est résolu par Sanctum, pas par le garde
 *    `web` — mais par construction, `Auth::user()` est `null` pour le garde par défaut tant
 *    qu'aucune session authentifiée n'accompagne la requête, donc le garde ci-dessous laisse
 *    passer de toute façon.
 */
class VerifyDossierContext
{
    /** En-tête porté par chaque requête Livewire (voir resources/views/filament/partials/dossier-context-meta.blade.php). */
    public const HEADER = 'X-OpenLMNP-Context';

    /** Champ caché porté par les formulaires POST classiques (bascule/retour de dossier). */
    public const INPUT = 'openlmnp_context';

    public function handle(Request $request, Closure $next): Response
    {
        $isLivewireRequest = $request->hasHeader('X-Livewire');

        if (! $isLivewireRequest && ! $request->isMethod('post')) {
            return $next($request);
        }

        if ($request->routeIs('*livewire.upload-file') || $request->routeIs('*.auth.logout')) {
            return $next($request);
        }

        $user = $request->user();

        if ($user === null) {
            return $next($request);
        }

        $expected = DossierContext::contextId();
        $provided = $request->header(self::HEADER) ?? $request->input(self::INPUT);

        if (! is_string($provided) || $provided === '' || ! hash_equals($expected, $provided)) {
            throw new StaleDossierContextException;
        }

        return $next($request);
    }
}
