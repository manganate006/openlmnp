<?php

use App\Filament\Pages\Vault;
use App\Filament\Resources\Properties\Pages\CreateProperty;
use App\Models\DocumentVault;
use App\Models\Property;
use App\Models\User;
use App\Services\Vault\DocumentCipher;
use App\Services\Vault\VaultKeyManager;
use App\Support\VaultSession;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

// La page de réglages du coffre-fort, et le point d'écriture des fichiers : ce
// qu'un utilisateur peut faire, et ce qu'il ne peut pas.

beforeEach(function () {
    Storage::fake('local');
    $this->user = User::factory()->create();
});

it('enables the vault and encrypts what is already there', function () {
    $path = "documents/{$this->user->id}/pieces-comptables/facture.pdf";
    Storage::disk('local')->put($path, '%PDF-1.4 facture EDF');

    Livewire::actingAs($this->user)
        ->test(Vault::class)
        ->callAction('enable', [
            'passphrase' => 'une phrase de passe solide',
            'passphrase_confirmation' => 'une phrase de passe solide',
            'acknowledgement' => 'JE COMPRENDS',
        ])
        ->assertHasNoActionErrors();

    $vault = DocumentVault::where('user_id', $this->user->id)->first();

    expect($vault)->not->toBeNull()
        ->and($vault->status)->toBe(DocumentVault::STATUS_ACTIVE)
        ->and(Storage::disk('local')->get($path))->toStartWith(DocumentCipher::MAGIC)
        ->and(Storage::disk('local')->get($path))->not->toContain('%PDF');
});

it('requires the warning to be acknowledged word for word', function () {
    Livewire::actingAs($this->user)
        ->test(Vault::class)
        ->callAction('enable', [
            'passphrase' => 'une phrase de passe solide',
            'passphrase_confirmation' => 'une phrase de passe solide',
            'acknowledgement' => 'oui oui',
        ])
        ->assertHasActionErrors(['acknowledgement']);

    expect(DocumentVault::where('user_id', $this->user->id)->exists())->toBeFalse();
});

it('requires both passphrase fields to match', function () {
    Livewire::actingAs($this->user)
        ->test(Vault::class)
        ->callAction('enable', [
            'passphrase' => 'une phrase de passe solide',
            'passphrase_confirmation' => 'une autre phrase de passe',
            'acknowledgement' => 'JE COMPRENDS',
        ])
        ->assertHasActionErrors(['passphrase_confirmation']);
});

it('unlocks with the right passphrase and refuses the wrong one', function () {
    app(VaultKeyManager::class)->create($this->user, 'une phrase de passe solide');

    Livewire::actingAs($this->user)
        ->test(Vault::class)
        ->callAction('unlock', ['passphrase' => 'ce n est pas la bonne'])
        ->assertHasNoActionErrors();

    // Refus par notification, sans clé posée : la page reste verrouillée.
    expect(app(VaultSession::class)->keyFor($this->user))->toBeNull();
});

it('decrypts everything and drops the vault when disabled', function () {
    $path = "documents/{$this->user->id}/pieces-comptables/facture.pdf";
    Storage::disk('local')->put($path, '%PDF-1.4 facture EDF');

    [, $dek] = app(VaultKeyManager::class)->create($this->user, 'une phrase de passe solide');
    app(\App\Services\Vault\VaultMigrator::class)
        ->process($this->user, $dek, \App\Services\Vault\VaultMigrator::DIRECTION_ENCRYPT);

    // La phrase de passe est demandée par la fenêtre de désactivation elle-même : le
    // cookie ne porte que la DEK, qui n'ouvre pas les dépôts scellés (format 2).
    Livewire::actingAs($this->user)
        ->test(Vault::class)
        ->callAction('disable', ['passphrase' => 'une phrase de passe solide'])
        ->assertHasNoActionErrors();

    expect(Storage::disk('local')->get($path))->toBe('%PDF-1.4 facture EDF')
        ->and(DocumentVault::where('user_id', $this->user->id)->exists())->toBeFalse();
});

it('is not offered to demo accounts', function () {
    $ephemeral = User::factory()->create(['is_demo' => true]);
    $public = User::factory()->create(['email' => config('demo.email')]);

    expect($ephemeral->isDemo())->toBeTrue()
        ->and($public->isDemo())->toBeTrue()
        ->and(\App\Support\Vault::canBeEnabledBy($ephemeral))->toBeFalse()
        // Le compte démo public porte is_demo = false alors que ses identifiants
        // sont publiés : le reconnaître à son email est indispensable.
        ->and(\App\Support\Vault::canBeEnabledBy($public))->toBeFalse()
        ->and(\App\Support\Vault::canBeEnabledBy($this->user))->toBeTrue();

    $this->actingAs($ephemeral)->get(Vault::getUrl())->assertForbidden();
});

