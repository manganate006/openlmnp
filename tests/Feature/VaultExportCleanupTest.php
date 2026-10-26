<?php

use App\Models\Document;
use App\Models\Expense;
use App\Models\Property;
use App\Models\User;
use App\Services\DocumentExportService;
use App\Services\Vault\DocumentCipher;
use App\Services\Vault\VaultKeyManager;
use App\Support\VaultSession;
use Illuminate\Support\Facades\Storage;

// L'export ZIP déchiffre les justificatifs vers des fichiers temporaires. Ces
// clairs atterrissent dans /tmp, sur le serveur même dont le coffre protège
// l'utilisateur : ils ne doivent jamais survivre à l'export, et leur suppression
// ne doit dépendre d'aucun chemin heureux (d'où le `finally` du service).

/** @return string[] */
function vaultTempLeftovers(): array
{
    return glob(sys_get_temp_dir() . '/olmnp-vault-*') ?: [];
}

function makeExportProperty(User $user): Property
{
    return Property::forceCreate([
        'user_id' => $user->id,
        'name' => 'Appartement Export',
        'address' => '1 rue Test',
        'city' => 'Paris',
        'postal_code' => '75001',
        'type' => 'apartment',
        'total_area' => 100,
        'rented_area' => 50,
        'acquisition_date' => '2020-01-01',
        'acquisition_price' => 30000000,
        'notary_fees' => 1500000,
        'market_value' => null,
        'land_percentage' => 20,
        'rental_start_date' => '2023-01-01',
        'rental_type' => 'seasonal',
        'tva_regime' => 'exempt',
        'is_primary_residence' => true,
    ]);
}

function makeEncryptedDocument(Property $property, string $dek, string $filename): Document
{
    $expense = Expense::forceCreate([
        'property_id' => $property->id,
        'expense_date' => '2024-01-01',
        'amount' => 15700,
        'amount_ht' => 15700,
        'amount_tva' => 0,
        'tva_rate' => 0,
        'category' => 'property_tax',
        'description' => 'CFE 2024',
        'is_dedicated' => true,
        'recurring_type' => 'yearly',
    ]);

    $path = "documents/{$property->user_id}/pieces-comptables/{$filename}";
    Storage::disk('local')->put($path, '%PDF-1.4 avis de CFE');
    app(DocumentCipher::class)->encryptFileInPlace(Storage::disk('local')->path($path), $dek);

    return Document::forceCreate([
        'documentable_type' => Expense::class,
        'documentable_id' => $expense->id,
        'label' => 'Avis CFE 2024',
        'file_path' => $path,
        'document_date' => '2024-01-01',
        'sort_order' => 0,
    ]);
}

it('leaves no decrypted file behind after exporting an encrypted vault', function () {
    Storage::fake('local');

    $before = vaultTempLeftovers();

    $user = User::factory()->create();
    [$vault, $dek] = app(VaultKeyManager::class)->create($user, 'une phrase de passe solide');
    app(VaultSession::class)->unlock($user, $dek, $vault->key_version);

    $property = makeExportProperty($user);
    makeEncryptedDocument($property, $dek, 'avis-cfe.pdf');
    makeEncryptedDocument($property, $dek, 'avis-cfe-bis.pdf');

    $result = app(DocumentExportService::class)->exportZip($user);

    expect($result['count'])->toBe(2)
        ->and($result['path'])->not->toBeNull()
        ->and(Storage::disk('local')->exists($result['path']))->toBeTrue();

    // Le cœur du test : aucun clair ne traîne dans le répertoire temporaire.
    expect(vaultTempLeftovers())->toBe($before);
});

it('refuses to export rather than produce an archive amputated of encrypted files', function () {
    Storage::fake('local');

    $before = vaultTempLeftovers();

    $user = User::factory()->create();
    [, $dek] = app(VaultKeyManager::class)->create($user, 'une phrase de passe solide');
    // Volontairement PAS de unlock() : le coffre est activé mais verrouillé.

    $property = makeExportProperty($user);
    makeEncryptedDocument($property, $dek, 'avis-cfe.pdf');

    expect(fn () => app(DocumentExportService::class)->exportZip($user))
        ->toThrow(\App\Services\Vault\VaultException::class);

    expect(vaultTempLeftovers())->toBe($before);
});
