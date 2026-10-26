<?php

namespace App\Support;

use App\Models\DocumentVault;
use App\Models\User;
use App\Services\Vault\VaultKeyManager;
use Illuminate\Support\Facades\Cookie;

/**
 * Résidence de la clé de données pendant la session.
 *
 * La DEK descellée vit dans un COOKIE chiffré par Laravel, jamais dans la
 * session serveur : le pilote de session écrit en clair sur le disque, celui-là
 * même dont le coffre protège l'utilisateur. Un instantané du disque, la
 * réplique de secours et les sauvegardes ne contiennent donc aucun matériel de
 * clé. Côté serveur, la clé n'existe qu'en mémoire, le temps de la requête.
 *
 * Ce que ce choix ne protège PAS : un attaquant capable d'exécuter du code sur
 * le serveur pendant que l'utilisateur est déverrouillé voit la clé passer. Le
 * coffre protège la donnée au repos, pas l'exécution — ce n'est pas du « zéro
 * connaissance » et ne doit jamais être présenté comme tel.
 *
 * ⚠️ La résolution est PARESSEUSE, volontairement : elle n'est pas faite par un
 * middleware. Un middleware global s'exécuterait AVANT `EncryptCookies` (les
 * middlewares globaux passent avant ceux des groupes) et lirait donc une valeur
 * encore chiffrée ; et un middleware de groupe raterait l'autre pile, le panel
 * Filament ne passant pas par le groupe « web » alors que la route de service
 * des fichiers `/d/{path}`, si. En lisant le cookie au premier usage réel, on
 * est correct dans les deux piles sans dépendre d'un ordre d'exécution.
 *
 * ⚠️ UN COOKIE PAR UTILISATEUR (lot 3 des dossiers liés, plan § 2.3 et § 10).
 * Le NOM du cookie porte l'identifiant de l'utilisateur concerné
 * (`cookieNameFor()`) : sans ça, un seul nom de cookie partagé par toute
 * l'instance ne pouvait porter qu'UNE SEULE clé déverrouillée à la fois par
 * navigateur. Un dossier lié étant authentifié dans la MÊME session navigateur
 * que son principal (bascule, jamais une seconde session), déverrouiller le
 * coffre du dossier réécrivait alors le cookie du principal — et revenir
 * ensuite au principal (`DossierContext::leave()`) le trouvait reverrouillé,
 * alors que rien n'avait changé côté PRINCIPAL. Avec un cookie par utilisateur,
 * les deux coexistent : chacun garde sa clé déverrouillée tant que sa propre
 * fenêtre glissante ne l'a pas expirée, quel que soit le dossier actif entre
 * les deux. Aucun affaiblissement : le nom n'est qu'un index (le contenu reste
 * chiffré par Laravel et l'empreinte `u`/`v` à l'intérieur reste vérifiée).
 */
class VaultSession
{
    /** @var array<int, string|null> clé déjà résolue, par id utilisateur */
    private array $keys = [];

    /** @var array<int, bool> */
    private array $resolved = [];

    public function __construct(private VaultKeyManager $keyManager) {}

    /** Nom du cookie PROPRE à cet utilisateur — jamais un nom partagé entre comptes. */
    public function cookieNameFor(User $user): string
    {
        return (string) config('vault.cookie.name', 'olmnp_vault_key').'_'.$user->id;
    }

    /**
     * Dépose la clé pour la suite de la navigation.
     *
     * ⚠️ Le cookie n'est lisible qu'à la requête SUIVANTE : l'action de
     * déverrouillage doit rafraîchir la page, pas tenter de servir un aperçu
     * dans la même requête.
     */
    public function unlock(User $user, string $dek, int $keyVersion): void
    {
        $this->keys[$user->id] = $dek;
        $this->resolved[$user->id] = true;

        Cookie::queue($this->makeCookie($user, $dek, $keyVersion));
    }

    /** Verrouille le coffre de CET utilisateur — jamais celui d'un autre identifiant actif. */
    public function lock(User $user): void
    {
        $this->keys[$user->id] = null;
        $this->resolved[$user->id] = true;

        Cookie::queue(Cookie::forget($this->cookieNameFor($user)));
    }

