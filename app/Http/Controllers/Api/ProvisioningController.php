<?php

namespace App\Http\Controllers\Api;

use App\Enums\AccountKind;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Notifications\WelcomeSetPassword;
use App\Support\DossierQuota;
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
            $this->promoteMemberAccount($existing);

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

    /**
     * Pose le quota de dossiers liés d'un compte (lot 4 du plan « dossiers liés »),
     * appelée par la vitrine après la création ou la promotion du compte au paiement.
     *
     * Réponse UNIFORME (même modèle que `setSuspension` : F10, pas d'oracle
     * d'énumération d'e-mails) : un e-mail inconnu, un dossier lié (jamais principal)
     * ou un compte de démonstration reçoivent exactement la même réponse qu'un compte
     * principal réel — seul un journal côté serveur distingue ces cas, pour repérer une
     * désynchronisation avec la vitrine sans la révéler à l'appelant.
     *
     * ⚠️ Ne baisse JAMAIS le quota sous le nombre de dossiers déjà créés par ce
     * compte (`DossierQuota::used()`, dossiers actifs non archivés) : la vitrine peut
     * rejouer un ancien montant (reprise, rétro-compatibilité, erreur de séquence)
     * sans jamais retirer à un abonné un dossier qu'il a déjà ouvert.
     */
    public function entitlements(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'linked_dossier_quota' => ['required', 'integer', 'min:0'],
        ]);

        $user = User::query()
            ->where('email', $data['email'])
            ->where('is_linked_dossier', false)
            ->first();

        // Défense en profondeur : un compte membre n'a jamais de quota propre
        // (App\Support\DossierQuota::for() force 0 de toute façon), mais s'abstenir
        // d'ÉCRIRE la colonne évite d'y laisser une valeur qui n'a aucun sens tant
        // que `promoteMemberAccount()` (appelée par `store()`, toujours en amont
        // côté vitrine) ne l'a pas promu en compte standard.
        if ($user && $user->isMemberAccount()) {
            Log::warning('Provisioning: entitlements demandé pour un compte membre (ignoré)', [
                'email' => $data['email'],
            ]);

            return response()->json(['status' => 'updated']);
        }

        if ($user && ! $user->isDemo()) {
            $floor = DossierQuota::used($user);
            $user->linked_dossier_quota = max((int) $data['linked_dossier_quota'], $floor);
            $user->save();
        } else {
            Log::warning('Provisioning: entitlements demandé pour un compte introuvable ou inéligible', [
                'email' => $data['email'],
            ]);
        }

        return response()->json(['status' => 'updated']);
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

    /**
     * Un compte « membre » (App\Enums\AccountKind::Member — accès gratuit
     * d'indivisaire, créé depuis une invitation de dossier) qui s'abonne devient un
     * compte STANDARD ordinaire, SANS perdre son appartenance au dossier lié (lot
     * « dossiers membre », décision de Jérémie du 2026-10-15 : « si la personne
     * s'abonne un jour, son compte membre doit pouvoir devenir standard sans perdre
     * son appartenance au dossier »).
     *
     * ⚠️ `linked_dossier_members` ne pend que de `user_id` (jamais réécrit ici) :
     * promouvoir, c'est réécrire UNE SEULE colonne sur UNE SEULE ligne — même
     * principe que `promoteSandbox()` pour un bac à sable de démonstration. Appelée
     * depuis `store()`, le SEUL point d'entrée du webhook de paiement de la vitrine
     * pour un compte qui existe déjà : c'est pourquoi une promotion de membre ne
     * peut PAS passer inaperçue, même quand `store()` répond `exists` (réponse
     * inchangée à dessein, pour ne rien casser côté appelant).
     */
    private function promoteMemberAccount(User $user): void
    {
        if (! $user->isMemberAccount()) {
            return;
        }

        $user->forceFill(['account_kind' => AccountKind::Standard])->save();

        Log::info('Provisioning: compte membre promu en compte standard au paiement', [
            'user_id' => $user->id,
        ]);
    }
}
