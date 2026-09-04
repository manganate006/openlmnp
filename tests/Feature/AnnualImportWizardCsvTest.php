<?php

use App\Filament\Pages\AnnualImportWizard;
use App\Models\FiscalYear;
use App\Models\Income;
use App\Models\Property;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;


function annualWizardProperty(User $user): Property
{
    return Property::create([
        'user_id' => $user->id,
        'name' => 'Studio Test',
        'address' => '1 rue du Test',
        'city' => 'Lyon',
        'postal_code' => '69003',
        'type' => 'apartment',
        'total_area' => 45,
        'rented_area' => 45,
        'acquisition_date' => '2022-01-01',
        'acquisition_price' => 20000000,
        'land_percentage' => 15,
        'rental_start_date' => '2022-03-01',
        'rental_type' => 'seasonal',
        'is_primary_residence' => false,
    ]);
}

/**
 * L'étape « Recettes Airbnb » de l'assistant d'import annuel n'importait rien.
 *
 * L'état brut d'un `FileUpload` est un TABLEAU, et la page lit `$this->data` : le
 * `is_string($data['csv_file'])` qui gardait le bloc était toujours faux. Aucune erreur, aucun
 * message — et une notification « Import terminé » pour finir. C'est le pire des défauts :
 * silencieux, sur des recettes qui finissent dans une déclaration fiscale.
 */
it('importe réellement les recettes du CSV déposé dans l’assistant annuel', function () {
    Storage::fake('local');
    $user = User::factory()->create();
    $this->actingAs($user);

    $property = annualWizardProperty($user);

    $csv = <<<'CSV'
    Date,Type,Code de confirmation,Nuits,Voyageur,Logement,Montant
    01/03/2026,Réservation,HMABCD1234,3,Jean Dupont,Studio,450.00
    CSV;

    Livewire\Livewire::test(AnnualImportWizard::class)
        ->set('data.property_id', $property->id)
        ->set('data.year', 2026)
        ->set('data.import_airbnb', true)
        ->set('data.csv_file', [UploadedFile::fake()->createWithContent('airbnb.csv', $csv)])
        ->call('create');

    expect(Income::where('property_id', $property->id)->count())
        ->toBeGreaterThan(0, 'aucune recette créée : le CSV déposé a été ignoré en silence');
});

/**
 * `transmitted_at` était déclarée dans le modèle sans qu'aucune migration ne la crée.
 *
 * ⚠️ Inoffensif à la lecture d'un modèle (qui rend `null`), mais SQLite traite un identifiant
 * inconnu entre guillemets doubles comme un littéral de chaîne : `whereNotNull()` rendait donc
 * TOUS les exercices au lieu d'aucun, sans lever « no such column ». Mesuré en production le
 * 2026-09-04 : 80 sur 80.
 */
it('expose transmitted_at comme une vraie colonne, filtrable', function () {
    expect(Schema::hasColumn('fiscal_years', 'transmitted_at'))
        ->toBeTrue('la colonne que le modèle déclare doit exister en base');

    $user = User::factory()->create();
    $property = annualWizardProperty($user);
    foreach ([2023, 2024, 2025] as $year) {
        FiscalYear::create(['user_id' => $user->id, 'year' => $year, 'status' => FiscalYear::STATUS_DRAFT]);
    }

    expect(FiscalYear::whereNotNull('transmitted_at')->count())
        ->toBe(0, 'un filtre sur une colonne vide ne doit rendre aucune ligne');
});
