<?php

use App\Filament\Pages\Vault;
use App\Models\DocumentVault;
use App\Models\User;
use App\Services\Vault\DocumentCipher;
use App\Services\Vault\VaultKeyManager;
use App\Services\Vault\VaultMigrator;
use App\Support\DocumentStorage;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/**
 * Désactiver un coffre qui contient des dépôts postérieurs à l'activation.
 *
 * Ces dépôts sont au format 2 : leur clé est scellée à la clé publique du coffre, et
 * seule la clé privée la descelle — que l'instance n'a jamais en temps normal. La
 * désactivation lisait la clé dans le cookie, qui ne porte que la DEK : les fichiers
 * scellés restaient chiffrés (« 1 fichier(s) n'ont pas pu être traités ») et le coffre
 * ne pouvait plus être désactivé. Constaté au navigateur le 2026-10-16.
 */
beforeEach(function () {
    Storage::fake('local');
    $this->user = User::factory()->create(['email' => 'coffre-scelle@example.test']);
    $this->passphrase = 'une phrase de passe solide';

    [, $dek] = app(VaultKeyManager::class)->create($this->user, $this->passphrase);

    // Un fichier d'avant l'activation, chiffré par la migration (format 1)…
    $this->ancien = "documents/{$this->user->id}/pieces-comptables/avant.pdf";
    Storage::disk('local')->put($this->ancien, '%PDF-1.4 facture d\'avant');
    app(VaultMigrator::class)->process($this->user, $dek, VaultMigrator::DIRECTION_ENCRYPT);

    // … et un dépôt postérieur, scellé à la clé publique (format 2).
    $this->nouveau = "documents/{$this->user->id}/pieces-comptables/apres.pdf";
    DocumentStorage::putProtected('%PDF-1.4 facture d\'après', $this->nouveau, $this->user);

    $cipher = app(DocumentCipher::class);
    expect($cipher->formatOf(Storage::disk('local')->path($this->ancien)))->toBe(DocumentCipher::FORMAT_VERSION)
        ->and($cipher->formatOf(Storage::disk('local')->path($this->nouveau)))->toBe(DocumentCipher::FORMAT_VERSION_SEALED);
});

it('decrypts both formats and drops the vault from the settings page', function () {
    Livewire::actingAs($this->user)
        ->test(Vault::class)
        ->callAction('disable', ['passphrase' => $this->passphrase])
        ->assertHasNoActionErrors();

    expect(Storage::disk('local')->get($this->ancien))->toBe('%PDF-1.4 facture d\'avant')
        ->and(Storage::disk('local')->get($this->nouveau))->toBe('%PDF-1.4 facture d\'après')
        ->and(DocumentVault::where('user_id', $this->user->id)->exists())->toBeFalse();
});

it('touches nothing on a wrong passphrase', function () {
    $avant = Storage::disk('local')->get($this->nouveau);

    Livewire::actingAs($this->user)
        ->test(Vault::class)
        ->callAction('disable', ['passphrase' => 'ce n est pas la bonne'])
        ->assertHasNoActionErrors();

    expect(Storage::disk('local')->get($this->nouveau))->toBe($avant)
        ->and(DocumentVault::where('user_id', $this->user->id)->value('status'))->toBe(DocumentVault::STATUS_ACTIVE);
});

it('decrypts both formats from the command line', function () {
    $this->artisan('openlmnp:vault-migrate', ['email' => 'coffre-scelle@example.test', '--decrypt' => true])
        ->expectsQuestion('Phrase de passe du coffre-fort', $this->passphrase)
        ->assertSuccessful();

    expect(Storage::disk('local')->get($this->nouveau))->toBe('%PDF-1.4 facture d\'après')
        ->and(DocumentVault::where('user_id', $this->user->id)->exists())->toBeFalse();
});

it('still cannot open a sealed file with the DEK alone', function () {
    // Garde de conception : hors désactivation, l'instance ne relit pas ce qu'elle a scellé.
    [$dek] = app(VaultKeyManager::class)->unlockPair($this->user, $this->passphrase);
    $absolute = Storage::disk('local')->path($this->nouveau);
    $avant = file_get_contents($absolute);

    expect(fn () => app(DocumentCipher::class)->decryptFileInPlace($absolute, $dek))
        ->toThrow(\App\Services\Vault\VaultException::class);

    expect(file_get_contents($absolute))->toBe($avant);
});
