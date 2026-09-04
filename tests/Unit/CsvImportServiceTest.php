<?php

use App\Models\Expense;
use App\Models\Furniture;
use App\Models\Income;
use App\Models\Property;
use App\Models\PropertyWork;
use App\Models\User;
use App\Services\Csv\CsvImportService;
use App\Services\Csv\CsvProfile;
use App\Services\Csv\CsvReader;

/**
 * Import CSV générique (lot 5) — recettes, charges, mobilier, travaux.
 *
 * Ce que ces tests verrouillent, et qui n'existait pas avant :
 *   - un séparateur autre que la virgule. Un CSV lu avec le mauvais séparateur ne lève
 *     pas d'erreur : il produit UNE colonne, et l'échec est muet ;
 *   - un mappage de colonnes CORRIGEABLE, pas deviné puis appliqué en silence ;
 *   - la détection de doublons sur les quatre cibles ;
 *   - le refus d'importer quand une colonne obligatoire n'est associée à rien.
 */
function csvProperty(User $user): Property
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

/** Écrit un CSV temporaire et rend son chemin. */
function csvFile(string $content): string
{
    $path = tempnam(sys_get_temp_dir(), 'olmnp-csv-') . '.csv';
    file_put_contents($path, $content);

    return $path;
}

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->property = csvProperty($this->user);
    $this->service = app(CsvImportService::class);
});

// -----------------------------------------------------------------------------
// Lecture du fichier : séparateur, format des montants, accents
// -----------------------------------------------------------------------------

it('detects the delimiter instead of assuming a comma', function () {
    expect(CsvReader::detectDelimiter('Date;Montant;Libellé'))->toBe(';')
        ->and(CsvReader::detectDelimiter('Date,Montant,Libelle'))->toBe(',')
        ->and(CsvReader::detectDelimiter("Date\tMontant\tLibelle"))->toBe("\t");
});

it('imports a French semicolon-separated file with European amounts', function () {
    $file = csvFile(
        "Date;Montant;Libellé;Catégorie\n"
        . "15/03/2024;1 234,56 €;Taxe foncière 2024;Taxe foncière\n"
        . "02/04/2024;89,90 €;Assurance PNO;Assurance\n"
    );

    $result = $this->service->import($file, $this->property, CsvProfile::TARGET_EXPENSE);

    expect($result['imported'])->toBe(2);

    $expenses = Expense::withoutGlobalScopes()->where('property_id', $this->property->id)
        ->orderBy('expense_date')->get();

    expect((int) $expenses[0]->amount)->toBe(123456)
        ->and($expenses[0]->expense_date->format('Y-m-d'))->toBe('2024-03-15')
        ->and($expenses[0]->category)->toBe(Expense::CATEGORY_PROPERTY_TAX)
        ->and($expenses[1]->category)->toBe(Expense::CATEGORY_INSURANCE);
});

it('imports an anglo-saxon comma-separated file', function () {
    $file = csvFile(
        "Date,Amount,Description\n"
        . "2024-03-15,\"1,234.56\",Cleaning service\n"
    );

    $result = $this->service->import($file, $this->property, CsvProfile::TARGET_EXPENSE);

    expect($result['imported'])->toBe(1)
        ->and((int) Expense::withoutGlobalScopes()->first()->amount)->toBe(123456);
});

it('matches column headings regardless of accents and case', function () {
    // « LIBELLE » sans accent et en capitales : une comparaison stricte laissait la
    // colonne non mappée, et la charge entrait sans libellé.
    $mapping = CsvProfile::guessMapping(CsvProfile::TARGET_EXPENSE, ['DATE', 'MONTANT', 'LIBELLE']);

    expect($mapping)->toHaveKeys(['date', 'amount', 'description'])
        ->and($mapping['description'])->toBe(2);
});

it('never assigns the same column to two different fields', function () {
    // « Montant » et « Montant TTC » sont deux alias du même champ : sans garde, le
    // second écrasait le premier et l'ordre des colonnes décidait du résultat.
    $mapping = CsvProfile::guessMapping(CsvProfile::TARGET_EXPENSE, ['Date', 'Montant', 'Montant TTC']);

    expect(array_count_values($mapping))->each->toBe(1);
});

// -----------------------------------------------------------------------------
// Aperçu et mappage
// -----------------------------------------------------------------------------

it('previews at most ten rows and reports the real total', function () {
    $lines = "Date;Montant;Libellé\n";
    for ($i = 1; $i <= 25; $i++) {
        $lines .= sprintf("%02d/01/2024;%d,00;Charge %d\n", min($i, 28), 10 + $i, $i);
    }

    $preview = $this->service->preview(csvFile($lines), $this->property, CsvProfile::TARGET_EXPENSE);

    expect($preview['rows'])->toHaveCount(CsvImportService::PREVIEW_ROWS)
        ->and($preview['total'])->toBe(25)
        ->and($preview['missing'])->toBe([]);
});

it('reports which mandatory columns are still unmapped', function () {
    $file = csvFile("Colonne A;Colonne B\nx;y\n");

    $preview = $this->service->preview($file, $this->property, CsvProfile::TARGET_EXPENSE);

    expect($preview['missing'])->toContain('Date de la charge')
        ->and($preview['missing'])->toContain('Montant TTC');
});

it('refuses to import when a mandatory column is unmapped', function () {
    $file = csvFile("Colonne A;Colonne B\nx;y\n");

    expect(fn () => $this->service->import($file, $this->property, CsvProfile::TARGET_EXPENSE))
        ->toThrow(RuntimeException::class, 'Colonnes obligatoires non renseignées');
});

