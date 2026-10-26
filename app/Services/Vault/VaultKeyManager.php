<?php

namespace App\Services\Vault;

use App\Models\DocumentVault;
use App\Models\User;
use SodiumException;

/**
 * Gestion des clés du coffre-fort : enveloppe DEK / KEK.
 *
 * Une clé de données aléatoire (DEK) chiffre les fichiers. Elle est elle-même
 * scellée par une clé (KEK) dérivée de la phrase de passe par Argon2id. Deux
 * conséquences voulues :
 *
 *  - changer de phrase de passe ne rescelle QUE la DEK : aucun fichier n'est
 *    réécrit, l'opération est instantanée quel que soit le volume ;
 *  - la phrase de passe n'est stockée nulle part. Une saisie erronée fait
 *    échouer le descellement authentifié, ce qui suffit à la refuser sans
 *    conserver le moindre vérificateur dérivé du secret.
 */
class VaultKeyManager
{
    public const MIN_PASSPHRASE_LENGTH = 12;

    /** Le coffre est-il proposable sur cette instance ? */
    public function instanceEnabled(): bool
    {
        return (bool) config('vault.enabled', true);
    }

    public function vaultFor(User $user): ?DocumentVault
    {
        return DocumentVault::where('user_id', $user->id)->first();
    }

    public function isEnabled(User $user): bool
    {
        return $this->vaultFor($user) !== null;
    }

    /**
     * Crée le coffre et retourne la DEK en clair, pour que l'appelant puisse
     * enchaîner sur le chiffrement de l'existant sans redemander la phrase.
     *
     * @return array{0: DocumentVault, 1: string}
     */
    public function create(User $user, string $passphrase): array
    {
        $this->assertPassphraseAcceptable($passphrase);

        if ($this->isEnabled($user)) {
            throw new VaultException('Le coffre-fort est déjà activé sur ce compte.');
        }

        $profile = $this->profile();
        $salt = random_bytes(SODIUM_CRYPTO_PWHASH_SALTBYTES);
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        $dek = sodium_crypto_secretstream_xchacha20poly1305_keygen();
        $kek = $this->deriveKey($passphrase, $salt, $profile['ops'], $profile['mem'], $profile['alg']);

        // Paire X25519 : la clé publique reste en clair et ne permet QUE de
        // chiffrer. C'est elle qui rend le dépôt possible coffre verrouillé.
        $keypair = sodium_crypto_box_keypair();
        $publicKey = sodium_crypto_box_publickey($keypair);
        $secretKey = sodium_crypto_box_secretkey($keypair);

        // ⚠️ Nonce DISTINCT de `$nonce` : deux secrets scellés sous la même KEK
        // avec le même nonce casseraient XSalsa20-Poly1305.
        $secretNonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        $vault = DocumentVault::create([
            'user_id' => $user->id,
            'kdf_salt' => base64_encode($salt),
            'kdf_ops' => $profile['ops'],
            'kdf_mem' => $profile['mem'],
            'kdf_alg' => $profile['alg'],
            'wrapped_dek' => base64_encode(sodium_crypto_secretbox($dek, $nonce, $kek)),
            'wrap_nonce' => base64_encode($nonce),
            'dek_fingerprint' => $this->fingerprint($dek),
            'public_key' => base64_encode($publicKey),
            'wrapped_secret_key' => base64_encode(sodium_crypto_secretbox($secretKey, $secretNonce, $kek)),
            'secret_key_nonce' => base64_encode($secretNonce),
            // ⚠️ Reste à 1, et ce n'est pas un oubli : `key_version` décrit le
            // FORMAT DES FICHIERS, pas la présence d'une paire de clés. Tant que
            // le contenu est chiffré par la DEK, la version ne bouge pas. La
            // passer à 2 ici invalidait toute session déverrouillée
            // (`VaultSession` compare la version du cookie à celle du coffre) et
            // rendait 423 sur des fichiers parfaitement lisibles.
            'key_version' => 1,
            'status' => DocumentVault::STATUS_ACTIVE,
            'enabled_at' => now(),
        ]);

        sodium_memzero($kek);
        sodium_memzero($keypair);
        sodium_memzero($secretKey);

        return [$vault, $dek];
    }

