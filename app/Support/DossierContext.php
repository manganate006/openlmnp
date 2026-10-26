<?php

namespace App\Support;

use App\Models\DossierEvent;
use App\Models\LinkedDossier;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * Point UNIQUE de la bascule d'authentification vers un dossier lié (voir
 * `_admin/docs/plan-dossiers-lies-2026-10-14.md` § 2).
 *
 * Un dossier lié est un `User` à part entière, sans connexion directe : ouvrir un
 * dossier authentifie SON `User` (`Auth::login()`), le compte principal étant
 * mémorisé en session. C'est ce qui permet à l'app de rester juste partout ailleurs
 * — scopes d'isolation, chemins de documents, MCP — sans y toucher : `Auth::user()`
 * reste la « bonne » identité pour les données, pas pour la facturation.
 *
 * Dix endroits précis doivent au contraire viser le PRINCIPAL plutôt que le dossier
 * actif (plan § 2.3) : clé IA/quota/consentement, jetons MCP, identité du profil,
 * avis, mode de navigation, limite Sirene... C'est `principal()` qui les résout.
 *
 * ⚠️ **Le « vieil onglet », piège Laravel 13** (corrigé 2026-10-15, voir `CLAUDE.md`
 * § « Dossiers liés ») : le plan d'origine comptait sur un 419 CSRF natif pour fermer
 * un onglet resté sur l'identité d'avant une bascule. C'est faux en Laravel 13 —
 * `PreventRequestForgery::hasValidOrigin()` laisse passer toute requête portant
 * `Sec-Fetch-Site: same-origin`, ce que tout navigateur envoie pour une requête du
 * site lui-même : le jeton CSRF n'est alors même pas comparé. `contextId()` /
 * `regenerateContext()` ci-dessous sont le correctif : un identifiant de CONTEXTE,
 * distinct du jeton CSRF, posé dans CHAQUE page rendue (balise `<meta>`) et renvoyé
 * par chaque requête d'écriture (en-tête Livewire, champ cache des formulaires). Il
 * change à chaque `Auth::login()` (connexion, bascule, retour) — donc un onglet
 * resté sur l'identité précédente envoie un identifiant périmé, et
 * `App\Http\Middleware\VerifyDossierContext` le refuse par un 419 explicite, celui-là
 * bien réel. Voir ce middleware pour le détail de ce qu'il couvre.
 */
final class DossierContext
{
    public const SESSION_PRINCIPAL_ID = 'dossier.principal_id';

    public const SESSION_PASSWORD_FINGERPRINT = 'dossier.principal_password_fingerprint';

    /** Identifiant de contexte de la session courante — voir le commentaire de classe. */
    public const SESSION_CONTEXT_ID = 'dossier.context';

    /**
     * Marque posée dans une session REMPLACÉE par une bascule (voir `markSessionReplaced()`).
     * Une session qui la porte ne contient rien d'autre : ni identité, ni contexte.
     */
    public const SESSION_REPLACED = 'dossier.session_replaced';

    /**
     * Identifiant de contexte de la session courante, créé au besoin (page rendue
     * avant que la session n'en porte un — ne devrait arriver qu'une fois, juste
     * après ce déploiement). Stable tant qu'aucun `Auth::login()` ne survient.
     */
    public static function contextId(): string
    {
        $id = session(self::SESSION_CONTEXT_ID);

        if (is_string($id) && $id !== '') {
            return $id;
        }

        return self::regenerateContext();
    }

    /**
     * Force un NOUVEL identifiant de contexte et l'inscrit en session. Appelée par
     * `switchTo()`, et par les deux branches de `leave()`/`recoverFrom()` (retour au
     * principal OU déconnexion complète) — les SEULS endroits de l'application qui
     * changent l'identité authentifiée D'UNE SESSION DÉJÀ ÉTABLIE (vérifié : tous les
     * autres `Auth::login()` du dépôt — connexion normale, démo, reprise de démo,
     * création d'un compte membre — authentifient une session fraîche, sans identité
     * antérieure à protéger ; `contextId()` leur suffit, en initialisation paresseuse
     * à la première page rendue après coup).
     */
    public static function regenerateContext(): string
    {
        $id = Str::random(40);

        session([self::SESSION_CONTEXT_ID => $id]);

        return $id;
    }

