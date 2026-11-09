<?php

namespace App\Support;

use App\Models\DocumentVault;
use App\Models\User;
use App\Services\Vault\VaultKeyManager;

/**
 * Point d'entrée unique du coffre-fort pour le reste de l'application.
 *
 * Même rôle que McpDemo pour le mode démo : un seul endroit à auditer pour
 * savoir si les fichiers d'un utilisateur sont chiffrés, si sa clé est
 * disponible, et quel message afficher quand elle ne l'est pas.
 */
class Vault
{
    /** Le coffre est-il proposable sur cette instance ? */
    public static function availableOnInstance(): bool
    {
        return (bool) config('vault.enabled', true);
    }

    /**
     * Le coffre est réservé aux comptes réels. Un bac à sable de démonstration
     * est éphémère et ses identifiants sont publics : y proposer une phrase de
     * passe donnerait une fausse impression de confidentialité.
     */
    public static function canBeEnabledBy(?User $user): bool
    {
        return self::availableOnInstance()
            && $user instanceof User
            && ! $user->isDemo();
    }

    public static function vaultOf(?User $user): ?DocumentVault
    {
        if (! $user instanceof User) {
            return null;
        }

        return app(VaultKeyManager::class)->vaultFor($user);
    }

    public static function isEnabledFor(?User $user): bool
    {
        return self::vaultOf($user) !== null;
    }

    public static function keyFor(?User $user): ?string
    {
        if (! $user instanceof User || ! self::isEnabledFor($user)) {
            return null;
        }

        return app(VaultSession::class)->keyFor($user);
    }

    public static function currentKey(): ?string
    {
        return self::keyFor(auth()->user());
    }

    public static function isUnlockedFor(?User $user): bool
    {
        return self::keyFor($user) !== null;
    }

    /**
     * L'utilisateur peut-il déposer ou consulter un fichier maintenant ?
     * Vrai quand le coffre est absent (cas nominal) ou activé et déverrouillé.
     */
    public static function filesAvailableFor(?User $user): bool
    {
        return self::blockedReasonFor($user) === null;
    }

    /**
     * Message français expliquant pourquoi les fichiers sont indisponibles,
     * ou null s'ils le sont. Jamais de détail technique : ce texte est affiché
     * tel quel à l'utilisateur.
     */
    public static function blockedReasonFor(?User $user): ?string
    {
        $vault = self::vaultOf($user);

        if ($vault === null) {
            return null;
        }

        if ($vault->isMigrating()) {
            return 'Coffre-fort en cours de ' . ($vault->status === DocumentVault::STATUS_ENCRYPTING ? 'chiffrement' : 'déchiffrement')
                . ' : reprenez ou terminez l\'opération depuis la page « Coffre-fort » avant de déposer de nouveaux fichiers.';
        }

        // ⚠️ Verrouillé n'empêche plus d'ÉCRIRE : la clé publique suffit. Ne
        // refuser le dépôt que sur un coffre d'avant la paire de clés, où la DEK
        // reste nécessaire dans les deux sens.
        if (! self::isUnlockedFor($user) && self::publicKeyFor($user) === null) {
            return 'Coffre-fort verrouillé : saisissez votre phrase de passe depuis la page « Coffre-fort » pour déposer un justificatif.';
        }

        return null;
    }

    /** Version de clé à écrire dans l'en-tête des fichiers chiffrés. */
    /**
     * Clé publique du coffre, en clair. Toujours disponible dès que le coffre est
     * activé — verrouillé ou non, c'est tout l'intérêt : elle suffit à CHIFFRER.
     *
     * Rend `null` sur un coffre d'avant la paire de clés, où le dépôt continue
     * d'exiger la DEK.
     */
    public static function publicKeyFor(?User $user): ?string
    {
        $encoded = self::vaultOf($user)?->public_key;

        return filled($encoded) ? base64_decode($encoded) : null;
    }

    /**
     * Le dépôt d'un justificatif est-il possible ?
     *
     * Depuis la paire de clés, il l'est **coffre verrouillé** : écrire ne demande
     * que la clé publique. Seule la lecture réclame la phrase de passe.
     */
    public static function canWriteFor(?User $user): bool
    {
        if (! self::isEnabledFor($user)) {
            return true;
        }

        return self::publicKeyFor($user) !== null || self::isUnlockedFor($user);
    }

    public static function keyVersionFor(?User $user): int
    {
        return self::vaultOf($user)?->key_version ?? 1;
    }
}
