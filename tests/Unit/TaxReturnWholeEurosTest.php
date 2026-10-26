<?php

use App\Models\Income;
use App\Models\Property;
use App\Models\User;
use App\Services\DepreciationService;
use App\Services\FiscalYearService;
use App\Services\TaxReturnService;

/**
 * Issues #20 et #21 (cocool97) : la case 136 du bilan reprenait le résultat FISCAL, et les
 * formulaires mêlaient centimes (2033-B) et euros (2033-A, 2033-C).
 */
beforeEach(function () {
    $this->tax = app(TaxReturnService::class);
});

/** Formulaires en centimes, minimaux, pour éprouver `wholeEuros()` sans base de données. */
function centsForms(array $b, array $dotations, int $fiscal, ?array $bis = null): array
{
    $cat = fn (int $dotation) => ['lines' => [], 'brut' => 1000049, 'dotation' => $dotation, 'cumul' => $dotation * 3];
    $categories = ['constructions' => $cat($dotations[0]), 'autres' => $cat($dotations[1] ?? 0)];

    $b += ['218_brut' => $b['218'], '318' => 0, '350' => 0];
    $b['254'] = array_sum($dotations);
    $b['310'] = $b['218'] - $b['242'] - $b['244'] - $b['254'] - $b['294'];

    return [
        'form2031' => ['AB' => $b['218'], 'CB' => max($fiscal, 0), 'CC' => max(-$fiscal, 0)],
        'form2033A' => ['014' => 0, '016' => 0, '028' => 2000049, '030' => 600049, '156' => 900049, '180' => 0, '112' => 0, '044' => 0, '048' => 0, '120' => 0, '136' => 0],
        'form2033B' => $b + ['352' => max($fiscal, 0), '354' => max(-$fiscal, 0)],
        'form2033C' => ['categories' => $categories, 'total_brut' => 0, 'total_dotation' => 0, 'total_cumul' => 0],
        'form2033D' => ['982' => 0, '983' => 0, '984' => 0, '860' => 0, '870' => 0, 'suivi_39c' => ['ouverture' => 0, 'differes' => 0, 'repris' => 0, 'cloture' => 0]],
        'form2042' => ['case_benefice' => '5NA', 'case_deficit' => '5NY', 'montant' => abs($fiscal), 'is_benefice' => $fiscal >= 0],
        'form2031bis' => $bis,
    ];
}

it('prints the accounting result in box 136, not the capped fiscal result (#20)', function () {
    $user = User::factory()->create();
    $property = Property::forceCreate([
        'user_id' => $user->id, 'name' => 'Studio', 'address' => '1 rue A', 'city' => 'Lyon',
        'postal_code' => '69001', 'type' => 'apartment', 'total_area' => 30, 'rented_area' => 30,
        'acquisition_date' => '2022-01-01', 'acquisition_price' => 15000000, 'notary_fees' => 0,
        'agency_fees' => 0, 'land_percentage' => 15, 'rental_start_date' => '2022-01-01',
        'rental_type' => 'seasonal', 'is_primary_residence' => false,
    ]);
    app(DepreciationService::class)->generateDefaultComponents($property);
    // Loyers bien inférieurs à la dotation : l'art. 39 C en diffère une partie.
    Income::create(['property_id' => $property->id, 'income_date' => '2025-05-01', 'amount' => 62813,
        'tva_rate' => 0, 'platform_fee' => 0, 'tourist_tax' => 0, 'source' => 'airbnb']);
    $fy = app(FiscalYearService::class)->getOrCreate($user, 2025);
    app(FiscalYearService::class)->calculate($fy);
    $fy->refresh();

    $properties = collect([$property]);
    $b = $this->tax->compute2033B($fy, $properties, 2025);
    $a = $this->tax->compute2033A($fy, $properties, 2025);

    expect((int) $fy->fiscal_result)->toBe(0)
        ->and($b['318'])->toBeGreaterThan(0)
        ->and($b['310'])->toBeLessThan(0)
        ->and($a['136'])->toBe($b['310'])
        // Le bilan s'équilibre AVEC le résultat comptable : 120 n'absorbe plus la part différée.
        ->and($a['120'] + $a['136'] + $a['156'])->toBe($a['112']);

    $checks = collect($this->tax->checks($a, $b, $this->tax->compute2033C($properties, 2025), $properties))->keyBy('id');
    expect($checks['bilan']['status'])->toBe(TaxReturnService::CHECK_OK)
        ->and($checks['bilan']['message'])->toContain('case 136 du 2033-A = ligne 310 du 2033-B');

    // Et dans le PDF, à l'euro : -2 171,61 € y devenait 0 €.
    $html = view('pdf.tax-return', $this->tax->pdfData($fy))->render();
    expect($html)->toContain('Résultat exercice (136)</td><td class="r">' . number_format(round($b['310'] / 100), 0, ',', ' ') . ' €');
});

