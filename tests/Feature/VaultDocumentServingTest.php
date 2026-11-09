<?php

use App\Models\User;
use App\Services\Vault\DocumentCipher;
use App\Services\Vault\VaultKeyManager;
use App\Support\DocumentStorage;
use App\Support\VaultSession;
use Illuminate\Support\Facades\Storage;

// Le service des justificatifs chiffrés : ce que voit le propriétaire, ce que voit
// quelqu'un d'autre, et ce qui se passe quand la clé n'est pas là.

beforeEach(function () {
    Storage::fake('local');

    $this->user = User::factory()->create();
    [$this->vault, $this->dek] = app(VaultKeyManager::class)->create($this->user, 'une phrase de passe solide');
});

/**
 * Le cookie porteur de clé, tel que VaultSession le pose — UN COOKIE PAR
 * UTILISATEUR depuis le lot 3 des dossiers liés : le nom embarque l'id de
 * `$user`, jamais un nom générique partagé par l'instance entière.
 */
function vaultCookie(User $user, string $dek, int $keyVersion = 1): array
{
    return [app(VaultSession::class)->cookieNameFor($user) => json_encode([
        'u' => $user->id,
        'k' => base64_encode($dek),
        'v' => $keyVersion,
        'e' => now()->addHour()->getTimestamp(),
    ])];
}

/** Dépose un justificatif chiffré et retourne son URL signée. */
function encryptedDocument(User $user, string $dek, string $content, string $name = 'facture.pdf'): string
{
    $path = "documents/{$user->id}/pieces-comptables/{$name}";
    Storage::disk('local')->put($path, $content);
    app(DocumentCipher::class)->encryptFileInPlace(Storage::disk('local')->path($path), $dek);

    return DocumentStorage::temporaryUrl($path);
}

it('serves the decrypted document to its owner when the vault is unlocked', function () {
    $url = encryptedDocument($this->user, $this->dek, '%PDF-1.4 facture EDF de mars');

    $response = $this->actingAs($this->user)
        ->withCookies(vaultCookie($this->user, $this->dek))
        ->get($url);

    $response->assertOk();

    expect($response->streamedContent())->toBe('%PDF-1.4 facture EDF de mars')
        // Type imposé par l'extension : jamais déduit du contenu.
        ->and($response->headers->get('Content-Type'))->toBe('application/pdf')
        // Longueur du CLAIR : sans elle, le navigateur ne sait pas quand s'arrêter.
        ->and($response->headers->get('Content-Length'))->toBe('28')
        ->and($response->headers->get('Accept-Ranges'))->toBe('none');
});

it('answers 423 when the vault is locked instead of serving ciphertext', function () {
    $url = encryptedDocument($this->user, $this->dek, '%PDF-1.4 facture EDF de mars');

    $this->actingAs($this->user)->get($url)->assertStatus(423);
});

it('never serves the file to another user, unlocked or not', function () {
    $url = encryptedDocument($this->user, $this->dek, '%PDF-1.4 facture EDF de mars');
    $intruder = User::factory()->create(['is_admin' => true]);

    // Même administrateur, même avec un cookie de coffre-fort valide pour lui.
    $this->actingAs($intruder)
        ->withCookies(vaultCookie($intruder, $this->dek))
        ->get($url)
        ->assertForbidden();
});

it('ignores a vault cookie that belongs to another account', function () {
    $url = encryptedDocument($this->user, $this->dek, '%PDF-1.4 facture EDF de mars');
    $other = User::factory()->create();

    // Cookie posé sous le NOM de l'utilisateur connecté (celui que le serveur
    // va chercher), mais dont le CONTENU prétend appartenir à quelqu'un
    // d'autre : défense en profondeur de la vérification `u`, en plus de
    // l'isolation déjà apportée par le nom du cookie lui-même (un par
    // utilisateur depuis le lot 3 des dossiers liés).
    $cookie = [app(\App\Support\VaultSession::class)->cookieNameFor($this->user) => json_encode([
        'u' => $other->id,
        'k' => base64_encode($this->dek),
        'v' => 1,
        'e' => now()->addHour()->getTimestamp(),
    ])];

    $this->actingAs($this->user)
        ->withCookies($cookie)
        ->get($url)
        ->assertStatus(423);
});

it('ignores an expired vault cookie', function () {
    $url = encryptedDocument($this->user, $this->dek, '%PDF-1.4 facture');

    $cookie = [app(VaultSession::class)->cookieNameFor($this->user) => json_encode([
        'u' => $this->user->id,
        'k' => base64_encode($this->dek),
        'v' => 1,
        'e' => now()->subMinute()->getTimestamp(),
    ])];

    $this->actingAs($this->user)->withCookies($cookie)->get($url)->assertStatus(423);
});

it('ignores a cookie carrying a key that is not this vault key', function () {
    $url = encryptedDocument($this->user, $this->dek, '%PDF-1.4 facture');

    $this->actingAs($this->user)
        ->withCookies(vaultCookie($this->user, sodium_crypto_secretstream_xchacha20poly1305_keygen()))
        ->get($url)
        ->assertStatus(423);
});

it('still serves plaintext documents of accounts without a vault', function () {
    $plain = User::factory()->create();
    $path = "documents/{$plain->id}/pieces-comptables/quittance.pdf";
    Storage::disk('local')->put($path, '%PDF-1.4 quittance');

    $response = $this->actingAs($plain)->get(DocumentStorage::temporaryUrl($path));

    $response->assertOk();
    expect($response->streamedContent())->toContain('%PDF-1.4 quittance');
});

it('leaves nothing readable on disk', function () {
    encryptedDocument($this->user, $this->dek, '%PDF-1.4 facture EDF de mars');

    $onDisk = Storage::disk('local')->get("documents/{$this->user->id}/pieces-comptables/facture.pdf");

    expect($onDisk)->not->toContain('%PDF')
        ->and($onDisk)->not->toContain('EDF')
        ->and($onDisk)->toStartWith(DocumentCipher::MAGIC);
});

it('requires a valid signature, exactly like before', function () {
    encryptedDocument($this->user, $this->dek, '%PDF-1.4 facture');

    $this->actingAs($this->user)
        ->withCookies(vaultCookie($this->user, $this->dek))
        ->get("/d/documents/{$this->user->id}/pieces-comptables/facture.pdf")
        ->assertForbidden();
});