    /** L'utilisateur courant est-il (ou `$user`, si fourni) un dossier lié ? */
    public static function isLinked(?User $user = null): bool
    {
        $user ??= Auth::user();

        return (bool) $user?->is_linked_dossier;
    }

    /** L'utilisateur authentifié — identique à `Auth::user()`, pour lisibilité. */
    public static function active(): ?User
    {
        return Auth::user();
    }

    /**
     * Un compte « membre » (App\Enums\AccountKind::Member) agissant dans SON PROPRE
     * PRINCIPAL — pas encore basculé dans un dossier lié (lot « dossiers membre »,
     * décision de Jérémie du 2026-10-15). C'est dans cet état précis, et SEULEMENT
     * celui-ci, que la saisie de biens lui est fermée : une fois basculé DANS un
     * dossier (`is_linked_dossier` vaut alors `true`), il saisit normalement les
     * données DU DOSSIER.
     *
     * Partagée par App\Filament\Resources\Concerns\BlocksMemberPrincipal, les pages
     * d'assistant qui créent des biens depuis zéro, le widget d'explication du
     * tableau de bord et la page « Dossiers liés ».
     */
    public static function isMemberActingAsPrincipal(?User $user = null): bool
    {
        $user ??= Auth::user();

        return $user !== null && ! $user->is_linked_dossier && $user->isMemberAccount();
    }

    /**
     * Le compte qui PAIE et qui DÉCIDE : celui dont il faut consulter la clé IA, le
     * quota, le consentement, l'audience de l'avis, le mode de navigation, la limite
     * Sirene... Résolu depuis la session quand `$user` (par défaut l'utilisateur
     * courant) est un dossier lié ; identique à `$user` sinon.
     *
     * Rend `null` seulement si `$user` lui-même est `null`, ou si un dossier lié n'a
     * aucun principal résolvable en session (ne devrait pas arriver après le passage
     * d'`EnsureDossierContext`, qui aurait déjà fait sortir du contexte avant que ce
     * code ne s'exécute).
     */
    public static function principal(?User $user = null): ?User
    {
        $user ??= Auth::user();

        if ($user === null) {
            return null;
        }

        if (! $user->is_linked_dossier) {
            return $user;
        }

        $principalId = session(self::SESSION_PRINCIPAL_ID);

        return $principalId === null ? null : User::find($principalId);
    }

    /**
     * Dossiers ouvrables par ce principal : ceux dont il est membre valide
     * (accepté, non révoqué), non archivés.
     *
     * @return Collection<int, LinkedDossier>
     */
    public static function dossiersFor(User $principal): Collection
    {
        return LinkedDossier::query()
            ->active()
            ->whereMemberIs($principal->id)
            ->with('user')
            ->get();
    }

