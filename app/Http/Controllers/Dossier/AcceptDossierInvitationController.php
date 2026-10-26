<?php

namespace App\Http\Controllers\Dossier;

use App\Http\Controllers\Controller;
use App\Models\LinkedDossierInvitation;
use App\Models\User;
use App\Providers\AppServiceProvider;
use App\Services\LinkedDossierInvitationService;
use App\Support\RegistrationGate;
use Filament\Facades\Filament;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

/**
 * Porte d'entrée d'un lien d'invitation à un dossier lié (second indivisaire,
 * lot 3, écran 7 revu en variante B le 2026-10-15). Parallèle à
 * App\Http\Controllers\InvitationController (inscription), mais celui-ci ne crée
 * jamais de compte — il rattache un principal à un dossier existant.
 *
 * ⚠️ Route AUTONOME hors panel, même raison qu'InvitationController (jeton en
 * segment d'URL, jamais de GTM sur cette page — voir routes/web.php).
 *
 * ⚠️ **Changement de comportement du lot 3** (décision du 2026-10-15, écran 7
 * variante B) : `show()` (GET) n'accepte PLUS jamais l'invitation elle-même —
 * c'est désormais `confirm()` (POST STRICTEMENT) qui le fait, sur un clic
 * EXPLICITE de l'invité qui vient de voir ce qu'il accepte. Un simple GET, même
 * répété ou rejoué par un robot de prévisualisation de courriel, ne rattache donc
 * plus jamais personne — contre-épreuve couverte par
 * tests/Feature/Dossiers/LinkedDossierAcceptanceTest.php (« sans confirmation,
 * aucun rattachement »).
 */
class AcceptDossierInvitationController extends Controller
{
    public function __construct(private LinkedDossierInvitationService $invitations) {}

    /** Affiche l'invitation — AUCUNE mutation, quel que soit l'état de connexion. */
    public function show(Request $request, string $token): Response
    {
        abort_unless((bool) config('dossiers.enabled'), 404);

        [$blocked, $invitation, $user] = $this->resolveEligible($request, $token);

        if ($blocked !== null) {
            return $blocked;
        }

        $dossierName = (string) $invitation->dossier->user?->name;

        if ($user === null) {
            // Mémorise cette URL comme destination après connexion OU inscription :
            // Filament\Auth\Http\Responses\{Login,Registration}Response font toutes
            // deux un redirect()->intended() — même clé de session que
            // redirect()->guest() (que l'on n'utilise pas ici puisqu'on affiche
            // d'abord une page d'explication, pas une redirection immédiate).
            session(['url.intended' => $request->fullUrl()]);

            // ⚠️ `RegistrationGate::allows()`, pas seulement `exposesRoute()` : en
            // mode « invite », la route /register EXISTE mais exige SA PROPRE
            // invitation de compte (App\Services\InvitationService) — un lien de
            // création n'y mènerait qu'à une impasse pour qui n'en détient pas une.
            // Les deux mécanismes d'invitation restent volontairement séparés
            // (voir le rapport de livraison du lot 3).
            $inscriptionUrl = RegistrationGate::allows() ? Filament::getRegistrationUrl() : null;

            return response()->view('dossier-invitation.show', [
                'cas' => 'rejoindre',
                'dossierName' => $dossierName,
                'email' => $invitation->email,
                'connexionUrl' => Filament::getLoginUrl(),
                'inscriptionUrl' => $inscriptionUrl,
                'siteUrl' => $this->siteUrl(),
                // Toujours proposée, QUEL QUE SOIT RegistrationGate (c'est précisément
                // son intérêt : contourner ALLOW_REGISTRATION `false`/`invite` avec un
                // jeton d'invitation de DOSSIER valide — décision du 2026-10-15).
                'creerCompteUrl' => route('dossier.invitation.create-member', $token),
            ]);
        }

        // Connecté, bonne adresse, compte utilisable : on montre ce qu'accepter va
        // faire et on DEMANDE confirmation — rien n'est rattaché ici.
        return response()->view('dossier-invitation.show', [
            'cas' => 'confirmer',
            'dossierName' => $dossierName,
            'siren' => $invitation->dossier->siren,
            'invitedByName' => $invitation->invitedBy?->name,
            'email' => $user->email,
            'confirmUrl' => route('dossier.invitation.confirm', $token),
            'declineUrl' => route('dossier.invitation.decline', $token),
        ]);
    }

    /**
     * Rattache réellement l'invité au dossier — POST uniquement, sur confirmation
     * explicite (bouton « Rejoindre le dossier » de l'écran affiché par `show()`).
     */
    public function confirm(Request $request, string $token): Response|RedirectResponse
    {
        abort_unless((bool) config('dossiers.enabled'), 404);

        [$blocked, $invitation, $user] = $this->resolveEligible($request, $token);

        if ($blocked !== null) {
            return $blocked;
        }

        if ($user === null) {
            // Pas de session (lien rouvert dans un autre onglet, session expirée
            // entre-temps...) : on ne rattache jamais sans connaissance de qui
            // confirme. Retour à la page d'explication, qui reproposera la connexion.
            return redirect()->route('dossier.invitation.show', $token);
        }

        $dossierName = (string) $invitation->dossier->user?->name;

        try {
            $this->invitations->accept($invitation, $user);
        } catch (\RuntimeException $e) {
            return response()->view('dossier-invitation.show', [
                'cas' => 'erreur',
                'message' => $e->getMessage(),
            ], 410);
        }

        // Jamais `purchase` (plan § 5) : rejoindre un dossier n'est pas une vente,
        // aucune facture. Même mécanisme que `linked_dossier_created` (session
        // flash consommée par partials/gtm-head).
        AppServiceProvider::queueAnalyticsEvent(['event' => 'linked_dossier_joined']);

        return response()->view('dossier-invitation.show', [
            'cas' => 'bienvenue',
            'dossierName' => $dossierName,
        ]);
    }

