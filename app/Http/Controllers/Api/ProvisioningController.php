<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Notifications\WelcomeSetPassword;
use Filament\Facades\Filament;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/**
 * API de provisioning de comptes, protégée par ProvisioningGuard (jeton env).
 * Permet à un système externe (script d'admin, orchestrateur…) de créer,
 * suspendre ou réactiver des comptes utilisateur.
 */
class ProvisioningController extends Controller
{
    /**
     * Crée le compte s'il n'existe pas (idempotent) et envoie l'email de
     * bienvenue avec un lien de définition du mot de passe.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'name' => ['nullable', 'string', 'max:255'],
            // Jeton de reprise d'un bac à sable de démonstration, porté depuis l'app
            // jusqu'aux metadata Stripe puis relu par le webhook de la vitrine.
            'claim' => ['nullable', 'string', 'max:64'],
        ]);

        $existing = User::query()->where('email', $data['email'])->first();
        if ($existing) {
            return response()->json(['status' => 'exists', 'id' => $existing->id]);
        }

        $user = $this->promoteSandbox($data);
        $promoted = $user !== null;

        if (! $promoted) {
            $user = User::create([
                'name' => $data['name'] ?? Str::before($data['email'], '@'),
                'email' => $data['email'],
                'password' => Str::password(40),
            ]);
        }

        try {
            // L'URL signée doit être bâtie sur APP_URL (l'hôte public), pas sur
            // l'hôte de l'appel API interne — sinon le lien serait invalide.
            URL::forceRootUrl(config('app.url'));
            $token = Password::createToken($user);
            $user->notify(new WelcomeSetPassword(Filament::getResetPasswordUrl($token, $user)));
        } catch (\Throwable $e) {
            // Le compte est créé même si l'envoi échoue : l'utilisateur pourra
            // passer par « Mot de passe oublié ».
            Log::error('Provisioning: échec envoi email de bienvenue', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);
        }

        return response()->json([
            'status' => $promoted ? 'promoted' : 'created',
            'id' => $user->id,
        ], 201);
    }

    public function suspend(Request $request): JsonResponse
    {
        return $this->setSuspension($request, now(), 'suspended');
    }

    public function unsuspend(Request $request): JsonResponse
    {
        return $this->setSuspension($request, null, 'active');
    }

    /**
     * Applique/retire la suspension de façon idempotente. La réponse est
     * uniforme, que le compte existe ou non (F10 : pas d'oracle d'énumération
     * d'e-mails) ; un e-mail inconnu est journalisé côté serveur pour repérer
     * une éventuelle désynchronisation avec la vitrine.
     */
    private function setSuspension(Request $request, ?\Illuminate\Support\Carbon $suspendedAt, string $status): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'max:255'],
        ]);

        $user = User::query()->where('email', $data['email'])->first();

        if ($user) {
            $user->suspended_at = $suspendedAt;
            $user->save();
        } else {
            Log::warning('Provisioning: '.$status.' demandé pour un e-mail inconnu', [
                'email' => $data['email'],
            ]);
        }

        return response()->json(['status' => $status]);
    }

    /**
     * Transforme le bac à sable de démonstration en compte payant, sur place.
     *
     * C'est tout le mécanisme de « conserver les données », et il ne déplace RIEN :
     * `properties`, `fiscal_years`, `badge_progress` et `user_badges` pendent tous de
     * `users.id`, et leurs enfants de `property_id` / `fiscal_year_id`. Promouvoir, c'est
     * réécrire trois colonnes sur UNE seule ligne. Pas de sérialisation, pas de format
     * d'échange à maintenir, pas d'écran d'import à construire.
     *
     * Renvoie null quand la promotion n'est pas possible — l'appelant retombe alors sur la
     * création normale, à l'identique. Le client a payé : il obtient un compte quoi qu'il
     * arrive, même si son bac à sable a été purgé entre-temps.
     */
    private function promoteSandbox(array $data): ?User
    {
        if (blank($data['claim'] ?? null)) {
            return null;
        }

        $sandbox = User::query()
            ->where('demo_claim_token', $data['claim'])
            ->where('is_demo', true)
            ->first();

        // Jeton inconnu, ou bac à sable déjà purgé par la commande de nettoyage.
        if ($sandbox === null) {
            Log::info('Provisioning: jeton de reprise sans bac à sable correspondant', [
                'email' => $data['email'],
            ]);

            return null;
        }

        // Un sandbox expiré n'est pas promu : la purge peut passer à tout moment, et
        // ressusciter un compte que la commande s'apprête à supprimer créerait une course.
        if ($sandbox->demo_expires_at !== null && $sandbox->demo_expires_at->isPast()) {
            return null;
        }

        $sandbox->forceFill([
            'name' => $data['name'] ?? $sandbox->name,
            'email' => $data['email'],
            'is_demo' => false,
            'demo_expires_at' => null,
            'demo_claim_token' => null,
            'demo_promoted_at' => now(),
        ])->save();

        return $sandbox;
    }
}
