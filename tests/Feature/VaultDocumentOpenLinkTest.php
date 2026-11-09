<?php

use App\Models\User;
use App\Services\Vault\DocumentCipher;
use App\Services\Vault\VaultKeyManager;
use App\Support\DocumentStorage;
use Illuminate\Support\Facades\Storage;

/**
 * Un justificatif chiffré était impossible à consulter : rien ne dispatchait
 * `coffre-ouvrir`, et Filament retombait sur `FileUpload->openable()->downloadable()`,
 * alimenté par une URL nulle — d'où le clic qui échouait sur `/expenses/1/[object Object]`.
 *
 * Deux défauts, deux garanties séparées :
 *   - le LIEN produit pour un fichier chiffré doit être exploitable par le navigateur
 *     (porter le nom du document, pointer vers la route signée) ;
 *   - la ROUTE `/d/{path}` doit accepter `?raw=1` ajouté APRÈS la signature — c'est ce que
 *     fait `vault.js#chiffre()` pour récupérer le chiffré brut. Avant le correctif, ce
 *     paramètre supplémentaire invalidait la signature Laravel (elle est recalculée sur TOUS
 *     les paramètres présents à la vérification, pas seulement ceux signés à l'origine) :
 *     même un lien correct aurait fini en 403.
 */
beforeEach(function () {
    Storage::fake('local');

    $this->user = User::factory()->create();
    [$this->vault, $this->dek] = app(VaultKeyManager::class)->create($this->user, 'une phrase de passe solide');
});

function sceller(User $user, string $dek, string $contents = '%PDF-1.4 facture'): string
{
    $path = "documents/{$user->id}/pieces-comptables/facture.pdf";
    Storage::disk('local')->put($path, $contents);
    app(DocumentCipher::class)->encryptFileInPlace(Storage::disk('local')->path($path), $dek);

    return $path;
}

it('accepts a raw=1 appended after signing, on a URL freshly signed for another param set', function () {
    // Signature calculée SANS `raw` — exactement ce que produit
    // `DocumentStorage::temporaryUrl()` et ce que fait un navigateur qui ajoute le
    // paramètre après coup, comme `vault.js#chiffre()`.
    $path = sceller($this->user, $this->dek);
    $url = DocumentStorage::temporaryUrl($path);

    $response = $this->actingAs($this->user)->get($url . (str_contains($url, '?') ? '&' : '?') . 'raw=1');

    $response->assertOk();
    expect($response->headers->get('X-Vault-Encrypted'))->toBe('1');
});

it('produces a document link carrying the display name in its fragment for an encrypted file', function () {
    $path = sceller($this->user, $this->dek);

    $this->actingAs($this->user);

    $closure = DocumentStorage::vaultAwareFileUrl();
    $url = $closure($path, 'Facture EDF de mars');

    expect($url)->not->toBeNull()
        ->and($url)->toContain('/d/documents/')
        ->and($url)->toContain('signature=')
        ->and($url)->toEndWith('#vault=' . rawurlencode('Facture EDF de mars'));
});

it('returns null for a plaintext document, so Filament falls back to its normal preview URL', function () {
    $plain = User::factory()->create();
    $path = "documents/{$plain->id}/pieces-comptables/quittance.pdf";
    Storage::disk('local')->put($path, '%PDF-1.4 quittance');

    $this->actingAs($plain);

    $closure = DocumentStorage::vaultAwareFileUrl();

    expect($closure($path, 'Quittance'))->toBeNull();
});

it('lets the browser fetch the raw ciphertext from the link it produced, exactly as vault.js does', function () {
    $path = sceller($this->user, $this->dek, '%PDF-1.4 contenu chiffré à retrouver');

    $this->actingAs($this->user);

    $closure = DocumentStorage::vaultAwareFileUrl();
    $url = $closure($path, 'Facture EDF');

    // Ce que fait `vault.js#chiffre()` : retirer le fragment (jamais envoyé au serveur),
    // ajouter `raw=1` À LA SUITE de l'URL déjà signée.
    $withoutFragment = strtok($url, '#');
    $rawUrl = $withoutFragment . (str_contains($withoutFragment, '?') ? '&' : '?') . 'raw=1';

    $response = $this->get($rawUrl);

    $response->assertOk();
    expect($response->headers->get('X-Vault-Encrypted'))->toBe('1')
        ->and($response->headers->get('Content-Disposition'))->toContain('attachment');
});
