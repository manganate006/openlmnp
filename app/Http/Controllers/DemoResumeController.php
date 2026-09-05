<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\DemoExpiry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;

/**
 * Reconnecte quelqu'un à son bac à sable de démonstration depuis un lien signé.
 *
 * Le besoin : la session dure `SESSION_LIFETIME` (120 min par défaut) alors que le sandbox
 * vit 24 h, et le compte porte un mot de passe aléatoire de 40 caractères. Sans ce lien, un
 * visiteur qui revient trois heures plus tard trouve un bac à sable bien vivant qu'il ne
 * pourra jamais rejoindre.
 */
class DemoResumeController extends Controller
{
    public function __invoke(User $user): RedirectResponse
    {
        // La signature prouve que le lien vient de nous, pas que la cible est légitime :
        // on revérifie donc les deux, sinon un lien signé pour un compte devenu payant
        // (promotion) ouvrirait une session sans mot de passe sur un compte réel.
        if (! $user->is_demo || ! DemoExpiry::for($user)->applies()) {
            return redirect()
                ->route('demo.start')
                ->with('status', "Ce bac à sable n'existe plus. En voici un tout neuf.");
        }

        Auth::login($user);

        // Nouvelle session : un lien de reprise est une clé au porteur, on ne rattache pas
        // l'ancienne session à qui présente le lien.
        request()->session()->regenerate();

        return redirect()->intended('/');
    }
}
