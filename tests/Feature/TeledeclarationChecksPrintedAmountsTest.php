<?php

use App\Filament\Pages\Teledeclaration;
use App\Models\FiscalYear;
use App\Models\User;
use App\Services\DemoDataService;
use App\Services\TaxReturnService;
use Livewire\Livewire;

/**
 * OP 2815 : sur la démo publique (exercice 2025), la 2033-B imprimait 254 = 11 352 € et
 * 310 = −2 617 €, quand les « Contrôles de cohérence » de la même page annonçaient 11 353 €
 * et −2 618 €. Les contrôles tournent sur les centimes (c'est voulu : un écart d'un centime y
 * reste visible), mais leurs messages arrondissaient la SOMME des centimes, alors que la
 * liasse imprime la somme des lignes arrondies (`wholeEuros()`). Deux dotations à ,40 € :
 * 5 676,40 + 5 676,40 = 11 352,80 → 11 353 € ; 5 676 + 5 676 = 11 352 €.
 *
 * La liasse fait foi : un contrôle « vérifié » cite le montant imprimé.
 */
function euroText(int $cents): string
{
    return number_format($cents / 100, 0, ',', ' ') . ' €';
}

/** Formulaires en centimes, minimaux : deux catégories de dotation à ,40 €. */
function printedChecksForms(): array
{
    $cat = fn (int $dotation) => ['lines' => [], 'debut' => 1000000, 'augmentations' => 0, 'diminutions' => 0, 'brut' => 1000000,
        'cumul_debut' => 0, 'dotation' => $dotation, 'diminutions_amort' => 0, 'cumul' => $dotation];

    $b = ['218' => 900000, '242' => 0, '244' => 0, '294' => 0, '318' => 0, '350' => 0];
    $b['254'] = 567640 + 567640;
    $b['232'] = $b['218'];
    $b['264'] = $b['254'];
    $b['270'] = $b['232'] - $b['264'];
    $b['310'] = $b['270'] - $b['294'];
    $b['312'] = max($b['310'], 0);
    $b['314'] = max(-$b['310'], 0);
    $b['352'] = $b['370'] = max($b['310'], 0);
    $b['354'] = $b['372'] = max(-$b['310'], 0);

    return [
        'form2031' => ['AB' => $b['218'], 'CB' => $b['352'], 'CC' => $b['354']],
        'form2033A' => ['014' => 0, '016' => 0, '028' => 2000000, '030' => 1135280, '092' => 0, '156' => 0, '180' => 0,
            '110' => 0, '112' => 0, 'net' => 0, '044' => 2000000, '048' => 1135280, '120' => 0, '136' => $b['310']],
        'form2033B' => $b,
        'form2033C' => ['categories' => ['constructions' => $cat(567640), 'autres' => $cat(567640)],
            'total_brut' => 2000000, 'total_dotation' => 1135280, 'total_cumul' => 1135280],
        'form2033D' => ['982' => 0, '983' => 0, '984' => 0, '860' => 0, '870' => 0,
            'suivi_39c' => ['ouverture' => 0, 'differes' => 0, 'repris' => 0, 'cloture' => 0]],
        'form2042' => ['case_benefice' => '5NA', 'case_deficit' => '5NY', 'montant' => abs($b['310']), 'is_benefice' => false],
        'form2031bis' => null,
    ];
}

it('cites the printed 254 and 310 in the checks, not the rounded sum of cents (OP 2815)', function () {
    $tax = app(TaxReturnService::class);
    $forms = printedChecksForms();
    $printed = $tax->wholeEuros($forms);

    // Le scénario reproduit bien l'écart : la liasse et l'arrondi des centimes divergent.
    expect($printed['form2033B']['254'])->toBe(1135200)
        ->and(euroText($forms['form2033B']['254']))->toBe('11 353 €')
        ->and($printed['form2033B']['310'])->toBe(-235200)
        ->and(euroText($forms['form2033B']['310']))->toBe('-2 353 €');

    $checks = collect($tax->checks(
        $forms['form2033A'], $forms['form2033B'], $forms['form2033C'], [], null, $printed,
    ))->keyBy('id');

    expect($checks['dotation']['status'])->toBe(TaxReturnService::CHECK_OK)
        ->and($checks['dotation']['message'])->toContain('(11 352 €)')
        ->and($checks['bilan']['status'])->toBe(TaxReturnService::CHECK_OK)
        ->and($checks['bilan']['message'])->toContain('(-2 352 €)')
        ->and($checks['resultat']['status'])->toBe(TaxReturnService::CHECK_OK)
        ->and($checks['resultat']['message'])->toContain('(-2 352 €)');
});

it('shows on the demo the same amounts in the checks as on the 2033-B (OP 2815)', function () {
    $user = User::factory()->create(['is_demo' => true, 'demo_expires_at' => now()->addDay()]);
    app(DemoDataService::class)->seedForUser($user);
    $this->actingAs($user);

    $fy = FiscalYear::withoutGlobalScopes()->where('user_id', $user->id)->where('year', 2025)->firstOrFail();
    $tax = app(TaxReturnService::class);

    foreach (['PDF' => $tax->pdfData($fy), 'écran' => null] as $support => $data) {
        if ($data === null) {
            // L'écran, sur le même exercice : ses formulaires imprimés sont ceux du PDF.
            $page = Livewire::test(Teledeclaration::class)->set('year', 2025)->instance()->declarationData;
            $data = $tax->pdfData($fy->fresh());
            expect(array_column($page['checks'], 'status'))->toBe(array_column($data['checks'], 'status'));
            $data['checks'] = $page['checks'];
        }
        $checks = collect($data['checks'])->keyBy('id');

        expect($checks['dotation']['status'])->toBe(TaxReturnService::CHECK_OK, $support)
            ->and($checks['dotation']['message'])->toContain('(' . euroText($data['form2033B']['254']) . ')')
            ->and($checks['bilan']['status'])->toBe(TaxReturnService::CHECK_OK, $support)
            ->and($checks['bilan']['message'])->toContain('(' . euroText($data['form2033B']['310']) . ')')
            ->and($checks['immobilisations']['message'])->not->toContain('Écart');
    }
});
