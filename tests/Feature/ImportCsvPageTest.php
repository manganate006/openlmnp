<?php

use App\Filament\Pages\ImportCsv;
use App\Models\Expense;
use App\Models\Property;
use App\Models\User;
use App\Services\Csv\CsvProfile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/**
 * L'écran d'import CSV générique (lot 5).
 *
 * Le point qui compte : le mappage est PROPOSÉ, montré, corrigeable, et l'import reste
 * bloqué tant qu'une colonne obligatoire n'est associée à rien. Un mappage deviné puis
 * appliqué en silence transforme un import réussi en comptabilité fausse.
 */
function importCsvProperty(User $user): Property
{
    return Property::forceCreate([
        'user_id' => $user->id,
        'name' => 'Bien import page',
        'address' => '1 rue Test',
        'city' => 'Paris',
        'postal_code' => '75001',
        'type' => 'apartment',
        'total_area' => 100,
        'rented_area' => 100,
        'acquisition_date' => '2020-01-01',
        'acquisition_price' => 20000000,
        'notary_fees' => 0,
        'land_percentage' => 20,
        'rental_start_date' => '2021-01-01',
        'rental_type' => 'seasonal',
        'is_primary_residence' => false,
    ]);
}

/**
 * Dépose un CSV là où Filament range ses téléversements, et rend l'état que le composant
 * `FileUpload` porte réellement : un tableau `[uuid => chemin]`.
 *
 * ⚠️ Passer un `UploadedFile` échoue (« Undefined property: UploadedFile::$name ») et une
 * chaîne nue aussi (les règles de validation attendent un tableau) : c'est cette forme-là
 * qu'il faut reproduire pour tester l'écran sans simuler tout le téléversement.
 *
 * @return array<string, string>
 */
function uploadedCsv(string $content): array
{
    $path = 'imports/' . uniqid('olmnp-page-') . '.csv';
    Storage::disk()->put($path, $content);

    return [uniqid('u') => $path];
}

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->property = importCsvProperty($this->user);
});

it('renders the upload step', function () {
    Livewire::actingAs($this->user)
        ->test(ImportCsv::class)
        ->assertOk()
        ->assertSee('Ce que cet écran sait lire');
});

it('shows the guessed mapping and a preview before writing anything', function () {
    $file = uploadedCsv("Date;Montant;Libellé\n15/03/2024;1 234,56;Taxe foncière\n");

    $component = Livewire::actingAs($this->user)
        ->test(ImportCsv::class)
        ->set('data.property_id', $this->property->id)
        ->set('data.target', CsvProfile::TARGET_EXPENSE)
        ->set('data.csv_file', $file)
        ->call('preview');

    expect($component->get('previewData')['rows'])->toHaveCount(1)
        ->and($component->get('mapping')['date'])->toBe('0')
        ->and($component->get('mapping')['amount'])->toBe('1')
        ->and($component->get('mapping')['description'])->toBe('2');

    // L'aperçu n'écrit rien : c'est tout son intérêt.
    expect(Expense::withoutGlobalScopes()->count())->toBe(0);
});

it('imports only once the user confirms', function () {
    $file = uploadedCsv("Date;Montant;Libellé\n15/03/2024;1 234,56;Taxe foncière\n");

    $component = Livewire::actingAs($this->user)
        ->test(ImportCsv::class)
        ->set('data.property_id', $this->property->id)
        ->set('data.target', CsvProfile::TARGET_EXPENSE)
        ->set('data.csv_file', $file)
        ->call('preview')
        ->call('confirmImport');

    expect(Expense::withoutGlobalScopes()->count())->toBe(1)
        ->and($component->get('lastResult')['imported'])->toBe(1)
        ->and($component->get('previewData'))->toBeNull();
});

it('lets the user repair a mapping the guess got wrong', function () {
    // Aucun intitulé reconnaissable : la proposition est vide, l'utilisateur tranche.
    $file = uploadedCsv("A;B;C\n15/03/2024;250,00;Ramonage\n");

    $component = Livewire::actingAs($this->user)
        ->test(ImportCsv::class)
        ->set('data.property_id', $this->property->id)
        ->set('data.target', CsvProfile::TARGET_EXPENSE)
        ->set('data.csv_file', $file)
        ->call('preview');

    expect($component->get('previewData')['missing'])->not->toBeEmpty();

    $component->set('mapping.date', '0')
        ->set('mapping.amount', '1')
        ->set('mapping.description', '2')
        ->call('refreshPreview');

    expect($component->get('previewData')['missing'])->toBe([]);

    $component->call('confirmImport');

    expect(Expense::withoutGlobalScopes()->first()->description)->toBe('Ramonage');
});

it('never imports another user property', function () {
    $intruder = User::factory()->create();
    $file = uploadedCsv("Date;Montant;Libellé\n15/03/2024;100,00;Charge\n");

    $component = Livewire::actingAs($intruder)
        ->test(ImportCsv::class)
        ->set('data.property_id', $this->property->id)
        ->set('data.target', CsvProfile::TARGET_EXPENSE)
        ->set('data.csv_file', $file)
        ->call('preview');

    // Deux verrous se relaient : la liste des biens du formulaire n'expose que les siens
    // (la validation refuse donc l'identifiant), et `preview()` recherche le bien SOUS le
    // scope de l'utilisateur connecté. Aucun aperçu, aucune écriture.
    expect($component->get('previewData'))->toBeNull()
        ->and(Expense::withoutGlobalScopes()->count())->toBe(0);

    // ⚠️ `previewPropertyId` est une propriété Livewire PUBLIQUE : le navigateur peut la
    // poser lui-même et sauter tout le formulaire. `confirmImport()` doit donc rechercher
    // le bien sous le scope de l'utilisateur connecté, et pas se fier à l'aperçu.
    expect(fn () => Livewire::actingAs($intruder)
        ->test(ImportCsv::class)
        ->set('previewPropertyId', $this->property->id)
        ->set('previewTarget', CsvProfile::TARGET_EXPENSE)
        ->set('previewFilePath', array_values($file)[0])
        ->call('confirmImport'))
        ->toThrow(Illuminate\Database\Eloquent\ModelNotFoundException::class);

    expect(Expense::withoutGlobalScopes()->count())->toBe(0);
});