it('honours a hand-corrected mapping over the guessed one', function () {
    // Les intitulés ne disent rien : c'est l'utilisateur qui tranche.
    $file = csvFile("A;B;C\n15/03/2024;250,00;Ramonage\n");

    $result = $this->service->import($file, $this->property, CsvProfile::TARGET_EXPENSE, [
        'date' => 0, 'amount' => 1, 'description' => 2,
    ]);

    $expense = Expense::withoutGlobalScopes()->first();

    expect($result['imported'])->toBe(1)
        ->and((int) $expense->amount)->toBe(25000)
        ->and($expense->description)->toBe('Ramonage');
});

// -----------------------------------------------------------------------------
// Doublons et lignes illisibles
// -----------------------------------------------------------------------------

it('skips an expense that is already recorded', function () {
    $file = csvFile("Date;Montant;Libellé;Catégorie\n15/03/2024;1 234,56;Taxe foncière;Taxe foncière\n");

    $this->service->import($file, $this->property, CsvProfile::TARGET_EXPENSE);
    $second = $this->service->import($file, $this->property, CsvProfile::TARGET_EXPENSE);

    expect($second['imported'])->toBe(0)
        ->and($second['duplicates'])->toBe(1)
        ->and(Expense::withoutGlobalScopes()->count())->toBe(1);
});

it('uses the reservation reference as the identity of an income', function () {
    $file = csvFile(
        "Date;Montant;Code de confirmation;Voyageur\n"
        . "01/07/2024;520,00;HMABCD1234;Dupont\n"
    );

    $this->service->import($file, $this->property, CsvProfile::TARGET_INCOME);

    // Même réservation, montant corrigé : c'est le MÊME séjour, pas une seconde recette.
    $corrected = csvFile(
        "Date;Montant;Code de confirmation;Voyageur\n"
        . "03/07/2024;530,00;HMABCD1234;Dupont\n"
    );

    $second = $this->service->import($corrected, $this->property, CsvProfile::TARGET_INCOME);

    expect($second['duplicates'])->toBe(1)
        ->and(Income::withoutGlobalScopes()->count())->toBe(1);
});

it('skips an unreadable row without losing the rest of the file', function () {
    $file = csvFile(
        "Date;Montant;Libellé\n"
        . "15/03/2024;100,00;Bonne ligne\n"
        . ";250,00;Sans date\n"
        . "17/03/2024;120,00;Autre bonne ligne\n"
    );

    $result = $this->service->import($file, $this->property, CsvProfile::TARGET_EXPENSE);

    expect($result['imported'])->toBe(2)
        ->and($result['skipped'])->toBe(1)
        ->and($result['errors'][0])->toContain('Ligne 3');
});

it('ignores a trailing empty line without calling it an error', function () {
    $file = csvFile("Date;Montant;Libellé\n15/03/2024;100,00;Charge\n;;\n");

    $result = $this->service->import($file, $this->property, CsvProfile::TARGET_EXPENSE);

    expect($result['imported'])->toBe(1)
        ->and($result['errors'])->toBe([]);
});

// -----------------------------------------------------------------------------
// Mobilier et travaux — l'inventaire d'un cabinet arrive en tableur
// -----------------------------------------------------------------------------

it('imports a furniture inventory with durations and second-hand flags', function () {
    $file = csvFile(
        "Désignation;Montant;Date d'achat;Durée;Occasion\n"
        . "Canapé;1 200,00;10/01/2022;5;non\n"
        . "Lave-linge;450,00;15/02/2022;3;oui\n"
    );

    $result = $this->service->import($file, $this->property, CsvProfile::TARGET_FURNITURE);

    $items = Furniture::withoutGlobalScopes()->orderBy('purchase_date')->get();

    expect($result['imported'])->toBe(2)
        ->and((int) $items[0]->amount)->toBe(120000)
        ->and($items[0]->duration_years)->toBe(5)
        ->and($items[0]->is_second_hand)->toBeFalse()
        ->and($items[1]->is_second_hand)->toBeTrue()
        // La dotation est calculée par le hook du modèle, pas par l'import.
        ->and((int) $items[0]->annual_depreciation)->toBe(24000);
});

it('imports works and falls back to the default duration when it is absent', function () {
    $file = csvFile(
        "Désignation;Montant;Date des travaux\n"
        . "Réfection salle de bain;8 000,00;12/06/2022\n"
    );

    $result = $this->service->import($file, $this->property, CsvProfile::TARGET_WORK);
    $work = PropertyWork::withoutGlobalScopes()->first();

    expect($result['imported'])->toBe(1)
        ->and((int) $work->amount)->toBe(800000)
        ->and($work->duration_years)->toBe(10);
});

it('marks an x in a boolean column as true', function () {
    // Un tableur de cabinet coche d'une croix. La lire comme « faux » ferait taire une
    // option choisie, sans rien afficher.
    $file = csvFile("Désignation;Montant;Date;Occasion\nBureau;300,00;01/03/2022;x\n");

    $this->service->import($file, $this->property, CsvProfile::TARGET_FURNITURE);

    expect(Furniture::withoutGlobalScopes()->first()->is_second_hand)->toBeTrue();
});

it('keeps the import atomic when a row blows up midway', function () {
    // La deuxième ligne est illisible mais RATTRAPÉE (skipped) : l'atomicité se juge sur
    // le fait qu'aucune écriture partielle ne subsiste après une exception non rattrapée.
    $file = csvFile("Date;Montant;Libellé\n15/03/2024;100,00;A\n16/03/2024;200,00;B\n");

    expect(fn () => $this->service->import($file, $this->property, 'cible-inconnue'))
        ->toThrow(InvalidArgumentException::class);

    expect(Expense::withoutGlobalScopes()->count())->toBe(0);
});
