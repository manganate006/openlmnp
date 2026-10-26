<?php

use App\Models\User;
use App\Services\Vault\DocumentCipher;
use App\Services\Vault\VaultKeyManager;
use App\Support\Vault;

// La promesse du chantier : coffre VERROUILLÉ, un dépôt réussit ET le fichier reste
// illisible. Un test qui ne vérifie que le premier membre ne mesure rien du tout.

beforeEach(function () {
    config()->set('vault.kdf', 'test');
    $this->user = User::factory()->create();
    app(VaultKeyManager::class)->create($this->user, 'une phrase de passe solide');
    $this->actingAs($this->user);
});

it('autorise l\'écriture coffre verrouillé, et pas la lecture', function () {
    // Aucune session déverrouillée : on n'a que la clé publique.
    expect(Vault::isUnlockedFor($this->user))->toBeFalse()
        ->and(Vault::canWriteFor($this->user))->toBeTrue()
        ->and(Vault::publicKeyFor($this->user))->not->toBeNull()
        // Et rien ne doit plus prétendre que le dépôt est impossible.
        ->and(Vault::blockedReasonFor($this->user))->toBeNull();
});

it('chiffre avec la seule clé publique un fichier que l\'instance ne peut pas relire', function () {
    $public = Vault::publicKeyFor($this->user);
    $cipher = new DocumentCipher(1024);

    $clair = '%PDF-1.4 facture confidentielle';
    $in = fopen('php://temp', 'w+b');
    fwrite($in, $clair);
    rewind($in);
    $out = fopen('php://temp', 'w+b');

    // Exactement ce que fait le dépôt : clé neuve, scellée à la clé publique.
    $cipher->encrypt($in, $out, sodium_crypto_secretstream_xchacha20poly1305_keygen(), 1, null, $public);
    rewind($out);
    $chiffre = stream_get_contents($out);

    expect($chiffre)->not->toContain('%PDF')
        ->and($cipher->isEncryptedContent($chiffre))->toBeTrue();

    // Et c'est le point : avec la phrase de passe, ça se relit.
    [, $prive] = app(VaultKeyManager::class)->unlockPair($this->user, 'une phrase de passe solide');
    $paire = sodium_crypto_box_keypair_from_secretkey_and_publickey($prive, $public);

    $src = fopen('php://temp', 'w+b');
    fwrite($src, $chiffre);
    rewind($src);
    $dst = fopen('php://temp', 'w+b');
    $cipher->decrypt($src, $dst, $paire);
    rewind($dst);

    expect(stream_get_contents($dst))->toBe($clair);
});

it('refuse encore le dépôt sur un coffre d\'avant la paire de clés', function () {
    // Un coffre `key_version = 1` sans paire : la DEK reste nécessaire dans les
    // deux sens, le refus doit subsister.
    Vault::vaultOf($this->user)->update([
        'public_key' => null,
        'wrapped_secret_key' => null,
        'secret_key_nonce' => null,
    ]);

    expect(Vault::canWriteFor($this->user))->toBeFalse()
        ->and(Vault::blockedReasonFor($this->user))->toContain('verrouillé');
});

it('laisse l\'API MCP déposer, mais jamais exporter', function () {
    // Le dépôt redevient possible : l'agent chiffre sans rien pouvoir relire.
    expect(Vault::canWriteFor($this->user))->toBeTrue();

    // L'export, lui, produirait une archive EN CLAIR sur le disque du serveur :
    // il reste refusé, et ce refus n'est pas un oubli.
    $export = file_get_contents(base_path('app/Mcp/Tools/ExportDocuments.php'));

    expect($export)->toContain('Vault::isEnabledFor');
});
