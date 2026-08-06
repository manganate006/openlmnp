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
        ]);

        $existing = User::query()->where('email', $data['email'])->first();
        if ($existing) {
            return response()->json(['status' => 'exists', 'id' => $existing->id]);
        }

        $user = User::create([
            'name' => $data['name'] ?? Str::before($data['email'], '@'),
            'email' => $data['email'],
            'password' => Str::password(40),
        ]);

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

        return response()->json(['status' => 'created', 'id' => $user->id], 201);
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
}
