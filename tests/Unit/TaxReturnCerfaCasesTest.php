<?php

use App\Filament\Pages\Teledeclaration;
use App\Models\Furniture;
use App\Models\Property;
use App\Models\User;
use App\Services\DepreciationService;
use App\Services\FiscalYearService;
use App\Services\TaxReturnService;
use Livewire\Livewire;

/**
 * Numéros de case du 2033-A et du 2033-C, relevés le 2026-10-29 sur la liasse d'un cabinet.
 *
 *  - 2033-A : la case 110 est le total général BRUT (= 044), la case 112 le total général
 *    des AMORTISSEMENTS (= 048). Le total net de l'actif n'a pas de numéro. L'application
 *    rangeait le net sous « 112 » : recopié, il atterrissait dans la case des amortissements.
 *  - 2033-C : quatre colonnes par ligne (début, augmentations, diminutions, fin), numérotées
 *    +0, +2, +4, +6 à partir de 410…490 (valeurs brutes) et 500…570 (amortissements).
 *    L'application affichait les valeurs de FIN sous les numéros du DÉBUT, et n'avait ni la
 *    colonne début ni la colonne augmentations.
 *
 * Montants synthétiques.
 */
function cerfaCasesProperty(User $user, array $overrides = []): Property
{
    $property = Property::forceCreate(array_merge([
        'user_id' => $user->id,
        'name' => 'Bien cases',
        'address' => '1 rue Test',
        'city' => 'Paris',
        'postal_code' => '75001',
        'type' => 'apartment',
        'total_area' => 100,
        'rented_area' => 100,
        'acquisition_date' => '2023-03-01',
        'acquisition_price' => 20000000,
        'notary_fees' => 1500000,
        'agency_fees' => 0,
        'market_value' => null,
        'land_percentage' => 15,
        'rental_start_date' => '2023-07-01',
        'rental_type' => 'seasonal',
        'is_primary_residence' => false,
    ], $overrides));
    app(DepreciationService::class)->generateDefaultComponents($property);

    return $property->fresh();
}

function cerfaCasesFurniture(Property $property, string $date, int $amount): void
{
    Furniture::create([
        'property_id' => $property->id,
        'description' => 'Meuble ' . $date,
        'amount' => $amount,
        'purchase_date' => $date,
        'duration_years' => 5,
        'is_dedicated' => true,
    ]);
}

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->tax = app(TaxReturnService::class);
});

it('puts the gross total in box 110 and the depreciation total in box 112 of the 2033-A', function () {
    cerfaCasesProperty($this->user);
    $properties = Property::withoutGlobalScopes()->where('user_id', $this->user->id)->get();
    $fy = app(FiscalYearService::class)->getOrCreate($this->user, 2025);

    $a = $this->tax->compute2033A($fy, $properties, 2025);

    expect($a['048'])->toBeGreaterThan(0)
        ->and($a['110'])->toBe($a['044'])
        ->and($a['112'])->toBe($a['048'])
        ->and($a['net'])->toBe($a['044'] - $a['048'])
        ->and($a['180'])->toBe($a['net'])
        ->and($a['120'] + $a['136'] + $a['156'])->toBe($a['net']);
});

it('numbers every column of the 2033-C as the Cerfa does', function () {
    $c = $this->tax->compute2033C(collect(), 2025);

    expect($c['categories']['incorporelles']['lines'])->toBe([
        'immo' => ['debut' => '410', 'augmentations' => '412', 'diminutions' => '414', 'fin' => '416'],
        'amort' => ['debut' => '500', 'dotations' => '502', 'diminutions' => '504', 'fin' => '506'],
    ])
        ->and($c['categories']['terrains']['lines']['immo']['fin'])->toBe('426')
        ->and($c['categories']['terrains']['lines']['amort'])->toBeNull()
        ->and($c['categories']['constructions']['lines']['immo']['fin'])->toBe('436')
        ->and($c['categories']['constructions']['lines']['amort']['fin'])->toBe('526')
        ->and($c['categories']['installations']['lines']['amort']['dotations'])->toBe('532')
        ->and($c['categories']['agencements']['lines']['immo']['augmentations'])->toBe('452')
        ->and($c['categories']['autres']['lines']['immo']['fin'])->toBe('476')
        ->and($c['categories']['autres']['lines']['amort']['fin'])->toBe('566')
        ->and($c['total_lines'])->toBe([
            'immo' => ['debut' => '490', 'augmentations' => '492', 'diminutions' => '494', 'fin' => '496'],
            'amort' => ['debut' => '570', 'dotations' => '572', 'diminutions' => '574', 'fin' => '576'],
        ]);
});

