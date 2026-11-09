<?php

namespace App\Services\Vault;

use SodiumException;

/**
 * Chiffrement authentifié des fichiers du coffre-fort, en flux.
 *
 * Algorithme : crypto_secretstream_xchacha20poly1305 (libsodium). Le clair est
 * découpé en blocs de taille fixe, chacun authentifié : une altération d'un seul
 * octet est détectée au déchiffrement, et le dernier bloc porte le marqueur FINAL,
 * ce qui rend une troncature détectable elle aussi. Rien n'est jamais servi
 * silencieusement à la place du contenu d'origine.
 *
 * Le fichier chiffré porte un en-tête auto-descriptif de 46 octets :
 *
 *   magic « OLMNPENC »          8 o   détection sans consulter la base
 *   version du format           1 o
 *   version de la clé           1 o   permet une rotation future de la DEK
 *   taille de bloc              4 o   big endian — un changement de config ne
 *                                     rend jamais illisible un fichier existant
 *   taille du clair             8 o   big endian — sert le Content-Length exact
 *   en-tête secretstream       24 o
 *
 * Le chemin et l'extension du fichier ne changent pas : seul le contenu est
 * remplacé. C'est ce qui rend la migration idempotente (le magic est la source
 * de vérité de l'état chiffré) et la désactivation réversible à l'octet près.
 */
class DocumentCipher
{
    public const MAGIC = 'OLMNPENC';

    /**
     * Version écrite quand la clé est symétrique (la DEK) : le format historique.
     *
     * ⚠️ Ce n'est PAS « la version courante ». Ce qu'on écrit dépend de la
     * présence d'une clé publique — 1 sans elle, 2 avec. Confondre les deux a
     * cassé un test qui affirmait « l'en-tête annonce la version courante » : il
     * disait vrai tant qu'il n'y avait qu'un format.
     */
    public const FORMAT_VERSION = 1;

    /** Version écrite quand la clé du fichier est scellée à une clé publique. */
    public const FORMAT_VERSION_SEALED = 2;

    /** Version la plus élevée que ce code sait LIRE. */
    public const FORMAT_VERSION_MAX = 2;

    /** magic(8) + version(1) + key_version(1) + chunk_size(4) + plain_size(8) + header(24) */
    public const HEADER_BYTES = 46;

    /**
     * Format 2 : l'en-tête porte en plus la clé de CE fichier, scellée à la clé
     * publique du coffre (`crypto_box_seal` = 32 o de clé + 48 o de scellement).
     *
     * C'est ce qui permet d'écrire sans rien pouvoir relire : le scellement ne
     * demande que la clé publique, l'ouverture demande la clé privée. Un format 1
     * reste lisible tel quel — sa clé est la DEK, elle n'est pas dans le fichier.
     */
    public const SEALED_KEY_BYTES = SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES + SODIUM_CRYPTO_BOX_SEALBYTES;

    public const HEADER_BYTES_V2 = self::HEADER_BYTES + self::SEALED_KEY_BYTES;

    public function __construct(private ?int $chunkSize = null) {}

    /**
     * Un fichier est-il chiffré par le coffre ? Lecture des 8 premiers octets
     * seulement : la détection reste bon marché sur des milliers de fichiers.
     */
    public function isEncryptedFile(string $absolutePath): bool
    {
        if (! is_file($absolutePath) || filesize($absolutePath) < self::HEADER_BYTES) {
            return false;
        }

        $handle = @fopen($absolutePath, 'rb');
        if ($handle === false) {
            return false;
        }

        try {
            return fread($handle, strlen(self::MAGIC)) === self::MAGIC;
        } finally {
            fclose($handle);
        }
    }

    public function isEncryptedContent(string $content): bool
    {
        return str_starts_with($content, self::MAGIC);
    }

    /**
     * Taille du clair annoncée par l'en-tête, sans déchiffrer quoi que ce soit.
     * Sert à poser un Content-Length exact sur une réponse en flux.
     */
    public function plainSize(string $absolutePath): ?int
    {
        $handle = @fopen($absolutePath, 'rb');
        if ($handle === false) {
            return null;
        }

        try {
            return $this->readHeader($handle)['plain_size'];
        } catch (VaultException) {
            return null;
        } finally {
            fclose($handle);
        }
    }