    /**
     * Descelle la DEK. Lève VaultException si la phrase de passe est fausse,
     * sans rien révéler de plus.
     */
    public function unlock(User $user, string $passphrase): string
    {
        $vault = $this->vaultFor($user);

        if ($vault === null) {
            throw new VaultException("Le coffre-fort n'est pas activé sur ce compte.");
        }

        return $this->unwrap($vault, $passphrase);
    }

    /**
     * Descelle la DEK et la clé privée ensemble, pour les appelants qui ont
     * besoin des deux — la lecture d'un fichier au nouveau schéma, et la
     * remigration qui lit à l'ancien pour réécrire au nouveau.
     *
     * @return array{0: string, 1: string|null} [DEK, clé privée]
     */
    public function unlockPair(User $user, string $passphrase): array
    {
        $vault = $this->vaultFor($user);

        if ($vault === null) {
            throw new VaultException("Le coffre-fort n'est pas activé sur ce compte.");
        }

        return $this->unwrapAll($vault, $passphrase);
    }

    /**
     * DEK et paire X25519, pour la seule DÉSACTIVATION du coffre.
     *
     * Un fichier au format 2 n'est relisible qu'avec la clé privée, que l'instance
     * n'a jamais en temps normal. La désactiver, c'est justement demander que tout
     * redevienne lisible par l'hôte : la paire est reconstituée ici, le temps de la
     * requête, et n'est stockée nulle part. Sans elle, un coffre qui contient un seul
     * dépôt postérieur à l'activation ne pouvait plus être désactivé (2026-10-16).
     *
     * @return array{0: string, 1: string|null} [DEK, paire ou null sur un coffre d'avant la paire]
     */
    public function unlockForDecryption(User $user, string $passphrase): array
    {
        [$dek, $secretKey] = $this->unlockPair($user, $passphrase);
        $vault = $this->vaultFor($user);

        if ($secretKey === null || blank($vault?->public_key)) {
            return [$dek, null];
        }

        $pair = sodium_crypto_box_keypair_from_secretkey_and_publickey(
            $secretKey,
            base64_decode($vault->public_key),
        );
        sodium_memzero($secretKey);

        return [$dek, $pair];
    }

    /**
     * Rescelle la DEK sous une nouvelle phrase de passe. Le sel et le profil KDF
     * sont régénérés ; la DEK, elle, ne change pas — donc aucun fichier n'est
     * touché et les sessions déjà déverrouillées restent valides.
     */
    public function changePassphrase(User $user, string $current, string $new): void
    {
        $this->assertPassphraseAcceptable($new);

        $vault = $this->vaultFor($user);

        if ($vault === null) {
            throw new VaultException("Le coffre-fort n'est pas activé sur ce compte.");
        }

        [$dek, $secretKey] = $this->unwrapAll($vault, $current);

        $profile = $this->profile();
        $salt = random_bytes(SODIUM_CRYPTO_PWHASH_SALTBYTES);
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $kek = $this->deriveKey($new, $salt, $profile['ops'], $profile['mem'], $profile['alg']);

        // ⚠️ La clé privée est rescellée sous la NOUVELLE KEK, dans la même
        // écriture. L'oublier laisserait un coffre dont la DEK s'ouvre et dont
        // la clé privée ne s'ouvre plus : la lecture des fichiers au nouveau
        // schéma tomberait, silencieusement, à la prochaine phrase de passe.
        $secretPayload = [];

        if ($secretKey !== null) {
            $secretNonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
            $secretPayload = [
                'wrapped_secret_key' => base64_encode(sodium_crypto_secretbox($secretKey, $secretNonce, $kek)),
                'secret_key_nonce' => base64_encode($secretNonce),
            ];
        }

        $vault->update($secretPayload + [
            'kdf_salt' => base64_encode($salt),
            'kdf_ops' => $profile['ops'],
            'kdf_mem' => $profile['mem'],
            'kdf_alg' => $profile['alg'],
            'wrapped_dek' => base64_encode(sodium_crypto_secretbox($dek, $nonce, $kek)),
            'wrap_nonce' => base64_encode($nonce),
        ]);

        sodium_memzero($kek);
        sodium_memzero($dek);

        if ($secretKey !== null) {
            sodium_memzero($secretKey);
        }
    }

