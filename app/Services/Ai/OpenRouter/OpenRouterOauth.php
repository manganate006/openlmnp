<?php

namespace App\Services\Ai\OpenRouter;

use App\Models\AiUsageLog;
use App\Models\User;
use App\Services\Ai\Exceptions\AiOauthFailed;
use App\Services\Ai\Providers\Capability;
use App\Support\AiAccess;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use SensitiveParameter;

/**
 * « Se connecter avec OpenRouter » — remise d'une clé par OAuth PKCE.
 *
 * L'utilisateur est envoyé chez le fournisseur, l'autorise, et revient avec un
 * code que cette instance échange contre une clé QUI LUI APPARTIENT. Ni
 * identifiant d'application, ni secret : le défi PKCE est la seule preuve
 * d'identité, ce qui rend le procédé utilisable depuis n'importe quelle
 * installation auto-hébergée, sans inscription préalable.
 *
 * L'instance n'a PAS besoin d'être joignable depuis Internet : le fournisseur
 * ne rappelle jamais le serveur, c'est le navigateur de l'utilisateur — déjà
 * sur le réseau de l'instance — qui fait l'aller-retour. Le seul flux sortant
 * est l'échange du code, que l'instance émet déjà pour tout appel d'IA.
 *
 * ⚠️ Confidentialité — mêmes invariants qu'AiClient, et pour une raison de plus :
 * ici le corps de la réponse d'échange CONTIENT la clé. `Http::throw()` est donc
 * interdit (son exception l'embarquerait), et rien de cette réponse n'atteint un
 * log, une notification ou le journal d'usage.
 */
final class OpenRouterOauth
{
    public const SESSION_KEY = 'ai.openrouter.pkce';

    /** Calé sur l'expiration des codes d'autorisation d'OpenRouter. */
    private const PENDING_TTL_SECONDS = 600;

    /** Borne de sûreté : la colonne et le champ du profil s'arrêtent à 255. */
    private const MAX_KEY_LENGTH = 255;

    /**
     * Le bouton est-il proposé à cet utilisateur ?
     *
     * Quatre conditions, dans cet ordre : la fonctionnalité est activée, le
     * fournisseur sait remettre une clé, l'instance n'impose pas la sienne, et
     * le compte a le droit d'en gérer une.
     */
    public static function isAvailableFor(User $user): bool
    {
        if (! config('ai.enabled') || ! config('ai.oauth_enabled', true)) {
            return false;
        }

        if (! config('ai.allow_user_keys') || filled(config('ai.api_key'))) {
            return false;
        }

        // Un compte suspendu ou de démonstration n'a rien à connecter : le
        // second, dont les identifiants sont publics, encore moins. Un dossier lié
        // non plus (plan des dossiers liés § 2.3) : la clé IA est celle du PRINCIPAL
        // (voir App\Support\AiAccess::for()), jamais celle, inexistante, du dossier.
        if ($user->suspended_at !== null || AiAccess::isDemoAccount($user) || $user->is_linked_dossier) {
            return false;
        }

        try {
            return AiAccess::for($user)->provider()->supports(Capability::OauthKeyHandover);
        } catch (\Throwable) {
            // Configuration inexploitable : pas de bouton plutôt qu'une page en
            // erreur. Le message d'indisponibilité, lui, est déjà affiché.
            return false;
        }
    }

    /**
     * Pose le vérificateur en session et renvoie l'URL d'autorisation.
     *
     * @throws AiOauthFailed  Fournisseur sans remise de clé (garde de dernier recours).
     */
    public function begin(User $user, string $callbackUrl): string
    {
        $provider = AiAccess::for($user)->provider();
        $authorize = $provider->authorizeUrl();

        if ($authorize === null) {
            throw AiOauthFailed::refusedByProvider();
        }

        $verifier = self::newVerifier();

        session()->put(self::SESSION_KEY, [
            'verifier' => $verifier,
            'user_id' => $user->id,
            'issued_at' => now()->timestamp,
        ]);

        return $authorize . '?' . http_build_query([
            'callback_url' => $callbackUrl,
            'code_challenge' => self::challenge($verifier),
            'code_challenge_method' => 'S256',
        ]);
    }