    /**
     * Chiffre $source vers $destination. Retourne le nombre d'octets de clair traités.
     *
     * @param  resource  $source
     * @param  resource  $destination
     */
    public function encrypt($source, $destination, string $key, int $keyVersion = 1, ?int $plainSize = null, ?string $publicKey = null): int
    {
        $chunkSize = $this->chunkSize();
        $plainSize ??= $this->streamSize($source);

        // Avec une clé publique, on scelle la clé de ce fichier dans l'en-tête :
        // format 2, écriture possible sans jamais pouvoir relire. Sans elle, on
        // reste au format 1, où la clé est la DEK et ne voyage pas.
        $format = $publicKey !== null ? self::FORMAT_VERSION_SEALED : self::FORMAT_VERSION;
        $sealed = $publicKey !== null ? sodium_crypto_box_seal($key, $publicKey) : '';

        [$state, $streamHeader] = sodium_crypto_secretstream_xchacha20poly1305_init_push($key);

        $headerOffset = ftell($destination);

        fwrite($destination, self::MAGIC);
        fwrite($destination, pack('C', $format));
        fwrite($destination, pack('C', $keyVersion));
        fwrite($destination, pack('N', $chunkSize));
        fwrite($destination, pack('J', $plainSize ?? 0));
        fwrite($destination, $streamHeader);

        if ($format === self::FORMAT_VERSION_SEALED) {
            fwrite($destination, $sealed);
        }

        $written = 0;
        $block = $this->readFully($source, $chunkSize);

        while (true) {
            $next = $this->readFully($source, $chunkSize);
            $isLast = $next === '';

            fwrite($destination, sodium_crypto_secretstream_xchacha20poly1305_push(
                $state,
                $block,
                '',
                $isLast
                    ? SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL
                    : SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE,
            ));

            $written += strlen($block);

            if ($isLast) {
                break;
            }

            $block = $next;
        }

        // La taille du clair n'était pas connue d'avance (flux non mesurable) :
        // on repasse l'écrire dans l'en-tête plutôt que d'annoncer 0.
        if ($plainSize === null && $headerOffset !== false) {
            $end = ftell($destination);
            fseek($destination, $headerOffset + 14);
            fwrite($destination, pack('J', $written));
            fseek($destination, $end);
        }

        sodium_memzero($state);

        return $written;
    }

    /**
     * Déchiffre $source vers $destination. Retourne le nombre d'octets de clair écrits.
     *
     * Lève une VaultException si la clé est mauvaise, si un bloc a été altéré ou
     * si le flux s'arrête avant le marqueur FINAL.
     *
     * @param  resource  $source
     * @param  resource  $destination
     */
    /**
     * @param  string  $key  Format 1 : la DEK. Format 2 : la PAIRE X25519
     *                       (`crypto_box_keypair`), qui descelle la clé du fichier.
     */
    public function decrypt($source, $destination, string $key): int
    {
        $header = $this->readHeader($source);
        $streamKey = $this->streamKey($header, $key);

        try {
            $state = sodium_crypto_secretstream_xchacha20poly1305_init_pull($header['stream_header'], $streamKey);
        } catch (SodiumException) {
            throw VaultException::corrupted();
        }

        $blockSize = $header['chunk_size'] + SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES;
        $written = 0;

        while (true) {
            $block = $this->readFully($source, $blockSize);

            if ($block === '') {
                // Flux terminé sans marqueur FINAL : le fichier a été tronqué.
                throw VaultException::corrupted();
            }

            $result = false;

            try {
                $result = sodium_crypto_secretstream_xchacha20poly1305_pull($state, $block);
            } catch (SodiumException) {
                throw VaultException::corrupted();
            }

            if ($result === false) {
                throw VaultException::corrupted();
            }

            [$plain, $tag] = $result;

            fwrite($destination, $plain);
            $written += strlen($plain);

            if ($tag === SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL) {
                break;
            }
        }

        sodium_memzero($state);

        // L'en-tête n'est pas authentifié par le flux : une taille annoncée qui ne
        // correspond pas au clair produit signale une manipulation.
        if ($header['plain_size'] !== $written) {
            throw VaultException::corrupted();
        }

        return $written;
    }

