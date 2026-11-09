<?php

use App\Models\User;
use App\Services\Vault\DocumentCipher;
use App\Services\Vault\VaultKeyManager;
use App\Support\DocumentStorage;
use App\Support\Vault;
use App\Support\VaultSession;
use Illuminate\Support\Facades\Storage;

/**
 * Un justificatif déposé coffre VERROUILLÉ est scellé à la clé publique : seul le navigateur,
 * avec la clé privée, l'ouvre. Ouvert par son lien direct (clic du milieu, nouvel onglet), le
 * serveur partait en flux tronqué, même coffre déverrouillé (bêta, 2026-10-25).
 */
beforeEach(function () {
    Storage::fake('local');
    config()->set('vault.kdf', 'test');
    $this->user = User::factory()->create();
    [, $this->dek] = app(VaultKeyManager::class)->create($this->user, 'une phrase de passe solide');

    $path = "documents/{$this->user->id}/pieces-comptables/scelle.pdf";
    $in = fopen('php://temp', 'w+b');
    fwrite($in, '%PDF-1.4 facture scellée');
    rewind($in);
    $out = fopen('php://temp', 'w+b');
    // Exactement le dépôt coffre verrouillé : clé neuve, scellée à la clé publique.
    (new DocumentCipher)->encrypt($in, $out, sodium_crypto_secretstream_xchacha20poly1305_keygen(), 1, null, Vault::publicKeyFor($this->user));
    rewind($out);
    Storage::disk('local')->put($path, stream_get_contents($out));

    $this->url = DocumentStorage::temporaryUrl($path);
});

function cookieDeverrouille(User $user, string $dek): array
{
    return [app(VaultSession::class)->cookieNameFor($user) => json_encode([
        'u' => $user->id, 'k' => base64_encode($dek), 'v' => 1, 'e' => now()->addHour()->getTimestamp(),
    ])];
}

it('refuse proprement le lien direct d\'un fichier scellé, même coffre déverrouillé', function () {
    $response = $this->actingAs($this->user)
        ->withCookies(cookieDeverrouille($this->user, $this->dek))
        ->get($this->url);

    $response->assertStatus(423)
        ->assertSee('chiffré pour votre seul navigateur')
        ->assertSee('phrase de passe')
        ->assertDontSee('%PDF', false);
});

it('sert toujours le chiffré brut au module du navigateur', function () {
    $response = $this->actingAs($this->user)->get($this->url.'&raw=1');

    $response->assertOk();
    expect($response->headers->get('X-Vault-Encrypted'))->toBe('1');
});

it('rend une page 423 lisible plutôt que la page d\'erreur brute', function () {
    $this->actingAs($this->user)->get($this->url)
        ->assertStatus(423)
        ->assertSee('Justificatif verrouillé')
        ->assertDontSee('An Error Occurred');
});

it('traduit les libellés des icônes d\'un justificatif', function () {
    app()->setLocale('fr');

    expect(__('filament-forms::components.file_upload.actions.open.label'))->toBe('Ouvrir dans un nouvel onglet')
        ->and(__('filament-forms::components.file_upload.actions.download.label'))->toBe('Télécharger');
});
