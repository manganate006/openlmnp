<?php

namespace App\Http\Controllers\Dossier;

use App\Http\Controllers\Controller;
use App\Support\DossierContext;
use Filament\Notifications\Notification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;

/** Retour au compte principal. POST uniquement, même raison que OpenDossierController. */
class LeaveDossierController extends Controller
{
    public function __invoke(): RedirectResponse
    {
        abort_unless((bool) config('dossiers.enabled'), 404);

        DossierContext::leave();

        // Notification de sortie (écran 6, variante B), à chaque retour — même choix
        // qu'à l'entrée (OpenDossierController) : voir son commentaire.
        $principal = Auth::user();

        if ($principal !== null && ! $principal->is_linked_dossier) {
            Notification::make()
                ->title('Retour à votre dossier personnel')
                ->body((string) $principal->name)
                ->success()
                ->send();
        }

        return redirect('/');
    }
}
