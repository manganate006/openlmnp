<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\DemoDataService;
use App\Support\FeedbackEligibility;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Démarre une session de démonstration éphémère et isolée.
 *
 * Chaque visiteur obtient SA PROPRE copie des données fictives, rattachée
 * à un utilisateur éphémère distinct (user_id unique). L'isolation entre
 * visiteurs est garantie par le scope global BelongsToUserScope : aucun
 * visiteur ne voit ni n'impacte les données d'un autre.
 *
 * Les comptes démo expirent après config('demo.ttl_hours') et sont purgés
 * par la commande openlmnp:demo-cleanup.
 */
class DemoLoginController extends Controller
{
    public function __invoke(Request $request, DemoDataService $demoData)
    {
        if (! config('demo.enabled')) {
            abort(404);
        }

        // Limite le nombre de comptes démo actifs : on nettoie d'abord les
        // expirés, puis on refuse si la limite est toujours atteinte.
        $activeDemoAccounts = fn () => User::query()
            ->where('is_demo', true)
            ->where('demo_expires_at', '>', now())
            ->count();

        if ($activeDemoAccounts() >= config('demo.max_accounts')) {
            $this->cleanupExpired();

            if ($activeDemoAccounts() >= config('demo.max_accounts')) {
                abort(503, 'La démonstration accueille trop de visiteurs actuellement. Merci de réessayer dans quelques minutes.');
            }
        }

        // Crée un utilisateur éphémère avec un email unique.
        $user = User::create([
            'name' => 'Visiteur démo',
            'email' => 'demo-' . Str::random(12) . '@demo.local',
            'password' => Hash::make(Str::random(40)),
            'is_demo' => true,
            'demo_expires_at' => now()->addHours(config('demo.ttl_hours')),
        ]);

        // Copie privée des données fictives pour CE visiteur.
        $demoData->seedForUser($user);

        Auth::login($user);
        $request->session()->regenerate();

        // Drapeau first-party « a testé la démo » (30 jours, sans identifiant) :
        // consommé par l'événement sign_up (paramètre from_demo) pour mesurer
        // la conversion démo → inscription même après purge du compte sandbox.
        Cookie::queue('olmnp_demo_seen', '1', 60 * 24 * 30);

        // Date du PREMIER passage, écrite une seule fois. Un sandbox ne vivant que
        // config('demo.ttl_hours'), c'est le seul moyen de savoir qu'un visiteur est
        // revenu plusieurs jours plus tard : son compte d'alors n'existe plus.
        // Cookie distinct de `olmnp_demo_seen`, dont la valeur `'1'` est lue ailleurs.
        if (! $request->hasCookie(FeedbackEligibility::COOKIE_FIRST_SEEN)) {
            Cookie::queue(FeedbackEligibility::COOKIE_FIRST_SEEN, now()->toDateString(), 60 * 24 * 30);
        }

        return redirect('/');
    }

    /**
     * Supprime les comptes démo expirés (et leurs données via cascade FK).
     */
    protected function cleanupExpired(): void
    {
        User::query()
            ->where('is_demo', true)
            ->where('demo_expires_at', '<', now())
            ->get()
            ->each(fn (User $user) => $user->delete());
    }
}