it('splits the gross value between the opening and the additions of the year', function () {
    // Bien mis en location en 2023, meuble acheté en 2025 : pour l'exercice 2025, le bien
    // (terrain, composants, frais) est au début, le meuble est une augmentation.
    $property = cerfaCasesProperty($this->user);
    cerfaCasesFurniture($property, '2025-04-15', 600000);
    $properties = collect([$property->fresh()]);

    $c = $this->tax->compute2033C($properties, 2025);
    $previous = $this->tax->compute2033C(
        collect([$property->fresh()->setRelation('furniture', collect())]),
        2024,
    );

    expect($c['categories']['autres']['debut'])->toBe(0)
        ->and($c['categories']['autres']['augmentations'])->toBe(600000)
        ->and($c['categories']['autres']['brut'])->toBe(600000)
        ->and($c['categories']['autres']['diminutions'])->toBe(0)
        // Le début de l'exercice, c'est la fin du précédent.
        ->and($c['total_debut'])->toBe($previous['total_brut'])
        ->and($c['total_cumul_debut'])->toBe($previous['total_cumul'])
        ->and($c['categories']['terrains']['debut'])->toBe($c['categories']['terrains']['brut'])
        ->and($c['categories']['constructions']['augmentations'])->toBe(0)
        ->and($c['total_augmentations'])->toBe(600000)
        ->and($c['total_diminutions'])->toBe(0)
        ->and($c['total_diminutions_amort'])->toBe(0);

    foreach ($c['categories'] as $key => $cat) {
        expect($cat['debut'] + $cat['augmentations'] - $cat['diminutions'])->toBe($cat['brut'], $key)
            ->and($cat['cumul_debut'])->toBe($cat['cumul'] - $cat['dotation'], $key);
    }
    expect($c['total_debut'] + $c['total_augmentations'])->toBe($c['total_brut'])
        ->and($c['total_cumul_debut'] + $c['total_dotation'])->toBe($c['total_cumul']);
});

it('puts everything in the additions during the first year', function () {
    $property = cerfaCasesProperty($this->user, ['rental_start_date' => '2025-07-01']);
    cerfaCasesFurniture($property, '2025-07-01', 400000);

    $c = $this->tax->compute2033C(collect([$property->fresh()]), 2025);

    expect($c['total_brut'])->toBeGreaterThan(0)
        ->and($c['total_debut'])->toBe(0)
        ->and($c['total_augmentations'])->toBe($c['total_brut'])
        ->and($c['total_cumul_debut'])->toBe(0)
        ->and($c['total_cumul'])->toBe($c['total_dotation']);
});

it('keeps the 2033-C identities to the euro after rounding', function () {
    // Montants à ,50 et ,49 : arrondies séparément, les quatre colonnes ne bouclaient pas.
    $property = cerfaCasesProperty($this->user, ['acquisition_price' => 20000149, 'notary_fees' => 1500051]);
    cerfaCasesFurniture($property, '2024-02-10', 300050);
    cerfaCasesFurniture($property, '2025-05-20', 250049);
    $user = $this->user;
    $properties = collect([$property->fresh()]);
    $fy = app(FiscalYearService::class)->getOrCreate($user, 2025);

    $b = $this->tax->compute2033B($fy, $properties, 2025);
    $c = $this->tax->compute2033C($properties, 2025);
    $a = $this->tax->compute2033A($fy, $properties, 2025, $b['310']);
    $bis = null;
    $e = $this->tax->wholeEuros([
        'form2031' => ['AB' => $b['218'], 'CB' => $b['352'], 'CC' => $b['354']],
        'form2033A' => $a,
        'form2033B' => $b,
        'form2033C' => $c,
        'form2033D' => ['suivi_39c' => ['ouverture' => 0, 'differes' => 0, 'repris' => 0, 'cloture' => 0]],
        'form2042' => ['montant' => 0, 'is_benefice' => true],
        'form2031bis' => $bis,
    ]);
    $ec = $e['form2033C'];

    foreach ($ec['categories'] as $key => $cat) {
        foreach (TaxReturnService::COLUMNS_2033C as $col) {
            expect($cat[$col] % 100)->toBe(0, "$key $col");
        }
        // Valeurs déjà imprimées avant : inchangées (arrondi de la valeur au centime).
        expect($cat['brut'])->toBe((int) round($c['categories'][$key]['brut'] / 100) * 100, $key)
            ->and($cat['cumul'])->toBe((int) round($c['categories'][$key]['cumul'] / 100) * 100, $key)
            ->and($cat['debut'] + $cat['augmentations'] - $cat['diminutions'])->toBe($cat['brut'], $key)
            ->and($cat['cumul_debut'] + $cat['dotation'] - $cat['diminutions_amort'])->toBe($cat['cumul'], $key)
            ->and($cat['augmentations'])->toBeGreaterThanOrEqual(0, $key)
            ->and($cat['cumul_debut'])->toBeGreaterThanOrEqual(0, $key);
    }
    expect($ec['total_debut'] + $ec['total_augmentations'] - $ec['total_diminutions'])->toBe($ec['total_brut'])
        ->and($ec['total_cumul_debut'] + $ec['total_dotation'] - $ec['total_diminutions_amort'])->toBe($ec['total_cumul'])
        // Et les égalités du Cerfa entre formulaires : 044 = 496, 048 = 576 (sans reliquat).
        ->and($e['form2033A']['110'])->toBe($e['form2033A']['044'])
        ->and($e['form2033A']['112'])->toBe($e['form2033A']['048'])
        ->and($e['form2033A']['net'])->toBe($e['form2033A']['044'] - $e['form2033A']['048'])
        ->and($e['form2033A']['120'] + $e['form2033A']['136'] + $e['form2033A']['156'])->toBe($e['form2033A']['net']);
});

