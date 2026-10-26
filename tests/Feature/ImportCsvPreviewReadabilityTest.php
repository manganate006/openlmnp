<?php

use App\Filament\Pages\ImportCsv;
use App\Models\Expense;
use App\Models\User;
use App\Services\Csv\CsvImportService;
use App\Services\Csv\CsvProfile;
use Livewire\Livewire;

/**
 * L'écran de correspondance des colonnes : ce qu'il montre, et ce qu'il compte.
 *
 * Deux défauts distincts, tous deux visibles à l'œil nu et tous deux sans test :
 *
 * 1. **Il ne montrait jamais le fichier.** Les lignes rendues par `preview()` étaient
 *    construites APRÈS application du mappage, et l'en-tête réel ne servait qu'à deviner
 *    la correspondance. On demandait de vérifier un mappage sans rien donner à vérifier
 *    — « dans le select ce sont les champs disponibles ? chaque bloc correspond à une
 *    colonne du tableau ? ».
 *
 * 2. **Ses trois pastilles ne pouvaient pas être vraies en même temps.** Sur un fichier
 *    de 121 lignes, l'écran affichait « 60 à importer / 61 doublons / 20 lignes en
 *    erreur » : 141 lignes pour un fichier qui n'en a que 121. Deux mensonges
 *    superposés — « à importer » valait `total - doublons`, donc y comptait erreurs et
 *    lignes vides, et « en erreur » affichait la taille d'une liste TRONQUÉE à vingt,
 *    c'est-à-dire un plafond déguisé en décompte.
 */
beforeEach(function () {
    $this->user = User::factory()->create();
    $this->property = importCsvProperty($this->user);
    $this->service = app(CsvImportService::class);
});

/** Écrit un CSV sur le disque de test et rend son chemin. */
function csvOnDisk(string $content): string
{
    $path = 'imports/'.uniqid('olmnp-readability-').'.csv';
    \Illuminate\Support\Facades\Storage::disk()->put($path, $content);

    return \Illuminate\Support\Facades\Storage::disk()->path($path);
}

// ---------------------------------------------------------------------------------------
// Les compteurs : le défaut à élucider
// ---------------------------------------------------------------------------------------

it('partage exactement le total entre ses quatre compteurs', function () {
    // Six lignes de données : deux importables, une en doublon, deux illisibles
    // (date manquante), une entièrement vide.
    Expense::forceCreate([
        'property_id' => $this->property->id,
        'expense_date' => '2024-03-15',
        'amount' => 25000,
        'category' => \App\Models\Expense::CATEGORY_OTHER,
        'description' => 'Déjà là',
        'is_dedicated' => true,
    ]);

    $file = csvOnDisk(
        "Date;Montant;Libellé\n"
        ."15/03/2024;250,00;Déjà là\n"      // doublon
        ."16/03/2024;100,00;Ramonage\n"     // importable
        ."17/03/2024;120,00;Entretien\n"    // importable
        .";250,00;Sans date\n"              // erreur
        .";90,00;Sans date non plus\n"      // erreur
        .";;\n"                             // vide
    );

    $preview = $this->service->preview($file, $this->property, CsvProfile::TARGET_EXPENSE);

    expect($preview['total'])->toBe(6)
        ->and($preview['importable'])->toBe(2)
        ->and($preview['duplicates'])->toBe(1)
        ->and($preview['errors_total'])->toBe(2)
        ->and($preview['empty'])->toBe(1);

    // ⚠️ L'invariant qui rend les pastilles lisibles, et qu'aucune d'elles ne respectait.
    expect($preview['importable'] + $preview['duplicates'] + $preview['errors_total'] + $preview['empty'])
        ->toBe($preview['total']);
});