    /**
     * Supprime le coffre. À n'appeler qu'une fois tous les fichiers déchiffrés :
     * sans cette ligne, plus aucune clé ne peut être descellée.
     */
    public function destroy(User $user): void
    {
        DocumentVault::where('user_id', $user->id)->delete();
    }

    /** Empreinte publique d'une DEK : valide une clé sans la phrase de passe. */
    public function fingerprint(string $dek): string
    {
        return bin2hex(sodium_crypto_generichash($dek, '', 16));
    }

    public function matchesFingerprint(DocumentVault $vault, string $dek): bool
    {
        return hash_equals($vault->dek_fingerprint, $this->fingerprint($dek));
    }

    /**
     * Profil Argon2id de l'instance.
     *
     * @return array{ops: int, mem: int, alg: int}
     */
    public function profile(): array
    {
        $name = (string) config('vault.kdf', 'moderate');
        $profiles = (array) config('vault.kdf_profiles', []);
        $profile = $profiles[$name] ?? $profiles['moderate'] ?? ['ops' => 3, 'mem' => 268435456];

        return [
            'ops' => (int) $profile['ops'],
            'mem' => (int) $profile['mem'],
            'alg' => SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13,
        ];
    }

    /**
     * Descelle la DEK **et** la clé privée en une seule dérivation.
     *
     * ⚠️ Une dérivation Argon2id en profil `moderate` alloue 256 Mo et prend
     * ~500 ms : la faire deux fois pour lire deux secrets scellés sous la MÊME
     * clé doublerait le coût et la fenêtre pendant laquelle la KEK vit en
     * mémoire, sans rien apporter.
     *
     * La clé privée vaut `null` sur un coffre créé avant la paire de clés
     * (`key_version = 1`) : ces coffres n'en ont pas, et c'est le seul cas où
     * elle manque légitimement.
     *
     * @return array{0: string, 1: string|null} [DEK, clé privée]
     */
    private function unwrapAll(DocumentVault $vault, string $passphrase): array
    {
        $kek = $this->deriveKey(
            $passphrase,
            base64_decode($vault->kdf_salt),
            $vault->kdf_ops,
            $vault->kdf_mem,
            $vault->kdf_alg,
        );

        try {
            $dek = sodium_crypto_secretbox_open(
                base64_decode($vault->wrapped_dek),
                base64_decode($vault->wrap_nonce),
                $kek,
            );

            // Absente sur un coffre d'avant la paire de clés : c'est un état
            // valide, pas un échec. Une phrase de passe fausse se signale par la
            // DEK qui ne s'ouvre pas, jamais par celle-ci.
            $secretKey = null;

            if (filled($vault->wrapped_secret_key) && filled($vault->secret_key_nonce)) {
                $secretKey = sodium_crypto_secretbox_open(
                    base64_decode($vault->wrapped_secret_key),
                    base64_decode($vault->secret_key_nonce),
                    $kek,
                );

                if ($secretKey === false) {
                    $secretKey = null;
                }
            }
        } catch (SodiumException) {
            $dek = false;
            $secretKey = null;
        } finally {
            sodium_memzero($kek);
        }

        if ($dek === false) {
            throw VaultException::wrongPassphrase();
        }

        return [$dek, $secretKey];
    }

    private function unwrap(DocumentVault $vault, string $passphrase): string
    {
        return $this->unwrapAll($vault, $passphrase)[0];
    }

    private function deriveKey(string $passphrase, string $salt, int $ops, int $mem, int $alg): string
    {
        try {
            return sodium_crypto_pwhash(
                SODIUM_CRYPTO_SECRETBOX_KEYBYTES,
                $passphrase,
                $salt,
                $ops,
                $mem,
                $alg,
            );
        } catch (SodiumException) {
            // Paramètres illisibles ou sel corrompu : on refuse comme une mauvaise
            // saisie plutôt que de laisser fuir un détail d'implémentation.
            throw VaultException::wrongPassphrase();
        }
    }

    private function assertPassphraseAcceptable(string $passphrase): void
    {
        if (mb_strlen($passphrase) < self::MIN_PASSPHRASE_LENGTH) {
            throw new VaultException(
                'La phrase de passe doit compter au moins ' . self::MIN_PASSPHRASE_LENGTH . ' caractères.'
            );
        }
    }
}
