<?php

use App\Models\Document;
use App\Models\DocumentVault;
use App\Models\Expense;
use App\Models\Property;
use App\Models\User;
use App\Services\Vault\DocumentCipher;
use App\Services\Vault\VaultKeyManager;
use App\Services\Vault\VaultMigrator;
use Illuminate\Support\Facades\Storage;

// La migration de l'existant, dans les deux sens. Les propriétés attendues :
// idempotente, reprenable par lots, et capable de traverser des données abîmées
// sans planter — la production en a (14 justificatifs restés à l'ancienne racine
// du disque, 2 lignes orphelines).

const PASSPHRASE = 'une phrase de passe solide';

function makeVaultProperty(User $user, string $name = 'Appartement Test'): Property
{
    return Property::forceCreate([
        'user_id' => $user->id,
        'name' => $name,
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

function makeExpenseWithDocument(Property $property, string $filename, string $content): Document
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
    Storage::disk('local')->put($path, $content);

    return Document::forceCreate([
        'documentable_type' => Expense::class,
        'documentable_id' => $expense->id,
        'label' => 'Avis CFE 2024',
        'file_path' => $path,
        'sort_order' => 0,
    ]);
}

beforeEach(function () {
    Storage::fake('local');

    $this->user = User::factory()->create(['email' => 'proprietaire@example.test']);
    $this->property = makeVaultProperty($this->user);
    $this->migrator = app(VaultMigrator::class);
    $this->cipher = app(DocumentCipher::class);
});

it('encrypts every file of the account, including property photos', function () {
    makeExpenseWithDocument($this->property, 'facture.pdf', '%PDF-1.4 facture EDF');

    // Photo de bien : même arborescence, jamais référencée par la table documents.
    $photo = "documents/{$this->user->id}/photos-biens/appartement.jpg";
    Storage::disk('local')->put($photo, 'JPEG données de la photo');
    $this->property->update(['photo_path' => $photo]);

    [, $dek] = app(VaultKeyManager::class)->create($this->user, PASSPHRASE);
    $report = $this->migrator->process($this->user, $dek, VaultMigrator::DIRECTION_ENCRYPT);

    expect($report->processed)->toBe(2)
        ->and($report->isComplete())->toBeTrue()
        ->and(Storage::disk('local')->get($photo))->not->toContain('JPEG données')
        ->and(Storage::disk('local')->get("documents/{$this->user->id}/pieces-comptables/facture.pdf"))
        ->not->toContain('%PDF');
});

it('is idempotent: a second run touches nothing', function () {
    makeExpenseWithDocument($this->property, 'facture.pdf', '%PDF-1.4 facture');
    [, $dek] = app(VaultKeyManager::class)->create($this->user, PASSPHRASE);

    $this->migrator->process($this->user, $dek, VaultMigrator::DIRECTION_ENCRYPT);
    $fingerprint = md5(Storage::disk('local')->get("documents/{$this->user->id}/pieces-comptables/facture.pdf"));

    $second = $this->migrator->process($this->user, $dek, VaultMigrator::DIRECTION_ENCRYPT);

    expect($second->processed)->toBe(0)
        ->and($second->skipped)->toBe(1)
        // Un fichier re-chiffré changerait d'empreinte : l'état vient bien des
        // octets du fichier, pas d'un drapeau en base.
        ->and(md5(Storage::disk('local')->get("documents/{$this->user->id}/pieces-comptables/facture.pdf")))
        ->toBe($fingerprint);
});

it('resumes where it stopped when it works in batches', function () {
    foreach (range(1, 5) as $i) {
        makeExpenseWithDocument($this->property, "facture-{$i}.pdf", "%PDF-1.4 facture {$i}");
    }

    [, $dek] = app(VaultKeyManager::class)->create($this->user, PASSPHRASE);

    $first = $this->migrator->process($this->user, $dek, VaultMigrator::DIRECTION_ENCRYPT, batch: 2);

    expect($first->processed)->toBe(2)
        ->and($first->remaining)->toBe(3)
        ->and($first->isComplete())->toBeFalse();

    $second = $this->migrator->process($this->user, $dek, VaultMigrator::DIRECTION_ENCRYPT, batch: 2);
    $third = $this->migrator->process($this->user, $dek, VaultMigrator::DIRECTION_ENCRYPT, batch: 2);

    expect($second->processed)->toBe(2)
        ->and($third->processed)->toBe(1)
        ->and($third->isComplete())->toBeTrue();
});

it('restores the files byte for byte on the way back', function () {
    makeExpenseWithDocument($this->property, 'facture.pdf', '%PDF-1.4 facture EDF de mars');
    $before = md5(Storage::disk('local')->get("documents/{$this->user->id}/pieces-comptables/facture.pdf"));

    [, $dek] = app(VaultKeyManager::class)->create($this->user, PASSPHRASE);
    $this->migrator->process($this->user, $dek, VaultMigrator::DIRECTION_ENCRYPT);
    $this->migrator->process($this->user, $dek, VaultMigrator::DIRECTION_DECRYPT);

    expect(md5(Storage::disk('local')->get("documents/{$this->user->id}/pieces-comptables/facture.pdf")))
        ->toBe($before);
});

it('counts unreachable files instead of crashing on them', function () {
    // Reproduit l'état de la production : des lignes qui pointent vers l'ancienne
    // racine du disque `local`, déplacée par Laravel 11.
    $expense = Expense::forceCreate([
        'property_id' => $this->property->id,
        'expense_date' => '2024-01-01', 'amount' => 100, 'amount_ht' => 100,
        'amount_tva' => 0, 'tva_rate' => 0, 'category' => 'property_tax',
        'description' => 'CFE', 'is_dedicated' => true, 'recurring_type' => 'yearly',
    ]);

    Document::forceCreate([
        'documentable_type' => Expense::class,
        'documentable_id' => $expense->id,
        'label' => 'Justificatif disparu',
        'file_path' => 'documents/999/pieces-comptables/ancienne-racine.pdf',
        'sort_order' => 0,
    ]);

    makeExpenseWithDocument($this->property, 'facture.pdf', '%PDF-1.4 facture');
    [, $dek] = app(VaultKeyManager::class)->create($this->user, PASSPHRASE);

    $report = $this->migrator->process($this->user, $dek, VaultMigrator::DIRECTION_ENCRYPT);

    expect($report->missing)->toBe(1)
        ->and($report->missingPaths)->toContain('documents/999/pieces-comptables/ancienne-racine.pdf')
        // Le fichier valide, lui, a bien été traité.
        ->and($report->processed)->toBe(1);
});

// -----------------------------------------------------------------------------
// Commande artisan
// -----------------------------------------------------------------------------

it('changes nothing in dry run and needs no passphrase for it', function () {
    makeExpenseWithDocument($this->property, 'facture.pdf', '%PDF-1.4 facture');
    app(VaultKeyManager::class)->create($this->user, PASSPHRASE);

    $this->artisan('openlmnp:vault-migrate', ['email' => 'proprietaire@example.test', '--dry-run' => true])
        ->assertSuccessful();

    expect(Storage::disk('local')->get("documents/{$this->user->id}/pieces-comptables/facture.pdf"))
        ->toBe('%PDF-1.4 facture');
});

it('encrypts from the command line and stops on a wrong passphrase', function () {
    makeExpenseWithDocument($this->property, 'facture.pdf', '%PDF-1.4 facture');
    app(VaultKeyManager::class)->create($this->user, PASSPHRASE);

    $this->artisan('openlmnp:vault-migrate', ['email' => 'proprietaire@example.test'])
        ->expectsQuestion('Phrase de passe du coffre-fort', 'ce n est pas la bonne')
        ->assertFailed();

    expect(Storage::disk('local')->get("documents/{$this->user->id}/pieces-comptables/facture.pdf"))
        ->toBe('%PDF-1.4 facture');

    $this->artisan('openlmnp:vault-migrate', ['email' => 'proprietaire@example.test'])
        ->expectsQuestion('Phrase de passe du coffre-fort', PASSPHRASE)
        ->assertSuccessful();

    expect(Storage::disk('local')->get("documents/{$this->user->id}/pieces-comptables/facture.pdf"))
        ->toStartWith(DocumentCipher::MAGIC)
        ->and(DocumentVault::where('user_id', $this->user->id)->value('status'))
        ->toBe(DocumentVault::STATUS_ACTIVE);
});

it('drops the vault once everything is decrypted from the command line', function () {
    makeExpenseWithDocument($this->property, 'facture.pdf', '%PDF-1.4 facture');
    [, $dek] = app(VaultKeyManager::class)->create($this->user, PASSPHRASE);
    $this->migrator->process($this->user, $dek, VaultMigrator::DIRECTION_ENCRYPT);

    $this->artisan('openlmnp:vault-migrate', ['email' => 'proprietaire@example.test', '--decrypt' => true])
        ->expectsQuestion('Phrase de passe du coffre-fort', PASSPHRASE)
        ->assertSuccessful();

    expect(Storage::disk('local')->get("documents/{$this->user->id}/pieces-comptables/facture.pdf"))
        ->toBe('%PDF-1.4 facture')
        ->and(DocumentVault::where('user_id', $this->user->id)->exists())->toBeFalse();
});

it('unblocks a vault stuck mid operation', function () {
    $vault = app(VaultKeyManager::class)->create($this->user, PASSPHRASE)[0];
    $vault->update(['status' => DocumentVault::STATUS_ENCRYPTING]);

    $this->artisan('openlmnp:vault-migrate', ['email' => 'proprietaire@example.test', '--abandon' => true])
        ->assertSuccessful();

    expect($vault->fresh()->status)->toBe(DocumentVault::STATUS_ACTIVE);
});

it('refuses to run on an account without a vault', function () {
    $this->artisan('openlmnp:vault-migrate', ['email' => 'proprietaire@example.test'])->assertFailed();
    $this->artisan('openlmnp:vault-migrate', ['email' => 'inconnu@example.test'])->assertFailed();
});
