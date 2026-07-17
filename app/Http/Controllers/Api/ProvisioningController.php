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
        $user = $this->findUser($request);
        $user->suspended_at = now();
        $user->save();

        return response()->json(['status' => 'suspended', 'id' => $user->id]);
    }

    public function unsuspend(Request $request): JsonResponse
    {
        $user = $this->findUser($request);
        $user->suspended_at = null;
        $user->save();

        return response()->json(['status' => 'active', 'id' => $user->id]);
    }

    private function findUser(Request $request): User
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'max:255'],
        ]);

        return User::query()->where('email', $data['email'])->firstOrFail();
    }
}
