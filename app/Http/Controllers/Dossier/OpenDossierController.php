<?php

namespace App\Http\Controllers\Dossier;

use App\Http\Controllers\Controller;
use App\Models\LinkedDossier;
use App\Support\DossierContext;
use App\Support\DossierSwitchException;
use Filament\Notifications\Notification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;

/**
 * Bascule vers un dossier lié. POST uniquement (déclarée ainsi dans
 * `AdminPanelProvider`) : une bascule en GET serait suivie par n'importe quel lien
 * ou prérendu de navigateur.
 *
 * ⚠️ Identifiant inconnu OU interdit = le MÊME 404 (plan § 4 Sécurité) : un visiteur
 * qui devine un `public_id` d'autrui ne doit pas pouvoir distinguer « ça n'existe
 * pas » de « ce n'est pas le vôtre ».
 */
class OpenDossierController extends Controller
{
    public function __invoke(string $publicId): RedirectResponse
    {
        abort_unless((bool) config('dossiers.enabled'), 404);

        $dossier = LinkedDossier::where('public_id', $publicId)->first();

        if ($dossier === null) {
            abort(404);
        }

        $principal = Auth::user();

        // Identifiant inconnu OU interdit = le MÊME 404 : une appartenance refusée ne
        // doit jamais se distinguer d'un dossier inexistant.
        if ($principal === null
            || $principal->is_linked_dossier
            || ! $dossier->members()->where('user_id', $principal->id)->whereNotNull('accepted_at')->whereNull('revoked_at')->exists()
        ) {
            abort(404);
        }

        $dossierName = (string) $dossier->user?->name;

        try {
            DossierContext::switchTo($dossier);
        } catch (DossierSwitchException $e) {
            Notification::make()->title($e->getMessage())->danger()->send();

            return redirect()->back();
        }

        // Notification d'entrée (écran 6, variante B) : à CHAQUE bascule, pas
        // seulement la première — un rappel qu'on dissimulerait après la première
        // fois redeviendrait invisible pile quand une erreur de dossier se commet.
        Notification::make()
            ->title('Vous êtes dans « '.$dossierName.' »')
            ->body('Tout ce que vous saisissez ici appartient à l\'indivision. Le bouton « Revenir à '
                .'mon dossier » est dans le bandeau.')
            ->success()
            ->send();

        return redirect('/');
    }
}