    /**
     * Ouvre le dossier : authentifie son `User`, mémorise le principal et
     * l'empreinte de son mot de passe, régénère session et jeton CSRF.
     *
     * @throws DossierSwitchException
     */
    public static function switchTo(LinkedDossier $dossier): void
    {
        $principal = Auth::user();

        if ($principal === null) {
            throw DossierSwitchException::unauthenticated();
        }

        // La bascule part toujours du principal : jamais d'un dossier vers un autre
        // sans repasser par `leave()` — sinon l'empreinte de mot de passe mémorisée
        // resterait celle du tout premier principal, jamais rafraîchie.
        if ($principal->is_linked_dossier) {
            throw DossierSwitchException::alreadyLinked();
        }

        if ($principal->suspended_at !== null) {
            throw DossierSwitchException::principalSuspended();
        }

        if ($principal->isDemo()) {
            throw DossierSwitchException::demo();
        }

        if ($dossier->isArchived()) {
            throw DossierSwitchException::archived();
        }

        $isMember = $dossier->members()
            ->where('user_id', $principal->id)
            ->whereNotNull('accepted_at')
            ->whereNull('revoked_at')
            ->exists();

        if (! $isMember) {
            throw DossierSwitchException::notAMember();
        }

        // Anti-détournement (lot « dossiers membre », décision du 2026-10-15) : un
        // compte membre n'ouvre ce dossier que s'il y subsiste au moins un membre
        // actif qui n'est pas lui-même un compte membre. Un compte STANDARD, lui,
        // n'est jamais soumis à cette garde : c'est précisément ce qui le fait
        // compter comme « backer » pour les comptes membres du même dossier.
        if ($principal->isMemberAccount() && ! $dossier->hasActiveNonMemberBacker()) {
            throw DossierSwitchException::noActiveBacker();
        }

        $dossierUser = $dossier->user;

        if ($dossierUser === null || ! $dossierUser->is_linked_dossier) {
            throw DossierSwitchException::notAMember();
        }

        session([
            self::SESSION_PRINCIPAL_ID => $principal->id,
            self::SESSION_PASSWORD_FINGERPRINT => self::fingerprint($principal),
        ]);

        $previousSessionId = self::currentSessionId();

        Auth::login($dossierUser);
        self::forgetPasswordHash();
        // Vieil onglet : toute page rendue AVANT cette bascule porte désormais un
        // identifiant de contexte périmé (voir VerifyDossierContext).
        self::regenerateContext();
        self::afterSessionMigration($previousSessionId);

        DossierEvent::record($dossier->id, DossierEvent::TYPE_SWITCHED, $principal);
    }

    /** Retour au principal mémorisé en session, ou déconnexion s'il n'est plus valable. */
    public static function leave(): void
    {
        $active = Auth::user();

        if ($active === null || ! $active->is_linked_dossier) {
            return;
        }

        $dossierId = $active->linkedDossier?->id;
        $principal = self::principal($active);

        session()->forget([self::SESSION_PRINCIPAL_ID, self::SESSION_PASSWORD_FINGERPRINT]);

        if ($principal !== null && self::isPrincipalUsable($principal)) {
            $previousSessionId = self::currentSessionId();
            Auth::login($principal);
            self::forgetPasswordHash();
            self::afterSessionMigration($previousSessionId);
        } else {
            self::fullLogout();
        }

        // Vieil onglet, les deux branches : qu'on revienne au principal ou qu'on
        // déconnecte entièrement, toute page rendue DANS le dossier qu'on quitte
        // porte désormais un identifiant de contexte périmé (voir VerifyDossierContext).
        self::regenerateContext();

        if ($dossierId !== null) {
            DossierEvent::record($dossierId, DossierEvent::TYPE_LEFT, $principal);
        }
    }

    /**
     * Le contexte du dossier ACTIF est-il toujours valide ? Appelée à chaque
     * requête par `EnsureDossierContext` ET par `User::canAccessPanel()`.
     *
     * @return string|null un code d'échec (voir constantes FAILURE_*), ou null si tout va bien
     */
    public const FAILURE_NO_PRINCIPAL = 'no_principal';

    public const FAILURE_PRINCIPAL_MISSING = 'principal_missing';

    public const FAILURE_PRINCIPAL_SUSPENDED = 'principal_suspended';

    public const FAILURE_PRINCIPAL_DEMO = 'principal_demo';

    public const FAILURE_PASSWORD_CHANGED = 'principal_password_changed';

    public const FAILURE_DOSSIER_ARCHIVED = 'dossier_archived';

    public const FAILURE_MEMBERSHIP_REVOKED = 'membership_revoked';

    /** Anti-détournement (lot « dossiers membre ») : voir `LinkedDossier::hasActiveNonMemberBacker()`. */
    public const FAILURE_NO_ACTIVE_BACKER = 'no_active_backer';