it('rounds every line to the euro and rebuilds totals from the rounded lines (#21)', function () {
    // Trois lignes à ,50 : arrondies une à une, elles ne font pas l'arrondi de leur somme.
    $forms = centsForms(['218' => 1000050, '242' => 100050, '244' => 50050, '294' => 20000], [279974, 0], 0);
    $forms['form2033B']['352'] = $forms['form2033B']['310'];

    $e = $this->tax->wholeEuros($forms);

    expect($e['form2033B']['254'])->toBe(280000)
        // 572 = 254 sur les montants IMPRIMÉS, plus seulement sur les centimes.
        ->and($e['form2033C']['total_dotation'])->toBe($e['form2033B']['254'])
        ->and($e['form2033B']['264'])->toBe($e['form2033B']['242'] + $e['form2033B']['244'] + $e['form2033B']['254'])
        ->and($e['form2033B']['310'])->toBe($e['form2033B']['218'] - $e['form2033B']['264'] - $e['form2033B']['294'])
        ->and($e['form2033B']['352'])->toBe($e['form2033B']['310'])
        ->and($e['form2042']['montant'])->toBe($e['form2033B']['352'])
        ->and($e['form2031']['CB'])->toBe($e['form2033B']['352']);

    foreach (['form2033A', 'form2033B', 'form2033D', 'form2031'] as $form) {
        foreach ($e[$form] as $line => $value) {
            if (is_int($value)) {
                expect($value % 100)->toBe(0, "$form $line");
            }
        }
    }
    expect($e['form2033A']['120'] + $e['form2033A']['136'] + $e['form2033A']['156'])->toBe($e['form2033A']['112'])
        ->and($e['form2033A']['136'])->toBe($e['form2033B']['310']);
});

it('keeps a capped result at zero: the rounding gap goes to line 318', function () {
    // Résultat avant amortissement 628,13 €, dotation 2 799,74 € : 2 171,61 € différés.
    $forms = centsForms(['218' => 62813, '242' => 0, '244' => 0, '294' => 0], [140037, 139937], 0);
    $forms['form2033B']['318'] = 217161;

    $e = $this->tax->wholeEuros($forms);

    expect($e['form2033B']['310'])->toBe(62800 - 140000 - 139900)
        ->and($e['form2033B']['352'])->toBe(0)
        ->and($e['form2033B']['354'])->toBe(0)
        ->and($e['form2033B']['310'] + $e['form2033B']['318'] - $e['form2033B']['350'])->toBe(0)
        ->and($e['form2042']['montant'])->toBe(0);
});

it('keeps the fiscal year result when part B did not add up to the cent', function () {
    // Exercice clôturé modifié après coup : les lignes ne retombent plus sur le résultat figé.
    $forms = centsForms(['218' => 500000, '242' => 0, '244' => 0, '294' => 0], [0, 0], 812345);

    $e = $this->tax->wholeEuros($forms);

    expect($e['form2033B']['352'])->toBe(812300)
        ->and($e['form2042']['montant'])->toBe(812300);
});

it('splits an indivision result in whole euros that add up to the printed result', function () {
    $bis = ['complete' => true, 'total_share_bp' => 10000, 'result' => 812345, 'rows' => [
        ['name' => 'A', 'share_bp' => 3333], ['name' => 'B', 'share_bp' => 3333], ['name' => 'C', 'share_bp' => 3334],
    ]];
    $forms = centsForms(['218' => 812345, '242' => 0, '244' => 0, '294' => 0], [0, 0], 812345, $bis);

    $rows = $this->tax->wholeEuros($forms)['form2031bis']['rows'];

    expect(array_sum(array_column($rows, 'benefice')))->toBe(812300);
    foreach ($rows as $row) {
        expect($row['benefice'] % 100)->toBe(0);
    }
});

it('prints amount columns in a font that has the minus sign (#21)', function () {
    // Courier (police de base de DomPDF pour `monospace`) n'a pas U+2212 : « − 0,00 € »
    // s'imprimait « ? 0,00 € ».
    $view = file_get_contents(resource_path('views/pdf/tax-return.blade.php'));

    expect($view)->toContain('.r { text-align: right; font-family: DejaVu Sans Mono')
        ->and(file_exists(base_path('vendor/dompdf/dompdf/lib/fonts/DejaVuSansMono.ttf')))->toBeTrue();
});

it('tracks deferred depreciation outside the 2033-D deficit frame, to the euro (#19)', function () {
    $forms = centsForms(['218' => 62813, '242' => 0, '244' => 0, '294' => 0], [140037, 139937], 0);
    $forms['form2033B']['318'] = 217161;
    $forms['form2033D']['suivi_39c'] = ['ouverture' => 100049, 'differes' => 217161, 'repris' => 0, 'cloture' => 317210];

    $d = $this->tax->wholeEuros($forms)['form2033D'];

    expect([$d['982'], $d['983'], $d['984'], $d['860'], $d['870']])->toBe([0, 0, 0, 0, 0])
        ->and($d['suivi_39c']['ouverture'])->toBe(100000)
        // 318 imprimée, écart d'arrondi compris : le suivi boucle sur ce qui est affiché.
        ->and($d['suivi_39c']['differes'])->toBe(217100)
        ->and($d['suivi_39c']['cloture'])->toBe(317100);
});
