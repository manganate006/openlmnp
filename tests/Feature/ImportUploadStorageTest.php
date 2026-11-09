<?php

use App\Filament\Pages\AnnualImportWizard;
use App\Filament\Pages\ImportAirbnb;
use App\Filament\Pages\ImportCsv;
use App\Models\Income;
use App\Models\Property;
use App\Models\User;
use App\Services\Csv\CsvProfile;
use App\Support\ImportUpload;
use Filament\Forms\Components\FileUpload;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/**
 * Les fichiers déposés pour import ne tombent plus à la racine du disque privé.
 *
 * Les trois écrans d'import déclaraient un `FileUpload` **sans `->directory()`** : Filament
 * déposait donc le fichier en `storage/app/private/<ulid>.csv`, hors de `documents/`, sans
 * aucun lien en base — et rien ne le supprimait jamais. Un export Airbnb porte les NOMS des
 * voyageurs, leurs dates de séjour et les montants versés : c'est une pièce personnelle
 * abandonnée en clair à côté du coffre-fort censé la protéger.
 *
 * Deux corrections, verrouillées séparément : le RANGEMENT (sous `documents/{user_id}/imports`,
 * donc dans le périmètre que le coffre chiffre) et la DURÉE DE VIE (supprimé dès l'import fait).
 */
function importUploadProperty(User $user): Property
{
    return Property::forceCreate([
        'user_id' => $user->id,
        'name' => 'Bien import',
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

/** Dépose un CSV déjà « téléversé », dans le répertoire d'import de l'utilisateur. */
function storedImportCsv(User $user, string $content): string
{
    $path = ImportUpload::directoryFor($user->id) . '/' . uniqid('olmnp-') . '.csv';
    Storage::disk('local')->put($path, $content);

    return $path;
}

beforeEach(function () {
    // Disque isolé : sans lui, les fichiers déposés par un test survivent dans le
    // `storage/` partagé et le test suivant croit les avoir produits lui-même.
    Storage::fake('local');

    $this->user = User::factory()->create();
    $this->property = importUploadProperty($this->user);
});

it('range le fichier téléversé sous documents/{user}/imports, jamais à la racine', function (string $page) {
    $component = Livewire::actingAs($this->user)->test($page)
        // L'assistant annuel masque son dépôt derrière un interrupteur.
        ->set('data.import_airbnb', true)
        ->set('data.csv_file', [UploadedFile::fake()->createWithContent('airbnb.csv', "a,b\n1,2\n")]);

    $field = $component->instance()->form->getComponent(
        fn ($c) => $c instanceof FileUpload,
        withHidden: true,
    );
    $field->saveUploadedFiles();

    expect($field->getState())->toStartWith("documents/{$this->user->id}/imports/");

    // Rien à la racine du disque privé : c'est exactement ce qui s'y accumulait.
    $rootFiles = array_values(array_filter(
        Storage::disk('local')->files(),
        fn (string $file) => $file !== '.gitignore',
    ));

    expect($rootFiles)->toBe([]);
})->with([
    ImportAirbnb::class,
    ImportCsv::class,
    AnnualImportWizard::class,
]);

it('supprime le CSV Airbnb dès l\'import confirmé', function () {
    $csv = "Date,Type,Confirmation Code,Amount,Host Fee\n"
        . "01/15/2024,Reservation,ABC123,150.00,-5.00\n";
    $path = storedImportCsv($this->user, $csv);

    Livewire::actingAs($this->user)->test(ImportAirbnb::class)
        ->set('data.property_id', $this->property->id)
        ->set('data.csv_file', [uniqid('u') => $path])
        ->call('preview')
        ->call('confirmImport');

    expect(Storage::disk('local')->exists($path))->toBeFalse();
});

it('supprime le tableur générique dès l\'import confirmé', function () {
    $path = storedImportCsv($this->user, "Date;Montant;Libellé\n15/03/2024;1 234,56;Taxe foncière\n");

    Livewire::actingAs($this->user)->test(ImportCsv::class)
        ->set('data.property_id', $this->property->id)
        ->set('data.target', CsvProfile::TARGET_EXPENSE)
        ->set('data.csv_file', [uniqid('u') => $path])
        ->call('preview')
        ->call('confirmImport');

    expect(Storage::disk('local')->exists($path))->toBeFalse();
});

/**
 * ⚠️ Ce test verrouille DEUX choses, et la première n'est pas une régression théorique :
 * l'étape « Recettes Airbnb » de l'assistant annuel n'importait RIEN. L'état brut d'un
 * `FileUpload` est un tableau, jamais une chaîne, et le `is_string()` qui gardait le bloc
 * d'import était donc toujours faux — sans erreur, sans message, suivi d'une notification
 * « Import terminé ». Sans l'import, la suppression du CSV n'aurait rien eu à supprimer.
 */
it('importe puis supprime le CSV de l\'assistant annuel', function () {
    $csv = "Date,Type,Confirmation Code,Amount,Host Fee\n"
        . "01/15/2024,Reservation,XYZ789,150.00,-5.00\n";

    Livewire::actingAs($this->user)->test(AnnualImportWizard::class)
        ->set('data.year', 2024)
        ->set('data.property_id', $this->property->id)
        ->set('data.import_airbnb', true)
        ->set('data.csv_file', [UploadedFile::fake()->createWithContent('airbnb.csv', $csv)])
        ->call('create');

    expect(Income::withoutGlobalScopes()->where('property_id', $this->property->id)->count())->toBe(1);

    // Et il ne reste rien du fichier : ni dans le répertoire d'import, ni à la racine.
    expect(Storage::disk('local')->allFiles(ImportUpload::directoryFor($this->user->id)))->toBe([]);
});

it('refuse de supprimer un fichier hors du répertoire de l\'utilisateur connecté', function () {
    $other = User::factory()->create();

    $mine = storedImportCsv($this->user, "a,b\n");
    $his = storedImportCsv($other, "a,b\n");
    $justificatif = 'documents/' . $this->user->id . '/pieces-comptables/2024-01-01_facture.pdf';
    Storage::disk('local')->put($justificatif, 'PDF');

    $this->actingAs($this->user);

    // Le chemin vient de l'état Livewire, donc du navigateur : sans la garde, ce nettoyage
    // serait une suppression de fichier arbitraire.
    ImportUpload::discard($his);
    ImportUpload::discard($justificatif);
    ImportUpload::discard('documents/' . $this->user->id . '/imports/../pieces-comptables/2024-01-01_facture.pdf');

    expect(Storage::disk('local')->exists($his))->toBeTrue();
    expect(Storage::disk('local')->exists($justificatif))->toBeTrue();

    // Le sien, en revanche, part bien.
    ImportUpload::discard($mine);
    expect(Storage::disk('local')->exists($mine))->toBeFalse();
});
