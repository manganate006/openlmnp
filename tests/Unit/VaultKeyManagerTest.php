<?php

use App\Models\DocumentVault;
use App\Models\User;
use App\Services\Vault\VaultException;
use App\Services\Vault\VaultKeyManager;
use Illuminate\Support\Facades\DB;

// L'enveloppe DEK / KEK. Ce qui doit rester vrai quoi qu'il arrive : la phrase de
// passe n'est stockée nulle part, une mauvaise saisie est refusée, et en changer
// ne réécrit aucun fichier.

beforeEach(function () {
    $this->keys = app(VaultKeyManager::class);
    $this->user = User::factory()->create();
});

it('creates a vault without storing the passphrase anywhere', function () {
    [$vault, $dek] = $this->keys->create($this->user, 'une phrase de passe solide');

    expect($vault)->toBeInstanceOf(DocumentVault::class)
        ->and(strlen($dek))->toBe(SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES);

    // Aucune colonne ne doit contenir la phrase de passe, ni en clair ni sous une
    // forme qui permettrait de la retrouver : le seul vérificateur est l'échec du
    // descellement authentifié.
    $row = (array) DB::table('document_vaults')->where('user_id', $this->user->id)->first();

    foreach ($row as $value) {
        expect((string) $value)->not->toContain('une phrase de passe solide');
    }

    expect($row['dek_fingerprint'])->toBe($this->keys->fingerprint($dek))
        ->and($row['kdf_alg'])->toBe(SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13);
});

it('unseals the same data key when the passphrase is right', function () {
    [, $dek] = $this->keys->create($this->user, 'une phrase de passe solide');

    expect($this->keys->unlock($this->user, 'une phrase de passe solide'))->toBe($dek);
});

it('refuses a wrong passphrase without revealing anything', function () {
    $this->keys->create($this->user, 'une phrase de passe solide');

    expect(fn () => $this->keys->unlock($this->user, 'une phrase de passe SOLIDE'))
        ->toThrow(VaultException::class, 'Phrase de passe incorrecte.');
});

it('rejects a passphrase that is too short to be worth deriving', function () {
    expect(fn () => $this->keys->create($this->user, 'court'))
        ->toThrow(VaultException::class);

    expect(DocumentVault::where('user_id', $this->user->id)->exists())->toBeFalse();
});

it('reseals the data key on a passphrase change, without changing the key itself', function () {
    [, $dek] = $this->keys->create($this->user, 'une phrase de passe solide');
    $sealedBefore = DocumentVault::where('user_id', $this->user->id)->value('wrapped_dek');

    $this->keys->changePassphrase($this->user, 'une phrase de passe solide', 'une autre phrase de passe');

    $vault = DocumentVault::where('user_id', $this->user->id)->first();

    // La DEK est inchangée — c'est ce qui permet de ne réécrire AUCUN fichier —
    // mais son scellement, lui, est différent.
    expect($this->keys->unlock($this->user, 'une autre phrase de passe'))->toBe($dek)
        ->and($vault->wrapped_dek)->not->toBe($sealedBefore)
        ->and($vault->dek_fingerprint)->toBe($this->keys->fingerprint($dek))
        ->and($vault->key_version)->toBe(1);

    expect(fn () => $this->keys->unlock($this->user, 'une phrase de passe solide'))
        ->toThrow(VaultException::class);
});

it('refuses to change the passphrase without the current one', function () {
    $this->keys->create($this->user, 'une phrase de passe solide');

    expect(fn () => $this->keys->changePassphrase($this->user, 'mauvaise phrase actuelle', 'une autre phrase de passe'))
        ->toThrow(VaultException::class, 'Phrase de passe incorrecte.');

    expect($this->keys->unlock($this->user, 'une phrase de passe solide'))->toBeString();
});

it('keeps unsealing a vault created under another derivation profile', function () {
    // Les paramètres Argon2id sont figés par utilisateur à l'activation : durcir
    // le profil de l'instance ne doit jamais rendre un coffre existant illisible.
    config()->set('vault.kdf', 'test');
    [, $dek] = $this->keys->create($this->user, 'une phrase de passe solide');

    config()->set('vault.kdf', 'interactive');

    expect($this->keys->unlock($this->user, 'une phrase de passe solide'))->toBe($dek);
});

it('validates a data key coming from the session cookie without the passphrase', function () {
    [$vault, $dek] = $this->keys->create($this->user, 'une phrase de passe solide');

    expect($this->keys->matchesFingerprint($vault, $dek))->toBeTrue()
        ->and($this->keys->matchesFingerprint($vault, sodium_crypto_secretstream_xchacha20poly1305_keygen()))->toBeFalse();
});

it('drops the vault on destroy, leaving nothing to unseal', function () {
    $this->keys->create($this->user, 'une phrase de passe solide');
    $this->keys->destroy($this->user);

    expect($this->keys->isEnabled($this->user))->toBeFalse();
});

it('removes the vault when the account is deleted', function () {
    $this->keys->create($this->user, 'une phrase de passe solide');
    $this->user->delete();

    expect(DB::table('document_vaults')->count())->toBe(0);
});