it('can be hidden on an instance that does not want it', function () {
    config()->set('vault.enabled', false);

    expect(\App\Support\Vault::canBeEnabledBy($this->user))->toBeFalse();

    $this->actingAs($this->user)->get(Vault::getUrl())->assertForbidden();
});

// -----------------------------------------------------------------------------
// Point d'écriture des fichiers
// -----------------------------------------------------------------------------

it('encrypts a freshly uploaded property photo when the vault is unlocked', function () {
    [, $dek] = app(VaultKeyManager::class)->create($this->user, 'une phrase de passe solide');
    app(VaultSession::class)->unlock($this->user, $dek, 1);

    Livewire::actingAs($this->user)
        ->test(CreateProperty::class)
        ->fillForm([
            'name' => 'Studio Paris 11',
            'address' => '1 rue Test',
            'city' => 'Paris',
            'postal_code' => '75011',
            'type' => 'apartment',
            'total_area' => 40,
            'rented_area' => 40,
            'acquisition_date' => '2020-01-01',
            'acquisition_price' => 200000,
            'notary_fees' => 15000,
            'land_percentage' => 20,
            'rental_start_date' => '2023-01-01',
            'rental_type' => 'seasonal',
            'tva_regime' => 'exempt',
            'photo_path' => [UploadedFile::fake()->image('studio.jpg', 100, 100)],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $property = Property::withoutGlobalScopes()->where('user_id', $this->user->id)->firstOrFail();

    expect($property->photo_path)->not->toBeNull();

    $stored = Storage::disk('local')->get($property->photo_path);

    // Le fichier est chiffré sur le disque dès le dépôt : rien n'y transite en
    // clair après la fenêtre livewire-tmp.
    expect($stored)->toStartWith(DocumentCipher::MAGIC)
        ->and(app(DocumentCipher::class)->plainSize(Storage::disk('local')->path($property->photo_path)))
        ->toBeGreaterThan(0);
});

it('encrypts a justificatif deposited through the documents repeater', function () {
    // Le cas nominal du chantier : le champ vit dans un Repeater ->relationship(),
    // où le callback d'écriture tourne AVANT que la ligne Document existe. Il doit
    // donc résoudre le coffre par l'utilisateur connecté, jamais par $record.
    [, $dek] = app(VaultKeyManager::class)->create($this->user, 'une phrase de passe solide');
    app(VaultSession::class)->unlock($this->user, $dek, 1);

    $property = Property::forceCreate([
        'user_id' => $this->user->id,
        'name' => 'Studio', 'address' => '1 rue Test', 'city' => 'Paris',
        'postal_code' => '75011', 'type' => 'apartment', 'total_area' => 40,
        'rented_area' => 40, 'acquisition_date' => '2020-01-01',
        'acquisition_price' => 20000000, 'notary_fees' => 1500000, 'market_value' => null,
        'land_percentage' => 20, 'rental_start_date' => '2023-01-01',
        'rental_type' => 'seasonal', 'tva_regime' => 'exempt', 'is_primary_residence' => true,
    ]);

    Livewire::actingAs($this->user)
        ->test(\App\Filament\Resources\Expenses\Pages\CreateExpense::class)
        ->fillForm([
            'property_id' => $property->id,
            'expense_date' => '2024-03-14',
            'category' => 'property_tax',
            'description' => 'Taxe foncière 2024',
            'amount' => '157.00',
            'recurring_type' => 'once',
            'tva_rate' => 0,
            'documents' => [
                [
                    'label' => 'Avis de taxe foncière',
                    'file_path' => [UploadedFile::fake()->create('avis.pdf', 12)],
                ],
            ],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $document = \App\Models\Document::firstOrFail();

    expect(Storage::disk('local')->get($document->file_path))
        ->toStartWith(DocumentCipher::MAGIC)
        ->and($document->file_path)->toStartWith("documents/{$this->user->id}/pieces-comptables/");
});

it('stores uploads in the clear when no vault is enabled', function () {
    Livewire::actingAs($this->user)
        ->test(CreateProperty::class)
        ->fillForm([
            'name' => 'Studio Paris 11',
            'address' => '1 rue Test',
            'city' => 'Paris',
            'postal_code' => '75011',
            'type' => 'apartment',
            'total_area' => 40,
            'rented_area' => 40,
            'acquisition_date' => '2020-01-01',
            'acquisition_price' => 200000,
            'notary_fees' => 15000,
            'land_percentage' => 20,
            'rental_start_date' => '2023-01-01',
            'rental_type' => 'seasonal',
            'tva_regime' => 'exempt',
            'photo_path' => [UploadedFile::fake()->image('studio.jpg', 100, 100)],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $property = Property::withoutGlobalScopes()->where('user_id', $this->user->id)->firstOrFail();

    expect(Storage::disk('local')->get($property->photo_path))
        ->not->toStartWith(DocumentCipher::MAGIC);
});
