<?php

use App\Services\Vault\DocumentCipher;
use App\Services\Vault\VaultException;

// Format 2 : la clé du fichier voyage DANS le fichier, scellée à la clé publique.
// L'écriture n'exige que la publique, la lecture exige la privée. C'est toute la
// promesse du chantier — un test qui ne vérifie que l'aller-retour ne la mesure pas.

function paire(): array
{
    $kp = sodium_crypto_box_keypair();

    return [$kp, sodium_crypto_box_publickey($kp)];
}

function chiffre(DocumentCipher $c, string $clair, ?string $publicKey): string
{
    $in = fopen('php://temp', 'w+b');
    fwrite($in, $clair);
    rewind($in);
    $out = fopen('php://temp', 'w+b');

    $c->encrypt($in, $out, sodium_crypto_secretstream_xchacha20poly1305_keygen(), 1, null, $publicKey);
    rewind($out);

    return stream_get_contents($out);
}

it('fait un aller-retour octet par octet au format scellé', function () {
    [$kp, $pub] = paire();
    $cipher = new DocumentCipher(1024);
    $clair = random_bytes(5000);

    $chiffre = chiffre($cipher, $clair, $pub);

    $in = fopen('php://temp', 'w+b');
    fwrite($in, $chiffre);
    rewind($in);
    $out = fopen('php://temp', 'w+b');
    $cipher->decrypt($in, $out, $kp);
    rewind($out);

    expect(stream_get_contents($out))->toBe($clair);
});

it('écrit sans la clé privée et refuse de lire sans elle', function () {
    [, $pub] = paire();
    $cipher = new DocumentCipher(1024);

    // Écriture : la clé publique SEULE. C'est le dépôt coffre verrouillé.
    $chiffre = chiffre($cipher, '%PDF-1.4 facture', $pub);

    expect($chiffre)->not->toContain('%PDF');

    // Lecture avec une AUTRE paire : refusée. La clé publique ne suffit jamais.
    [$autre] = paire();
    $in = fopen('php://temp', 'w+b');
    fwrite($in, $chiffre);
    rewind($in);

    expect(fn () => $cipher->decrypt($in, fopen('php://temp', 'w+b'), $autre))
        ->toThrow(VaultException::class);
});

it('annonce le format 2 dans son en-tête, et 1 sans clé publique', function () {
    [, $pub] = paire();
    $cipher = new DocumentCipher(1024);

    $v2 = chiffre($cipher, 'contenu', $pub);
    $v1 = chiffre($cipher, 'contenu', null);

    expect(unpack('C', substr($v2, 8, 1))[1])->toBe(DocumentCipher::FORMAT_VERSION_SEALED)
        ->and(unpack('C', substr($v1, 8, 1))[1])->toBe(DocumentCipher::FORMAT_VERSION)
        // La clé scellée occupe bien 80 octets de plus.
        ->and(strlen($v2) - strlen($v1))->toBe(DocumentCipher::SEALED_KEY_BYTES);
});

it('lit encore un fichier au format 1 avec sa DEK', function () {
    $cipher = new DocumentCipher(1024);
    $dek = sodium_crypto_secretstream_xchacha20poly1305_keygen();

    $in = fopen('php://temp', 'w+b');
    fwrite($in, 'ancienne facture');
    rewind($in);
    $out = fopen('php://temp', 'w+b');
    $cipher->encrypt($in, $out, $dek, 1, null, null);
    rewind($out);

    $clair = fopen('php://temp', 'w+b');
    $cipher->decrypt($out, $clair, $dek);
    rewind($clair);

    // La rétrocompatibilité n'est pas un détail : les coffres déjà activés
    // portent des milliers de fichiers à ce format.
    expect(stream_get_contents($clair))->toBe('ancienne facture');
});

it('refuse une clé simple là où le format 2 attend une paire', function () {
    [, $pub] = paire();
    $cipher = new DocumentCipher(1024);
    $chiffre = chiffre($cipher, 'contenu', $pub);

    $in = fopen('php://temp', 'w+b');
    fwrite($in, $chiffre);
    rewind($in);

    // Confusion clé simple / paire : doit dire « verrouillé », pas lever une
    // exception sodium opaque.
    expect(fn () => $cipher->decrypt($in, fopen('php://temp', 'w+b'), str_repeat("\0", 32)))
        ->toThrow(VaultException::class);
});