it('compte les lignes en erreur au lieu d\'afficher le plafond de la liste', function () {
    // Vingt-cinq lignes sans date : la liste d'exemples en garde vingt, le compteur
    // doit en annoncer vingt-cinq. C'est très exactement le « 20 » de la capture.
    $lines = "Date;Montant;Libellé\n";
    for ($i = 1; $i <= 25; $i++) {
        $lines .= ";{$i}0,00;Ligne {$i}\n";
    }

    $preview = $this->service->preview(csvOnDisk($lines), $this->property, CsvProfile::TARGET_EXPENSE);

    expect($preview['errors'])->toHaveCount(20)
        ->and($preview['errors_total'])->toBe(25)
        ->and($preview['total'])->toBe(25)
        ->and($preview['importable'])->toBe(0);
});

it('affiche le décompte réel et signale que la liste est tronquée', function () {
    $lines = "Date;Montant;Libellé\n";
    for ($i = 1; $i <= 25; $i++) {
        $lines .= ";{$i}0,00;Ligne {$i}\n";
    }

    Livewire::actingAs($this->user)
        ->test(ImportCsv::class)
        ->set('data.property_id', $this->property->id)
        ->set('data.target', CsvProfile::TARGET_EXPENSE)
        ->set('data.csv_file', uploadedCsv($lines))
        ->call('preview')
        ->assertSee('25 ligne(s) en erreur')
        ->assertSee('les 20 premières sur')
        ->assertDontSee('20 ligne(s) en erreur');
});

it('rend aussi le décompte réel des erreurs après import', function () {
    $lines = "Date;Montant;Libellé\n15/03/2024;100,00;Bonne ligne\n";
    for ($i = 1; $i <= 25; $i++) {
        $lines .= ";{$i}0,00;Ligne {$i}\n";
    }

    $result = $this->service->import(csvOnDisk($lines), $this->property, CsvProfile::TARGET_EXPENSE);

    expect($result['errors'])->toHaveCount(20)
        ->and($result['errors_total'])->toBe(25)
        ->and($result['imported'])->toBe(1);
});

it('sépare lignes illisibles et lignes vides dans le résultat d\'import', function () {
    $result = $this->service->import(
        csvOnDisk(
            "Date;Montant;Libellé\n"
            ."15/03/2024;100,00;Bonne ligne\n"
            .";250,00;Sans date\n"
            .";;\n"
        ),
        $this->property,
        CsvProfile::TARGET_EXPENSE,
    );

    expect($result['imported'])->toBe(1)
        ->and($result['errors_total'])->toBe(1)
        ->and($result['empty'])->toBe(1)
        // `skipped` conserve son sens historique — les deux réunies — pour les appelants
        // qui s'en servent déjà.
        ->and($result['skipped'])->toBe(2);

    // Et la notification nomme les deux séparément, au lieu de tout appeler « illisible ».
    $page = file_get_contents(app_path('Filament/Pages/ImportCsv.php'));

    expect($page)->toContain("ligne(s) illisible(s)")
        ->and($page)->toContain("ligne(s) vide(s) ignorée(s)")
        ->and($page)->not->toContain("{\$result['skipped']} ligne(s) illisible(s)");
});

// ---------------------------------------------------------------------------------------
// Ce que l'écran montre
// ---------------------------------------------------------------------------------------

it('rend les dix premières lignes du fichier, intactes', function () {
    $lines = "Date facture;Montant TTC;Libellé\n";
    for ($i = 1; $i <= 15; $i++) {
        $lines .= sprintf("%02d/01/2024;%d,00;Charge %d\n", $i, 10 + $i, $i);
    }

    $preview = $this->service->preview(csvOnDisk($lines), $this->property, CsvProfile::TARGET_EXPENSE);

    expect($preview['raw_rows'])->toHaveCount(CsvImportService::RAW_PREVIEW_ROWS)
        // Brutes : telles que le fichier les porte, avant toute conversion.
        ->and($preview['raw_rows'][0])->toBe(['01/01/2024', '11,00', 'Charge 1'])
        ->and($preview['header'])->toBe(['Date facture', 'Montant TTC', 'Libellé']);
});

