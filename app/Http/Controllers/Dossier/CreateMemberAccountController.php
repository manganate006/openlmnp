<?php

namespace App\Http\Controllers\Dossier;

use App\Http\Controllers\Controller;
use App\Providers\AppServiceProvider;
use App\Services\LinkedDossierInvitationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rules\Password;

/**
 * Crée un compte « membre » gratuit (App\Enums\AccountKind::Member) depuis un lien
 * d'invitation de dossier, et le rattache dans la foulée (lot « dossiers membre »,
 * décision de Jérémie du 2026-10-15). Parallèle de AcceptDossierInvitationController
 * (GET, acceptation par un compte DÉJÀ existant) : celui-ci est le POST qui en crée
 * un, pour qui n'en a aucun.
 *
 * ⚠️ Contourne App\Support\RegistrationGate (ALLOW_REGISTRATION `false` ou `invite`)
 * — jamais la page Filament `/register`, qui peut ne pas même être enregistrée dans
 * ce mode (voir App\Providers\Filament\AdminPanelProvider). Route AUTONOME, hors
 * panel, même raison que AcceptDossierInvitationController (jeton en SEGMENT, jamais
 * de GTM dessus).
 *
 * ⚠️ L'e-mail n'est JAMAIS lu depuis la requête : ni validé, ni accepté, même si le
 * navigateur en envoie un. Il vient uniquement de la ligne d'invitation
 * (`$invitation->email`) — c'est ce qui rend cette création étanche à tout forçage
 * d'adresse ET à toute surface d'énumération (voir LinkedDossierInvitationService::
 * acceptAsNewMember()).
 */
class CreateMemberAccountController extends Controller
{
    public function __construct(private LinkedDossierInvitationService $invitations) {}

    public function __invoke(Request $request, string $token)
    {
        abort_unless((bool) config('dossiers.enabled'), 404);

        $invitation = $this->invitations->resolve($token);

        if ($invitation === null) {
            Log::warning('Invitation de dossier (création membre) : jeton présenté sans correspondance', ['ip' => $request->ip()]);

            return $this->refus('inconnue');
        }

        if ($invitation->isRevoked()) {
            return $this->refus('inconnue');
        }

        if ($invitation->isAccepted()) {
            return $this->refus('acceptee');
        }

        if ($invitation->isExpired()) {
            return $this->refus('expiree', ['date' => $invitation->expires_at]);
        }

        // Déjà connecté : ce bouton n'est jamais proposé dans ce cas (la page
        // d'acceptation GET accepte déjà directement une session existante), mais
        // défense en profondeur — on renvoie vers le chemin normal plutôt que de
        // créer un second compte pour une personne déjà authentifiée.
        if (Auth::check()) {
            return redirect()->route('dossier.invitation.show', $token);
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'password' => ['required', 'confirmed', Password::default()],
        ]);

        try {
            $user = $this->invitations->acceptAsNewMember($invitation, [
                'name' => $data['name'],
                'password' => $data['password'],
            ]);
        } catch (\RuntimeException $e) {
            return response()->view('dossier-invitation.show', [
                'cas' => 'erreur',
                'message' => $e->getMessage(),
            ], 410);
        }

        Auth::login($user);
        $request->session()->regenerate();

        // Jamais `purchase` (plan § 5) : créer ce compte n'est pas une vente, aucune
        // facture — même mécanisme que `linked_dossier_joined`.
        AppServiceProvider::queueAnalyticsEvent(['event' => 'linked_dossier_joined']);

        return response()->view('dossier-invitation.show', [
            'cas' => 'bienvenue',
            'dossierName' => (string) $invitation->dossier->user?->name,
        ]);
    }

    private function refus(string $cas, array $extra = [])
    {
        return response()->view('dossier-invitation.show', ['cas' => $cas] + $extra, 410);
    }
}