    public static function validate(User $dossier): ?string
    {
        if (! $dossier->is_linked_dossier) {
            return null;
        }

        $principalId = session(self::SESSION_PRINCIPAL_ID);

        if ($principalId === null) {
            // Aucune trace d'une bascule légitime : connexion directe, jeton forgé,
            // ou session créée hors du chemin normal.
            return self::FAILURE_NO_PRINCIPAL;
        }

        $principal = User::find($principalId);

        if ($principal === null || $principal->is_linked_dossier) {
            return self::FAILURE_PRINCIPAL_MISSING;
        }

        if ($principal->suspended_at !== null) {
            return self::FAILURE_PRINCIPAL_SUSPENDED;
        }

        if ($principal->isDemo()) {
            return self::FAILURE_PRINCIPAL_DEMO;
        }

        $fingerprint = session(self::SESSION_PASSWORD_FINGERPRINT);

        if (! is_string($fingerprint) || ! hash_equals($fingerprint, self::fingerprint($principal))) {
            return self::FAILURE_PASSWORD_CHANGED;
        }

        $linkedDossier = $dossier->linkedDossier;

        if ($linkedDossier === null) {
            return self::FAILURE_DOSSIER_ARCHIVED;
        }

        return self::checkMembership($principal, $linkedDossier);
    }

    /**
     * Un $principal donné a-t-il encore accès à $dossier : dossier non archivé,
     * membre accepté et non révoqué, et pour un compte membre, garde
     * anti-détournement (`LinkedDossier::hasActiveNonMemberBacker()`) ?
     *
     * Point UNIQUE, partagé par deux appelants qui n'ont PAS la même source de
     * principal : `validate()` ci-dessus (session web — EnsureDossierContext, en
     * persistant, donc revérifiée à chaque requête, Livewire comprise) et
     * `App\Http\Middleware\McpGuard` (lot 5 : un jeton MCP rattaché à un
     * dossier, revérifié à CHAQUE appel — pas de session dans ce contexte API
     * stateless, le principal est déjà connu : le `tokenable` du jeton).
     *
     * @return string|null un code d'échec FAILURE_*, ou null si tout va bien
     */
    public static function checkMembership(User $principal, LinkedDossier $dossier): ?string
    {
        if ($dossier->isArchived()) {
            return self::FAILURE_DOSSIER_ARCHIVED;
        }

        $isMember = $dossier->members()
            ->where('user_id', $principal->id)
            ->whereNotNull('accepted_at')
            ->whereNull('revoked_at')
            ->exists();

        if (! $isMember) {
            return self::FAILURE_MEMBERSHIP_REVOKED;
        }

        // Anti-détournement : un compte standard qui se suspend ou se révoque
        // EN COURS DE SESSION (ou entre deux appels MCP) doit fermer l'accès du
        // compte membre dès la requête/l'appel suivant.
        if ($principal->isMemberAccount() && ! $dossier->hasActiveNonMemberBacker()) {
            return self::FAILURE_NO_ACTIVE_BACKER;
        }

        return null;
    }

    /**
     * Fait sortir du contexte invalide : retour au principal s'il est encore
     * utilisable, déconnexion complète sinon. Utilisée par `EnsureDossierContext`.
     */
    public static function recoverFrom(User $dossier): void
    {
        $principalId = session(self::SESSION_PRINCIPAL_ID);
        $principal = $principalId !== null ? User::find($principalId) : null;

        session()->forget([self::SESSION_PRINCIPAL_ID, self::SESSION_PASSWORD_FINGERPRINT]);

        if ($principal !== null && self::isPrincipalUsable($principal)) {
            $previousSessionId = self::currentSessionId();
            Auth::login($principal);
            self::forgetPasswordHash();
            // Vieil onglet : voir le commentaire identique dans leave().
            self::regenerateContext();
            self::afterSessionMigration($previousSessionId);

            return;
        }

        self::fullLogout();
        self::regenerateContext();
    }

    private static function isPrincipalUsable(User $principal): bool
    {
        return ! $principal->is_linked_dossier
            && $principal->suspended_at === null
            && ! $principal->isDemo();
    }

    private static function fullLogout(): void
    {
        Auth::logout();

        if (($session = request()?->session()) !== null) {
            $session->invalidate();
            $session->regenerateToken();
        }
    }