it('montre le fichier AVANT de demander la correspondance', function () {
    $html = Livewire::actingAs($this->user)
        ->test(ImportCsv::class)
        ->set('data.property_id', $this->property->id)
        ->set('data.target', CsvProfile::TARGET_EXPENSE)
        ->set('data.csv_file', uploadedCsv("Date facture;Montant TTC;Libellé\n15/03/2024;1 234,56;Taxe foncière\n"))
        ->call('preview')
        ->html();

    // L'en-tête réel du fichier est affiché…
    expect($html)->toContain('Date facture')
        ->and($html)->toContain('Montant TTC');

    // …avec ses valeurs, TELLES QUELLES : « 1 234,56 » et non « 1 234,56 € ». Un titre
    // de carte sans lignes dessous passerait ce test sans rien montrer.
    expect($html)->toContain('<td>1 234,56</td>');

    // …et l'ordre compte autant que la présence : vérifier une correspondance qu'on
    // découvre après coup ne sert à rien.
    expect(strpos($html, 'Votre fichier'))->toBeLessThan(strpos($html, 'Correspondance des colonnes'))
        ->and(strpos($html, '<td>1 234,56</td>'))->toBeLessThan(strpos($html, 'Correspondance des colonnes'));
});

it('montre un exemple de valeur à côté de chaque champ', function () {
    // La demande explicite : en face de « Date de la charge », lire `15/03/2024`.
    $component = Livewire::actingAs($this->user)
        ->test(ImportCsv::class)
        ->set('data.property_id', $this->property->id)
        ->set('data.target', CsvProfile::TARGET_EXPENSE)
        ->set('data.csv_file', uploadedCsv("Date;Montant;Libellé\n15/03/2024;1 234,56;Taxe foncière\n"))
        ->call('preview');

    expect($component->get('previewData')['samples'])->toBe([
        0 => '15/03/2024', 1 => '1 234,56', 2 => 'Taxe foncière',
    ]);

    $component->assertSee('Exemple : 15/03/2024')
        ->assertSee('Exemple : Taxe foncière');
});

it('suit l\'exemple quand l\'utilisateur corrige la correspondance', function () {
    // Un exemple figé au premier aperçu ne vaudrait rien : c'est PENDANT la correction
    // qu'on a besoin de voir ce que la colonne choisie contient.
    $component = Livewire::actingAs($this->user)
        ->test(ImportCsv::class)
        ->set('data.property_id', $this->property->id)
        ->set('data.target', CsvProfile::TARGET_EXPENSE)
        ->set('data.csv_file', uploadedCsv("A;B;C\n15/03/2024;250,00;Ramonage\n"))
        ->call('preview');

    // Aucun intitulé reconnaissable : rien n'est mappé, donc aucun exemple.
    expect($component->instance()->sampleFor('date'))->toBeNull();

    $component->set('mapping.date', '0')->call('refreshPreview');

    expect($component->instance()->sampleFor('date'))->toBe('15/03/2024');
});

it('cherche l\'exemple au-delà d\'une première ligne vide', function () {
    // Un tableur de cabinet commence souvent par une ligne de sous-titres vides :
    // s'arrêter à la première ligne ne montrerait aucun exemple exploitable.
    $preview = $this->service->preview(
        csvOnDisk("Date;Montant;Libellé\n;;\n15/03/2024;250,00;Ramonage\n"),
        $this->property,
        CsvProfile::TARGET_EXPENSE,
    );

    expect($preview['samples'])->toBe([0 => '15/03/2024', 1 => '250,00', 2 => 'Ramonage']);
});