it('shows every 2033-C column under its own box on the Teledeclaration screen', function () {
    $property = cerfaCasesProperty($this->user, ['rental_start_date' => '2023-01-01']);
    cerfaCasesFurniture($property, '2024-06-01', 500000);
    $fy = app(FiscalYearService::class)->getOrCreate($this->user, 2024);
    app(FiscalYearService::class)->calculate($fy);
    $this->actingAs($this->user);

    // `mount()` lit l'année dans la requête : on la pose ensuite, comme le sélecteur d'année.
    $page = Livewire::test(Teledeclaration::class)->set('year', 2024);
    $forms = $page->instance()->declarationData['forms'];

    $a = collect($forms['2033-A']['lines'])->keyBy('line');
    $c = collect($forms['2033-C']['lines'])->keyBy('line');

    // 2033-A : 110/112 sont les totaux brut et amortissements, plus de « 112 Total actif ».
    expect($a['110']['raw'])->toBe($a['044']['raw'])
        ->and($a['112']['raw'])->toBe($a['048']['raw'])
        ->and($a['112']['desc'])->not->toContain('Total actif')
        ->and($a['—']['raw'])->toBe($a['044']['raw'] - $a['048']['raw'])
        ->and($a['—']['desc'])->toContain('sans numéro');

    // 2033-C : chaque colonne sous son numéro ; la fin en 4x6 / 496 / 576.
    foreach (['410', '412', '414', '416', '420', '426', '436', '476', '490', '492', '494', '496', '500', '506', '566', '570', '572', '574', '576'] as $case) {
        expect($c->has($case))->toBeTrue("case $case absente");
    }
    expect($c->has('510'))->toBeFalse()
        ->and($c['496']['raw'])->toBe($a['044']['raw'])
        ->and($c['576']['raw'])->toBe($a['048']['raw'])
        ->and($c['572']['raw'])->toBe($c['576']['raw'] - $c['570']['raw'])
        ->and($c['476']['raw'])->toBe(500000)
        ->and($c['472']['raw'])->toBe(500000)
        ->and($c['470']['raw'])->toBe(0)
        ->and($c['490']['raw'] + $c['492']['raw'])->toBe($c['496']['raw']);

    $page->assertSee('Cadre I')
        ->assertSee('Cadre II')
        ->assertDontSee('490/572/570');

    // L'export CSV parcourt les mêmes lignes : une ligne par case, sous son vrai numéro.
    $page->call('exportCsv')->assertFileDownloaded('declaration_lmnp_2024.csv');
    $csv = base64_decode((string) data_get($page->effects, 'download.content'));
    expect($csv)->toContain("\n2033-C;416;")
        ->toContain("\n2033-C;496;")
        ->toContain("\n2033-C;576;")
        ->toContain("\n2033-A;110;")
        ->toContain("\n2033-A;112;")
        ->not->toContain('Total actif');
});

it('labels the PDF boxes with their real numbers', function () {
    cerfaCasesProperty($this->user, ['rental_start_date' => '2023-01-01']);
    $fy = app(FiscalYearService::class)->getOrCreate($this->user, 2024);

    $html = view('pdf.tax-return', $this->tax->pdfData($fy))->render();

    expect($html)->not->toContain('Total actif (112)')
        ->not->toContain('490/572/570')
        ->toContain('Total général brut (110)')
        ->toContain('Total général amortissements (112)')
        ->toContain('(496)')
        ->toContain('(576)');
});