    /**
     * Chiffre un fichier sur place, par un fichier temporaire voisin renommé à la
     * fin : une interruption ne laisse jamais un justificatif à moitié réécrit.
     */
    public function encryptFileInPlace(string $absolutePath, string $key, int $keyVersion = 1): void
    {
        $this->transformInPlace(
            $absolutePath,
            fn ($in, $out) => $this->encrypt($in, $out, $key, $keyVersion, filesize($absolutePath) ?: 0),
        );
    }

    /**
     * @param  string       $key         La DEK, qui ouvre le format 1.
     * @param  string|null  $sealedPair  La paire X25519, qui seule ouvre le format 2. Fournie
     *                                   uniquement par la désactivation du coffre : sans elle,
     *                                   un fichier scellé échoue et reste intact.
     */
    public function decryptFileInPlace(string $absolutePath, string $key, ?string $sealedPair = null): void
    {
        if ($sealedPair !== null && ($this->formatOf($absolutePath) ?? 1) >= self::FORMAT_VERSION_SEALED) {
            $key = $sealedPair;
        }

        $this->transformInPlace(
            $absolutePath,
            fn ($in, $out) => $this->decrypt($in, $out, $key),
        );
    }

    /** Version de format d'un fichier chiffré, ou null s'il est en clair ou illisible. */
    public function formatOf(string $absolutePath): ?int
    {
        $handle = @fopen($absolutePath, 'rb');

        if ($handle === false) {
            return null;
        }

        try {
            $head = fread($handle, strlen(self::MAGIC) + 1);
        } finally {
            fclose($handle);
        }

        if ($head === false || strlen($head) <= strlen(self::MAGIC) || ! str_starts_with($head, self::MAGIC)) {
            return null;
        }

        return ord($head[strlen(self::MAGIC)]);
    }

    /**
     * Déchiffre vers un fichier temporaire distinct (export ZIP, analyse locale).
     * L'appelant est responsable de le supprimer.
     */
    public function decryptToTemporaryFile(string $absolutePath, string $key): string
    {
        $temporary = tempnam(sys_get_temp_dir(), 'olmnp-vault-');

        if ($temporary === false) {
            throw VaultException::unreadable($absolutePath);
        }

        $in = @fopen($absolutePath, 'rb');
        $out = @fopen($temporary, 'wb');

        if ($in === false || $out === false) {
            $in && fclose($in);
            $out && fclose($out);
            @unlink($temporary);
            throw VaultException::unreadable($absolutePath);
        }

        try {
            $this->decrypt($in, $out, $key);
        } catch (VaultException $e) {
            @unlink($temporary);
            throw $e;
        } finally {
            fclose($in);
            fclose($out);
        }

        return $temporary;
    }

    public function encryptString(string $plain, string $key, int $keyVersion = 1, ?string $publicKey = null): string
    {
        $in = fopen('php://temp', 'w+b');
        $out = fopen('php://temp', 'w+b');

        fwrite($in, $plain);
        rewind($in);

        $this->encrypt($in, $out, $key, $keyVersion, strlen($plain), $publicKey);

        rewind($out);
        $cipher = stream_get_contents($out);

        fclose($in);
        fclose($out);

        return $cipher;
    }