    public function isUnlockedFor(User $user): bool
    {
        return $this->keyFor($user) !== null;
    }

    /**
     * Clé de données de l'utilisateur, ou null si le coffre est verrouillé,
     * absent, ou si le cookie ne lui appartient pas.
     */
    public function keyFor(User $user): ?string
    {
        if ($this->resolved[$user->id] ?? false) {
            return $this->keys[$user->id] ?? null;
        }

        $this->resolved[$user->id] = true;
        $this->keys[$user->id] = $this->readCookie($user);

        return $this->keys[$user->id];
    }

    /** Clé de l'utilisateur connecté, sans en exiger un. */
    public function currentKey(): ?string
    {
        $user = auth()->user();

        return $user instanceof User ? $this->keyFor($user) : null;
    }

    /** Réinitialise la mémorisation (tests, changement d'utilisateur). */
    public function flush(): void
    {
        $this->keys = [];
        $this->resolved = [];
    }

    private function readCookie(User $user): ?string
    {
        $raw = request()->cookie($this->cookieNameFor($user));

        if (! is_string($raw) || $raw === '') {
            return null;
        }

        $payload = json_decode($raw, true);

        if (! is_array($payload) || ! isset($payload['u'], $payload['k'], $payload['v'], $payload['e'])) {
            return null;
        }

        // Le cookie appartient-il bien à l'utilisateur connecté ? Sans ce contrôle,
        // un cookie laissé par une session précédente sur le même navigateur
        // servirait à chiffrer avec une clé qui n'est pas celle du compte.
        if ((int) $payload['u'] !== $user->id) {
            return null;
        }

        // Fenêtre d'inactivité côté serveur, en plus de la durée de vie du
        // navigateur : le cookie est un cookie de session, il meurt aussi à la
        // fermeture du navigateur.
        if ((int) $payload['e'] < now()->getTimestamp()) {
            return null;
        }

        $dek = base64_decode((string) $payload['k'], true);

        if ($dek === false || $dek === '') {
            return null;
        }

        $vault = $this->keyManager->vaultFor($user);

        // Le coffre a pu être désactivé, ou la clé changée, depuis la pose du
        // cookie : on refuse plutôt que de chiffrer avec une clé fantaisiste.
        if ($vault === null
            || $vault->key_version !== (int) $payload['v']
            || ! $this->keyManager->matchesFingerprint($vault, $dek)) {
            return null;
        }

        // Fenêtre glissante : chaque usage repousse l'expiration.
        Cookie::queue($this->makeCookie($user, $dek, $vault->key_version));

        return $dek;
    }

    private function makeCookie(User $user, string $dek, int $keyVersion): \Symfony\Component\HttpFoundation\Cookie
    {
        $lifetime = max(1, (int) config('vault.cookie.lifetime', 120));

        $payload = json_encode([
            'u' => $user->id,
            'k' => base64_encode($dek),
            'v' => $keyVersion,
            'e' => now()->addMinutes($lifetime)->getTimestamp(),
        ]);

        return cookie(
            name: $this->cookieNameFor($user),
            value: $payload,
            // 0 = cookie de session : il disparaît à la fermeture du navigateur.
            minutes: 0,
            path: config('session.path', '/'),
            domain: config('session.domain'),
            // Repris de la session, avec un cran de plus : `session.secure` vaut
            // `null` tant que SESSION_SECURE_COOKIE n'est pas renseignée, et un
            // cookie sans le drapeau partirait en clair si la même origine
            // répondait un jour en HTTP. On le force donc dès que l'application
            // s'annonce en https, sans casser l'auto-hébergement servi en HTTP sur
            // un réseau local — où forcer `true` rendrait le coffre inutilisable
            // sans le moindre message d'erreur.
            secure: (bool) (config('session.secure')
                ?? str_starts_with((string) config('app.url'), 'https://')),
            httpOnly: true,
            // Aligné sur la session. En « strict », une arrivée depuis un lien
            // externe enverrait le cookie de session mais pas celui du coffre :
            // la première page s'afficherait verrouillée, la suivante non.
            sameSite: config('session.same_site', 'lax'),
        );
    }
}
