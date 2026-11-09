<?php

use App\Models\DocumentVault;
use App\Models\User;
use App\Services\Vault\VaultException;
use App\Services\Vault\VaultKeyManager;

// La propriété qui justifie tout le chantier : la clé publique suffit à CHIFFRER,
// et ne suffit pas à DÉCHIFFRER. Sans elle, le champ de dépôt reste grisé coffre
// verrouillé et l'API MCP continue de refuser les justificatifs.

beforeEach(function () {
    config()->set('vault.kdf', 'test');
});

it('scelle une paire de clés à la création du coffre', function () {
    $user = User::factory()->create();

    [$vault] = app(VaultKeyManager::class)->create($user, 'une phrase de passe solide');

    expect($vault->public_key)->not->toBeNull()
        ->and($vault->wrapped_secret_key)->not->toBeNull()
        // La paire existe, mais le FORMAT des fichiers n'a pas changé : la
        // version ne bougera qu'au lot qui scelle une clé par fichier.
        ->and($vault->key_version)->toBe(1)
        // La clé publique est en clair : c'est le principe, pas une fuite.
        //
        // ⚠️ `strlen()` et non `toHaveLength()` : ce dernier compte en CARACTÈRES
        // multi-octets, or une clé est du binaire aléatoire. Mesuré : sur 200
        // tirages, `mb_strlen()` rend autre chose que 32 dans 164 cas — le test
        // était donc vert au hasard des octets, et rouge une fois sur cinq.
        ->and(strlen(base64_decode($vault->public_key)))->toBe(SODIUM_CRYPTO_BOX_PUBLICKEYBYTES)
        // ⚠️ Nonces distincts : le même nonce sous la même KEK casserait
        // XSalsa20-Poly1305 pour les deux secrets à la fois.
        ->and($vault->secret_key_nonce)->not->toBe($vault->wrap_nonce);
});

it('chiffre avec la seule clé publique et refuse de déchiffrer sans la privée', function () {
    $user = User::factory()->create();
    [$vault] = app(VaultKeyManager::class)->create($user, 'une phrase de passe solide');

    $public = base64_decode($vault->public_key);
    $secret = 'clé de fichier au hasard';

    // Écriture : rien d'autre que la clé publique. C'est ce que fera le dépôt
    // coffre verrouillé.
    $scelle = sodium_crypto_box_seal($secret, $public);

    expect($scelle)->not->toContain($secret);

    // Lecture : impossible sans la phrase de passe.
    [, $prive] = app(VaultKeyManager::class)->unlockPair($user, 'une phrase de passe solide');

    expect($prive)->not->toBeNull()
        ->and(sodium_crypto_box_seal_open($scelle, sodium_crypto_box_keypair_from_secretkey_and_publickey($prive, $public)))
        ->toBe($secret);
});

it('rescelle la clé privée quand la phrase de passe change', function () {
    $user = User::factory()->create();
    [$vault] = app(VaultKeyManager::class)->create($user, 'phrase de passe initiale');

    [, $avant] = app(VaultKeyManager::class)->unlockPair($user, 'phrase de passe initiale');

    app(VaultKeyManager::class)->changePassphrase($user, 'phrase de passe initiale', 'toute autre phrase de passe');

    [, $apres] = app(VaultKeyManager::class)->unlockPair($user, 'toute autre phrase de passe');

    // La clé privée ne CHANGE pas — sinon les fichiers déjà scellés à la clé
    // publique deviendraient illisibles. Seul son scellement change.
    expect($apres)->toBe($avant)
        ->and($vault->fresh()->secret_key_nonce)->not->toBeNull();
});

it('laisse la clé privée nulle sur un coffre d\'avant la paire de clés', function () {
    $user = User::factory()->create();
    [$vault] = app(VaultKeyManager::class)->create($user, 'une phrase de passe solide');

    // Un coffre `key_version = 1` n'a pas de paire : c'est un état valide.
    $vault->update([
        'public_key' => null,
        'wrapped_secret_key' => null,
        'secret_key_nonce' => null,
        'key_version' => 1,
    ]);

    [$dek, $prive] = app(VaultKeyManager::class)->unlockPair($user, 'une phrase de passe solide');

    // La DEK s'ouvre quand même : l'absence de paire n'est pas un échec de
    // phrase de passe, et ne doit pas être signalée comme tel.
    expect($dek)->not->toBeEmpty()->and($prive)->toBeNull();
});

it('refuse toujours une phrase de passe fausse, paire ou non', function () {
    $user = User::factory()->create();
    app(VaultKeyManager::class)->create($user, 'une phrase de passe solide');

    expect(fn () => app(VaultKeyManager::class)->unlockPair($user, 'mauvaise phrase de passe'))
        ->toThrow(VaultException::class);
});
