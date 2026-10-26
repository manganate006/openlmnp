<?php

namespace App\Http\Controllers;

use App\Services\InvitationService;
use App\Support\RegistrationGate;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

/**
 * Porte d'entrée d'un lien d'invitation.
 *
 * ⚠️ ROUTE AUTONOME, hors du panel, et pas `/register?invitation=xxx`. `AdminPanelProvider`
 * injecte GTM en `HEAD_START`/`BODY_START` sur TOUTES les pages du panel, inscription
 * comprise, dès que `GTM_CONTAINER_ID` est renseigné — et GA4 envoie automatiquement
 * `page_location` avec la query string complète. Le jeton d'invitation partirait donc chez
 * Google à chaque ouverture du lien. `Referrer-Policy` ne couvre pas ce cas.
 *
 * Trois bénéfices secondaires : la page répond même si `ALLOW_REGISTRATION` est mal réglé (et
 * peut le dire), elle échappe à la contrainte CSS du panel, et elle donne une vraie page
 * française pour « invitation expirée » au lieu d'une notification Filament fugace.
 */
class InvitationController extends Controller
{
    public function __construct(private InvitationService $invitations)
    {
    }

    public function __invoke(Request $request, string $token)
    {
        $invitation = $this->invitations->resolve($token);

        if ($invitation === null) {
            // Jeton inconnu : on journalise sans JAMAIS écrire le jeton — c'est le signal
            // grep-able d'un balayage.
            Log::warning('Invitation : jeton présenté sans correspondance', ['ip' => $request->ip()]);

            return $this->refus('inconnue');
        }

        // ⚠️ « Révoquée » est traité EXACTEMENT comme « inconnue », même message octet pour
        // octet. Annoncer « on vous a retiré votre invitation » expose une décision
        // administrative à quelqu'un qu'on vient d'écarter, et n'aide personne.
        if ($invitation->isRevoked()) {
            return $this->refus('inconnue');
        }

        if ($invitation->isAccepted()) {
            return $this->refus('acceptee');
        }

        if ($invitation->isExpired()) {
            return $this->refus('expiree', ['date' => $invitation->expires_at]);
        }

        // Cas réel et agaçant : l'administrateur teste son propre lien en étant connecté, ou
        // un visiteur en bac à sable clique dessus. `Register::mount()` de Filament redirige
        // silencieusement tout utilisateur authentifié vers le tableau de bord — il croirait
        // le lien cassé.
        if (Auth::check() && Auth::user()->email !== $invitation->email) {
            return response()->view('invitation.refus', [
                'cas' => 'deja-connecte',
                'connecte' => Auth::user()->email,
            ], 409);
        }

        // ⚠️ On passe l'ID et le HASH, jamais le jeton en clair : même une session écrite sur
        // le disque ne livre alors pas de lien rejouable. La page d'inscription revérifie que
        // le hash correspond toujours — ce qui fait qu'une RELANCE invalide gratuitement une
        // inscription en cours.
        $request->session()->put('invitation', [
            'id' => $invitation->id,
            'hash' => hash('sha256', $token),
        ]);

        return redirect(Filament::getRegistrationUrl());
    }

    private function refus(string $cas, array $extra = [])
    {
        return response()->view('invitation.refus', ['cas' => $cas] + $extra, 410);
    }
}