    /**
     * Échange le code contre la clé de l'utilisateur, et l'enregistre.
     *
     * Le vérificateur est CONSOMMÉ avant tout appel réseau : pas de rejeu, et
     * aucun oracle à codes. C'est aussi ce qui fait échouer une injection de
     * clé — un code obtenu par un tiers a été émis contre SON défi, pas contre
     * le vérificateur de la session de la victime, et l'échange est refusé.
     *
     * @throws AiOauthFailed
     */
    public function exchange(User $user, ?string $code): void
    {
        $started = microtime(true);
        $pending = session()->pull(self::SESSION_KEY);

        try {
            $this->assertPendingIsValid($user, $code, $pending);

            $key = $this->requestKey(
                AiAccess::for($user)->provider()->keyExchangeUrl(),
                (string) $code,
                (string) $pending['verifier'],
            );

            $user->forceFill(['ai_api_key' => $key])->save();
        } catch (AiOauthFailed $e) {
            AiUsageLog::recordKeyHandover($user, 'error', $e->code(), $this->elapsedMs($started));

            throw $e;
        }

        AiUsageLog::recordKeyHandover($user, 'ok', durationMs: $this->elapsedMs($started));
    }

    /**
     * Refus qui n'émettent AUCUNE requête sortante.
     *
     * @param  mixed  $pending
     *
     * @throws AiOauthFailed
     */
    private function assertPendingIsValid(User $user, ?string $code, mixed $pending): void
    {
        if (blank($code)) {
            throw AiOauthFailed::cancelled();
        }

        if (! is_array($pending) || blank($pending['verifier'] ?? null)) {
            throw AiOauthFailed::noPendingRequest();
        }

        if ((int) ($pending['user_id'] ?? 0) !== $user->id) {
            throw AiOauthFailed::otherAccount();
        }

        if (now()->timestamp - (int) ($pending['issued_at'] ?? 0) > self::PENDING_TTL_SECONDS) {
            throw AiOauthFailed::expired();
        }
    }

    /**
     * Appel d'échange.
     *
     * Sans en-tête d'autorisation : on n'a pas encore de clé, et réutiliser le
     * client d'AiClient enverrait un « Bearer » vide.
     *
     * @throws AiOauthFailed
     */
    private function requestKey(?string $url, string $code, #[SensitiveParameter] string $verifier): string
    {
        if ($url === null) {
            throw AiOauthFailed::refusedByProvider();
        }

        try {
            $response = Http::asJson()
                ->acceptJson()
                ->timeout((int) config('ai.timeout', 60))
                ->post($url, [
                    'code' => $code,
                    'code_verifier' => $verifier,
                    'code_challenge_method' => 'S256',
                ]);
        } catch (ConnectionException) {
            throw AiOauthFailed::unreachable();
        }

        if (! $response->successful()) {
            throw AiOauthFailed::refusedByProvider();
        }

        $key = $response->json('key');

        if (! is_string($key)) {
            throw AiOauthFailed::unusableKey();
        }

        $key = trim($key);

        // Une valeur trop longue serait tronquée en base — donc inutilisable —
        // et une valeur non imprimable trahit une réponse qui n'est pas une clé.
        if ($key === '' || mb_strlen($key) > self::MAX_KEY_LENGTH || preg_match('/[^\P{C}]/u', $key)) {
            throw AiOauthFailed::unusableKey();
        }

        return $key;
    }

    /**
     * Avertissement si le retour ne se fera ni en HTTPS ni sur localhost.
     *
     * Le fournisseur ne garantit que ces deux cas : on prévient sans bloquer,
     * la saisie manuelle restant juste en dessous du bouton.
     */
    public static function insecureCallbackWarning(string $callbackUrl): ?string
    {
        $parts = parse_url($callbackUrl);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));

        if ($scheme === 'https' || in_array($host, ['localhost', '127.0.0.1', '[::1]'], strict: true)) {
            return null;
        }

        return "Cette instance est servie en HTTP sur {$host}. OpenRouter ne garantit le retour que vers "
            . "une adresse sécurisée (HTTPS) ou vers localhost : si la redirection échoue, saisissez "
            . "votre clé dans le champ ci-dessous.";
    }

    /** Vérificateur conforme à la RFC 7636 : 43 à 128 caractères base64url. */
    private static function newVerifier(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(64)), '+/', '-_'), '=');
    }

    private static function challenge(#[SensitiveParameter] string $verifier): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $verifier, binary: true)), '+/', '-_'), '=');
    }

    private function elapsedMs(float $started): int
    {
        return (int) round((microtime(true) - $started) * 1000);
    }
}