it('rend les deux tableaux d\'aperçu défilables sans les tronquer', function () {
    // « Tronqué à gauche » : les en-têtes étaient coupés et les colonnes de droite
    // invisibles, sans rien pour indiquer qu'il fallait faire défiler.
    $view = file_get_contents(resource_path('views/filament/pages/import-csv.blade.php'));

    expect($view)->toContain('.csvi-wrap { overflow-x: auto; max-width: 100%; }')
        // Les cellules ne s'écrasent plus : c'est le conteneur qui défile.
        ->toContain('min-width: max-content')
        // La première colonne reste visible pendant le défilement, sans quoi on ne sait
        // plus de quelle ligne on lit les valeurs.
        ->toContain('.csvi-table th:first-child, .csvi-table td:first-child { position: sticky; left: 0;')
        // Et le défilement est ANNONCÉ : une barre qui n'apparaît qu'au survol ne
        // s'annonce pas toute seule.
        ->toContain('faites-le défiler horizontalement');
});

it('décrit le parcours que le code exécute vraiment', function () {
    // La doc et le code se contredisaient : le docblock annonçait un aperçu de dix
    // lignes avant le mappage, que le code ne produisait pas.
    $page = file_get_contents(app_path('Filament/Pages/ImportCsv.php'));

    expect($page)->toContain('aperçu BRUT de dix lignes → mappage des colonnes');
});

// ---------------------------------------------------------------------------------------
// Les répétitions internes au fichier
// ---------------------------------------------------------------------------------------

it('annonce ce que l\'import écrira vraiment quand le fichier se répète lui-même', function () {
    // Constaté sur la bêta le 2026-09-24 : une ligne répétée À L'IDENTIQUE dans le même
    // fichier. L'aperçu annonçait « 3 à importer / 0 doublon », l'import n'écrivait que 2
    // lignes — il voit la première en base au moment d'écrire la seconde, l'aperçu ne
    // regardait que la base, vide au premier import.
    $file = csvOnDisk(
        "Date;Montant;Libellé\n"
        ."16/03/2024;100,00;Ramonage\n"
        ."17/03/2024;120,00;Entretien\n"
        ."16/03/2024;100,00;Ramonage\n"     // répétition de la première ligne
    );

    $preview = $this->service->preview($file, $this->property, CsvProfile::TARGET_EXPENSE);

    expect($preview['importable'])->toBe(2)
        ->and($preview['duplicates'])->toBe(1)
        ->and($preview['repeats'])->toBe(1)
        ->and($preview['importable'] + $preview['duplicates'] + $preview['errors_total'] + $preview['empty'])
        ->toBe($preview['total']);

    $result = $this->service->import($file, $this->property, CsvProfile::TARGET_EXPENSE);

    expect($result['imported'])->toBe($preview['importable'])
        ->and($result['duplicates'])->toBe($preview['duplicates']);
});

it('distingue un doublon de la base d\'une répétition interne au fichier', function () {
    Expense::forceCreate([
        'property_id' => $this->property->id,
        'expense_date' => '2024-03-15',
        'amount' => 25000,
        'category' => \App\Models\Expense::CATEGORY_OTHER,
        'description' => 'Déjà là',
        'is_dedicated' => true,
    ]);

    $file = csvOnDisk(
        "Date;Montant;Libellé\n"
        ."15/03/2024;250,00;Déjà là\n"      // doublon de la base
        ."15/03/2024;250,00;Déjà là\n"      // toujours un doublon de la base, pas une répétition
        ."16/03/2024;100,00;Ramonage\n"
        ."16/03/2024;100,00;Ramonage\n"     // répétition interne
    );

    $preview = $this->service->preview($file, $this->property, CsvProfile::TARGET_EXPENSE);

    expect($preview['importable'])->toBe(1)
        ->and($preview['duplicates'])->toBe(3)
        ->and($preview['repeats'])->toBe(1);
});

it('repère une recette répétée par sa référence de réservation', function () {
    $file = csvOnDisk(
        "Date;Montant;Référence\n"
        ."16/03/2024;100,00;HMABC123\n"
        ."18/03/2024;100,00;HMABC123\n"     // même séjour, date différente
    );

    $preview = $this->service->preview($file, $this->property, CsvProfile::TARGET_INCOME);

    expect($preview['importable'])->toBe(1)
        ->and($preview['repeats'])->toBe(1);
});