    /**
     * Lit et valide l'en-tête maison depuis un flux positionné à son début.
     *
     * @param  resource  $handle
     * @return array{version: int, key_version: int, chunk_size: int, plain_size: int, stream_header: string}
     */
    public function readHeader($handle): array
    {
        $raw = $this->readFully($handle, self::HEADER_BYTES);

        if (strlen($raw) < self::HEADER_BYTES || ! str_starts_with($raw, self::MAGIC)) {
            throw VaultException::notEncrypted();
        }

        $version = unpack('C', substr($raw, 8, 1))[1];

        if ($version > self::FORMAT_VERSION_MAX) {
            throw VaultException::unsupportedFormat($version);
        }

        // Format 2 : la clé du fichier suit l'en-tête, scellée à la clé publique.
        // Elle est lue ici pour que TOUS les appelants la reçoivent sans avoir à
        // connaître la longueur variable de l'en-tête.
        $sealedKey = null;

        if ($version >= self::FORMAT_VERSION_SEALED) {
            $sealedKey = $this->readFully($handle, self::SEALED_KEY_BYTES);

            if (strlen($sealedKey) < self::SEALED_KEY_BYTES) {
                throw VaultException::notEncrypted();
            }
        }

        return [
            'version' => $version,
            'key_version' => unpack('C', substr($raw, 9, 1))[1],
            'chunk_size' => unpack('N', substr($raw, 10, 4))[1],
            'plain_size' => unpack('J', substr($raw, 14, 8))[1],
            'stream_header' => substr($raw, 22, SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES),
            'sealed_key' => $sealedKey,
        ];
    }

    /**
     * Quelle clé ouvre réellement le flux.
     *
     * Format 1 : la DEK, telle quelle. Format 2 : la clé propre au fichier,
     * descellée avec la paire du coffre — c'est là que la lecture exige la clé
     * privée alors que l'écriture s'est contentée de la publique.
     *
     * @param  array{version:int, sealed_key:string|null}  $header
     */
    private function streamKey(array $header, string $key): string
    {
        if (($header['version'] ?? 1) < 2) {
            return $key;
        }

        if ($header['sealed_key'] === null) {
            throw VaultException::corrupted();
        }

        // ⚠️ En format 2, `$key` doit être une PAIRE et non une clé simple : la
        // confusion rendrait une SodiumException opaque là où l'utilisateur
        // attend « coffre verrouillé ».
        if (strlen($key) !== SODIUM_CRYPTO_BOX_KEYPAIRBYTES) {
            throw VaultException::locked();
        }

        $opened = false;

        try {
            $opened = sodium_crypto_box_seal_open($header['sealed_key'], $key);
        } catch (SodiumException) {
            $opened = false;
        }

        if ($opened === false) {
            throw VaultException::corrupted();
        }

        return $opened;
    }

    public function chunkSize(): int
    {
        return $this->chunkSize ?? (int) config('vault.chunk_size', 65536);
    }

    private function transformInPlace(string $absolutePath, callable $transform): void
    {
        $temporary = $absolutePath . '.vault-tmp';

        $in = @fopen($absolutePath, 'rb');
        $out = @fopen($temporary, 'wb');

        if ($in === false || $out === false) {
            $in && fclose($in);
            $out && fclose($out);
            @unlink($temporary);
            throw VaultException::unreadable($absolutePath);
        }

        try {
            $transform($in, $out);
        } catch (\Throwable $e) {
            fclose($in);
            fclose($out);
            @unlink($temporary);
            throw $e;
        }

        fclose($in);
        fclose($out);

        if (! @rename($temporary, $absolutePath)) {
            @unlink($temporary);
            throw VaultException::unreadable($absolutePath);
        }
    }

    /**
     * fread() peut rendre moins que demandé sans être en fin de flux : on boucle
     * pour que le découpage en blocs reste exact, sinon le déchiffrement échoue
     * sur des flux non locaux.
     *
     * @param  resource  $handle
     */
    private function readFully($handle, int $length): string
    {
        $buffer = '';

        while (strlen($buffer) < $length) {
            $piece = fread($handle, $length - strlen($buffer));

            if ($piece === false || $piece === '') {
                break;
            }

            $buffer .= $piece;
        }

        return $buffer;
    }

    /** @param resource $handle */
    private function streamSize($handle): ?int
    {
        $stat = @fstat($handle);

        if (! is_array($stat) || ! isset($stat['size']) || $stat['size'] <= 0) {
            return null;
        }

        $position = ftell($handle);

        return $position === false ? $stat['size'] : max(0, $stat['size'] - $position);
    }
}