    /**
     * Refus explicite (bouton « Refuser » de l'écran de confirmation) : ferme le
     * lien du point de vue de l'invité, sans rien rattacher. Mêmes conditions
     * d'éligibilité que `confirm()` — refuser exige d'être identifié comme la bonne
     * personne, au même titre qu'accepter.
     */
    public function decline(Request $request, string $token): Response|RedirectResponse
    {
        abort_unless((bool) config('dossiers.enabled'), 404);

        [$blocked, $invitation, $user] = $this->resolveEligible($request, $token);

        if ($blocked !== null) {
            return $blocked;
        }

        if ($user === null) {
            return redirect()->route('dossier.invitation.show', $token);
        }

        $this->invitations->decline($invitation);

        return response()->view('dossier-invitation.show', ['cas' => 'refusee']);
    }

    /**
     * Cas « mauvais compte » (écran 7, variante B) : UN SEUL bouton, qui déconnecte
     * la session active PUIS ramène sur ce même lien — jamais un simple lien de
     * connexion, qui laisserait la mauvaise session ouverte à côté du formulaire.
     */
    public function switchAccount(Request $request, string $token): RedirectResponse
    {
        abort_unless((bool) config('dossiers.enabled'), 404);

        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('dossier.invitation.show', $token);
    }

    /**
     * Résout le jeton et vérifie toute l'éligibilité qui NE DÉPEND PAS de l'action
     * demandée (état du jeton, puis — si connecté — recevabilité du compte actif).
     * Partagée par `show()`, `confirm()` et `decline()` pour que les trois gardes ne
     * divergent jamais.
     *
     * @return array{0: ?Response, 1: ?LinkedDossierInvitation, 2: ?User} le refus à
     *         renvoyer tel quel (sinon `null`), l'invitation, l'utilisateur connecté
     *         (`null` si personne n'est connecté — pas un refus en soi, à `show()`
     *         de décider quoi en faire)
     */
    private function resolveEligible(Request $request, string $token): array
    {
        $invitation = $this->invitations->resolve($token);

        if ($invitation === null) {
            // Jeton inconnu : journalisé sans JAMAIS écrire le jeton — signal
            // grep-able d'un balayage, même patron qu'InvitationController.
            Log::warning('Invitation de dossier : jeton présenté sans correspondance', ['ip' => $request->ip()]);

            return [$this->refus('inconnue'), null, null];
        }

        // ⚠️ « Révoquée » traitée EXACTEMENT comme « inconnue », même message :
        // annoncer qu'on a retiré l'invitation à quelqu'un qu'on vient d'écarter
        // n'aide personne (même choix qu'InvitationController).
        if ($invitation->isRevoked() || $invitation->isDeclined()) {
            return [$this->refus('inconnue'), null, null];
        }

        if ($invitation->isAccepted()) {
            return [$this->refus('acceptee'), null, null];
        }

        if ($invitation->isExpired()) {
            return [$this->refus('expiree', ['date' => $invitation->expires_at]), null, null];
        }

        $user = Auth::user();

        if ($user === null) {
            return [null, $invitation, null];
        }

        // Un dossier lié n'a jamais de connexion directe : son User ne peut pas
        // être $user ici (canAccessPanel() le lui interdirait déjà sur le panel),
        // mais cette route est hors panel — défense en profondeur.
        if ($user->is_linked_dossier) {
            return [$this->refus('compte-invalide', [
                'raison' => 'Ce compte est lui-même un dossier lié : il ne peut pas en rejoindre un autre.',
            ]), null, null];
        }

        if ($user->isDemo()) {
            return [$this->refus('compte-invalide', [
                'raison' => 'Un compte de démonstration ne peut pas rejoindre de dossier lié.',
            ]), null, null];
        }

        if ($user->suspended_at !== null) {
            return [$this->refus('compte-invalide', [
                'raison' => 'Ce compte est suspendu.',
            ]), null, null];
        }

        if (mb_strtolower($user->email) !== $invitation->email) {
            return [response()->view('dossier-invitation.show', [
                'cas' => 'mauvais-compte',
                'dossierName' => (string) $invitation->dossier->user?->name,
                'email' => $invitation->email,
                'connecte' => $user->email,
                'switchAccountUrl' => route('dossier.invitation.switch-account', $token),
            ], 409), null, null];
        }

        return [null, $invitation, $user];
    }

    private function siteUrl(): string
    {
        $signup = trim((string) config('services.website.signup_url'));

        return $signup !== '' ? $signup : trim((string) config('services.website.url'));
    }

    private function refus(string $cas, array $extra = []): Response
    {
        return response()->view('dossier-invitation.show', ['cas' => $cas] + $extra, 410);
    }
}