    /**
     * ⚠️ PIÈGE CONFIRMÉ (voir le plan) : `Illuminate\Auth\SessionGuard::login()` repose
     * déjà la bonne empreinte dans `password_hash_{guard}` — mais on l'oublie quand
     * même explicitement après chaque bascule, pour que
     * `Filament\Http\Middleware\AuthenticateSession` la RECALCULE elle-même en fin de
     * requête (son `tap()` final) plutôt que de dépendre d'un détail d'implémentation
     * du coeur Laravel. Une implémentation qui appellerait autre chose que
     * `Auth::login()` pour basculer (ex. un simple `Auth::setUser()`, pour éviter de
     * déclencher l'événement `Login`) OUBLIERAIT cette empreinte, et la toute
     * prochaine requête déconnecterait l'utilisateur en pleine bascule.
     */
    private static function forgetPasswordHash(): void
    {
        session()->forget('password_hash_'.config('auth.defaults.guard', 'web'));
    }

    /**
     * Déconnexion intermittente en deux onglets (test bêta du 2026-10-15), voir
     * App\Http\Middleware\GuardReplacedSession et tests/Feature/Dossiers/SessionRemplaceeTest.php.
     *
     * Chaque bascule passe par `Auth::login()`, qui change l'identifiant de session et DÉTRUIT
     * l'ancien. Une requête de l'autre onglet, partie avant que le navigateur ait reçu le
     * nouveau cookie, arrive avec l'ancien identifiant : Laravel ne trouve plus rien, ouvre une
     * session VIDE sous ce même identifiant et le renvoie dans son cookie. Si cette réponse
     * arrive la dernière, le navigateur abandonne la bonne session : déconnexion.
     *
     * L'ancien identifiant reçoit donc, au lieu de rien, une session qui ne porte que cette
     * marque. Le middleware la reconnaît : il refuse la requête (419 « vous avez changé de
     * dossier dans un autre onglet ») et, surtout, ne renvoie PAS de cookie de session, si
     * bien que le navigateur garde le nouveau. La marque ne contient ni l'identité, ni le
     * nouvel identifiant de session : qui détient l'ancien n'en tire rien.
     */
    public static function markSessionReplaced(string $sessionId): void
    {
        $session = request()?->hasSession() ? request()->session() : null;

        if ($session === null || $sessionId === '') {
            return;
        }

        $handler = $session->getHandler();

        // Pilote `cookie` : la session voyage dans le cookie lui-même, il n'y a pas
        // d'ancienne session stockée à marquer — et l'écrire poserait un cookie de plus.
        if ($handler instanceof \Illuminate\Session\CookieSessionHandler) {
            return;
        }

        // Pilote `database` : le gestionnaire mémorise si la ligne de la session COURANTE
        // existe (insert ou update), et ce drapeau est partagé par tous les identifiants.
        // Remis à zéro avant et après, sinon l'écriture de la vraie session, en fin de
        // requête, viserait une ligne inexistante (`update` sans effet : session perdue).
        // (`Store::setExists()` ne fait rien pour les pilotes qui n'ont pas ce drapeau.)
        $session->setExists(false);

        $tombstone = clone $session;
        $tombstone->setId($sessionId);
        $tombstone->flush();
        $tombstone->put(self::SESSION_REPLACED, time());
        $tombstone->save();

        $session->setExists(false);
    }

    /** Après un `Auth::login()` : marque l'ancienne session si son identifiant a changé. */
    private static function afterSessionMigration(?string $previousSessionId): void
    {
        $current = self::currentSessionId();

        if ($previousSessionId !== null && $current !== null && $previousSessionId !== $current) {
            self::markSessionReplaced($previousSessionId);
        }
    }

    /** La session stockée sous cet identifiant a-t-elle été remplacée par une bascule ? */
    public static function isSessionReplaced(string $sessionId): bool
    {
        $session = request()?->hasSession() ? request()->session() : null;

        if ($session === null) {
            return false;
        }

        $probe = clone $session;
        $probe->setId($sessionId);
        $probe->flush();
        $probe->start();

        return $probe->has(self::SESSION_REPLACED);
    }

    private static function currentSessionId(): ?string
    {
        return request()?->hasSession() ? request()->session()->getId() : null;
    }

    private static function fingerprint(User $principal): string
    {
        return hash('sha256', (string) $principal->getAuthPassword());
    }
}
