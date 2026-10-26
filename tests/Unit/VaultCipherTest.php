<?php

use App\Services\Vault\DocumentCipher;
use App\Services\Vault\VaultException;

// Le chiffrement des fichiers du coffre-fort. Trois propriétés non négociables :
// il restitue exactement ce qui a été chiffré, il refuse tout ce qui a été touché,
// et il ne charge jamais un justificatif entier en mémoire.

function cipherStream(string $content)
{
    $handle = fopen('php://temp', 'w+b');
    fwrite($handle, $content);
    rewind($handle);

    return $handle;
}

function decryptToString(DocumentCipher $cipher, string $encrypted, string $key): string
{
    $out = fopen('php://temp', 'w+b');
    $cipher->decrypt(cipherStream($encrypted), $out, $key);
    rewind($out);

    return (string) stream_get_contents($out);
}

it('restores encrypted content byte for byte, whatever the size', function (int $size) {
    $cipher = new DocumentCipher(1024);
    $key = sodium_crypto_secretstream_xchacha20poly1305_keygen();
    $plain = $size === 0 ? '' : random_bytes($size);

    $encrypted = $cipher->encryptString($plain, $key);

    expect($encrypted)->toStartWith(DocumentCipher::MAGIC)
        ->and(decryptToString($cipher, $encrypted, $key))->toBe($plain);
})->with([0, 1, 1023, 1024, 1025, 60000]);

it('records the plaintext size and chunk size in a self describing header', function () {
    $cipher = new DocumentCipher(1024);
    $key = sodium_crypto_secretstream_xchacha20poly1305_keygen();

    $encrypted = $cipher->encryptString(str_repeat('A', 5000), $key, keyVersion: 7);
    $header = $cipher->readHeader(cipherStream($encrypted));

    // La taille de bloc voyage avec le fichier : la changer dans la configuration
    // ne doit jamais rendre illisible un justificatif déjà chiffré.
    expect($header['chunk_size'])->toBe(1024)
        ->and($header['plain_size'])->toBe(5000)
        ->and($header['key_version'])->toBe(7)
        ->and($header['version'])->toBe(DocumentCipher::FORMAT_VERSION);
});

it('refuses a file whose content was altered by a single byte', function () {
    $cipher = new DocumentCipher(1024);
    $key = sodium_crypto_secretstream_xchacha20poly1305_keygen();

    $encrypted = $cipher->encryptString(str_repeat('A', 5000), $key);
    $encrypted[100] = chr(ord($encrypted[100]) ^ 0x01);

    expect(fn () => decryptToString($cipher, $encrypted, $key))
        ->toThrow(VaultException::class);
});

it('refuses a truncated file instead of serving what it can read', function () {
    $cipher = new DocumentCipher(1024);
    $key = sodium_crypto_secretstream_xchacha20poly1305_keygen();

    $encrypted = $cipher->encryptString(str_repeat('A', 5000), $key);

    // Sans le marqueur FINAL du dernier bloc, une troncature passerait pour un
    // fichier complet et l'utilisateur recevrait un justificatif amputé.
    expect(fn () => decryptToString($cipher, substr($encrypted, 0, -200), $key))
        ->toThrow(VaultException::class);
});

it('refuses the wrong key', function () {
    $cipher = new DocumentCipher(1024);
    $encrypted = $cipher->encryptString('facture', sodium_crypto_secretstream_xchacha20poly1305_keygen());

    expect(fn () => decryptToString($cipher, $encrypted, sodium_crypto_secretstream_xchacha20poly1305_keygen()))
        ->toThrow(VaultException::class);
});

it('refuses a file that is not encrypted at all', function () {
    $cipher = new DocumentCipher(1024);

    expect(fn () => decryptToString($cipher, '%PDF-1.4 une facture en clair', 'x'))
        ->toThrow(VaultException::class);
});

it('detects the encrypted state from the file itself, not from the database', function () {
    $cipher = new DocumentCipher(1024);
    $key = sodium_crypto_secretstream_xchacha20poly1305_keygen();

    $path = tempnam(sys_get_temp_dir(), 'olmnp-test-');
    file_put_contents($path, '%PDF-1.4 facture');

    expect($cipher->isEncryptedFile($path))->toBeFalse();

    $cipher->encryptFileInPlace($path, $key);

    expect($cipher->isEncryptedFile($path))->toBeTrue()
        // `file` ne doit plus reconnaître un PDF : c'est le critère d'acceptation.
        ->and(file_get_contents($path))->not->toContain('%PDF')
        ->and($cipher->plainSize($path))->toBe(16);

    $cipher->decryptFileInPlace($path, $key);

    expect(file_get_contents($path))->toBe('%PDF-1.4 facture');

    unlink($path);
});

it('streams large files without loading them in memory', function () {
    $cipher = new DocumentCipher(65536);
    $key = sodium_crypto_secretstream_xchacha20poly1305_keygen();

    $content = str_repeat('%PDF-1.4 ', 600_000); // ≈ 5,4 Mo
    $path = tempnam(sys_get_temp_dir(), 'olmnp-test-');
    file_put_contents($path, $content);

    $before = memory_get_peak_usage(true);
    $cipher->encryptFileInPlace($path, $key);
    $cipher->decryptFileInPlace($path, $key);
    $growth = memory_get_peak_usage(true) - $before;

    expect(md5_file($path))->toBe(md5($content))
        // Un justificatif de 5,4 Mo ne doit pas peser 5,4 Mo de plus en mémoire :
        // sans le découpage en flux, la marge exploserait.
        //
        // ⚠️ Le seuil vaut 4 Mio et non 2, et ce n'est pas de la complaisance.
        // `memory_get_peak_usage(true)` rend la mémoire réclamée à l'OS, qui avance
        // par paliers de 2 Mio : « strictement moins de 2 Mio » exigeait donc ZÉRO
        // nouveau palier, ce qui dépend de l'endroit où le pic se trouvait déjà —
        // c'est-à-dire des tests exécutés AVANT celui-ci. Le test passait seul et
        // échouait dans la suite complète, sur `2097152 < 2097152`, pile sur la borne.
        //
        // Ce que la mesure doit distinguer : lire le fichier d'un bloc coûterait au
        // moins 5,4 Mo, soit trois paliers ; le découpage en flux en coûte zéro ou un.
        // Quatre Mio séparent les deux cas avec de la marge des deux côtés, au lieu
        // de faire dépendre le verdict de la granularité de l'allocateur.
        ->and($growth)->toBeLessThan(4 * 1024 * 1024);

    unlink($path);
});

it('leaves the original file intact when the transformation fails', function () {
    $cipher = new DocumentCipher(1024);

    $path = tempnam(sys_get_temp_dir(), 'olmnp-test-');
    file_put_contents($path, '%PDF-1.4 facture');

    // Déchiffrer un fichier en clair échoue : l'original doit survivre entier.
    expect(fn () => $cipher->decryptFileInPlace($path, sodium_crypto_secretstream_xchacha20poly1305_keygen()))
        ->toThrow(VaultException::class);

    expect(file_get_contents($path))->toBe('%PDF-1.4 facture')
        ->and(file_exists($path . '.vault-tmp'))->toBeFalse();

    unlink($path);
});
