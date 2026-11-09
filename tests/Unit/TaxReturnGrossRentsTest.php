<?php

use App\Models\Expense;
use App\Models\FiscalYear;
use App\Models\Income;
use App\Models\Property;
use App\Models\User;
use App\Services\FiscalYearService;
use App\Services\TaxReturnService;

/**
 * Ligne 218 du 2033-B en loyers BRUTS (décision du 30/10/2026).
 *
 * Avant : 218 = loyers − commissions de plateforme, et les commissions ne figuraient sur
 * aucune ligne de charge. Le résultat était juste, mais le chiffre d'affaires déclaré était
 * minoré des commissions. Désormais 218 porte les loyers bruts (HT pour un bien assujetti à
 * la TVA), et les commissions vont en 242 (autres charges externes), SANS quote-part : ce sont
 * des frais propres aux recettes du bien, comme les recettes elles-mêmes.
 *
 * Le résultat ne bouge pas d'un centime : 310, 352 et le résultat de l'exercice restent ceux
 * d'avant. `total_income` (net) ne change pas non plus : il sert au plafonnement art. 39 C.
 *
 * Montants synthétiques uniquement.
 */
beforeEach(function () {
    $this->user = User::factory()->create();
    $this->fiscal = app(FiscalYearService::class);
    $this->tax = app(TaxReturnService::class);
});

function grossRentsProperty(User $user, array $extra = []): Property
{
    return Property::forceCreate(array_merge([
        'user_id' => $user->id,
        'name' => 'Studio test',
        'address' => '1 rue Fictive',
        'city' => 'Lyon',
        'postal_code' => '69003',
        'type' => 'apartment',
        'total_area' => 100,
        'rented_area' => 45,          // quote-part 45 %
        'acquisition_date' => '2018-01-01',
        'acquisition_price' => 20000000,
        'notary_fees' => 0,
        'agency_fees' => 0,
        'land_percentage' => 0,
        'rental_start_date' => '2018-06-01',
        'rental_type' => 'seasonal',
        'is_primary_residence' => false,
    ], $extra));
}

function grossRentsIncome(Property $property, string $date, int $amount, int $fee, int $tvaRate = 0): void
{
    Income::create([
        'property_id' => $property->id,
        'income_date' => $date,
        'amount' => $amount,
        'tva_rate' => $tvaRate,
        'platform_fee' => $fee,
        'tourist_tax' => 0,
        'source' => 'airbnb',
    ]);
}

function grossRentsExpense(Property $property, int $amount, bool $dedicated, int $tvaRate = 0): void
{
    Expense::create([
        'property_id' => $property->id,
        'expense_date' => '2026-02-15',
        'amount' => $amount,
        'tva_rate' => $tvaRate,
        'category' => Expense::CATEGORY_ENERGY,
        'description' => 'Charge test',
        'is_dedicated' => $dedicated,
        'recurring_type' => 'once',
    ]);
}

function grossRentsYear(User $user): FiscalYear
{
    return FiscalYear::forceCreate([
        'user_id' => $user->id,
        'year' => 2026,
        'status' => FiscalYear::STATUS_DRAFT,
    ]);
}

it('declares gross rents on line 218 and the platform fees on line 242, without quota share', function () {
    $property = grossRentsProperty($this->user);
    grossRentsIncome($property, '2026-03-10', 250000, 7500);
    grossRentsIncome($property, '2026-04-10', 120001, 3601);
    grossRentsExpense($property, 33333, false);           // partagée : × 45 %

    $fy = grossRentsYear($this->user);
    $this->fiscal->calculate($fy);
    $fy->refresh();

    $form = $this->tax->compute2033B($fy, collect([$property]), 2026);
    $sharedRetained = (int) bcmul('33333', $property->quota_share, 0);

    expect($form['218'])->toBe(370001)
        ->and($form['232'])->toBe($form['218'])
        // Commissions retenues en totalité : 7 500 + 3 601, aucune quote-part.
        ->and($form['242'])->toBe($sharedRetained + 11101)
        ->and($form)->not->toHaveKey('218_brut')
        // `total_income` reste net : c'est lui qui sert au plafonnement art. 39 C.
        ->and((int) $fy->total_income)->toBe(370001 - 11101);
});

it('keeps the accounting and fiscal results unchanged to the cent', function () {
    $property = grossRentsProperty($this->user);
    grossRentsIncome($property, '2026-03-10', 250000, 7500);
    grossRentsExpense($property, 33333, false);
    grossRentsExpense($property, 12000, true);

    $fy = grossRentsYear($this->user);
    $this->fiscal->calculate($fy);
    $fy->refresh();

    $properties = collect([$property]);
    $form = $this->tax->compute2033B($fy, $properties, 2026);

    // Avant le changement : 310 = loyers nets − charges retenues (bien sans composant,
    // aucun amortissement), soit exactement total_income − total_expenses.
    $before310 = (int) $fy->total_income - (int) $fy->total_expenses;

    expect($form['254'])->toBe(0)
        ->and($form['310'])->toBe($before310)
        ->and($form['352'] - $form['354'])->toBe((int) $fy->fiscal_result)
        ->and($form['310'] + $form['318'] - $form['350'])->toBe($form['352'] - $form['354']);

    $checks = collect($this->tax->checks(
        $this->tax->compute2033A($fy, $properties, 2026, $form['310']),
        $form,
        $this->tax->compute2033C($properties, 2026),
        $properties,
    ))->keyBy('id');

    expect($checks['resultat']['status'])->toBe(TaxReturnService::CHECK_OK);
});

it('uses gross rents before VAT for a property liable to VAT', function () {
    $property = grossRentsProperty($this->user, ['tva_regime' => Property::TVA_LIABLE, 'rented_area' => 100]);
    grossRentsIncome($property, '2026-06-01', 110000, 3000, 1000);      // 10 % : HT 100 000
    grossRentsExpense($property, 24000, true, 2000);                   // 20 % : HT 20 000

    $fy = grossRentsYear($this->user);
    $this->fiscal->calculate($fy);
    $fy->refresh();

    $form = $this->tax->compute2033B($fy, collect([$property]), 2026);

    expect($form['218'])->toBe(100000)
        ->and($form['242'])->toBe(20000 + 3000)
        ->and($form['310'])->toBe(100000 - 3000 - 20000)
        ->and($form['310'])->toBe((int) $fy->total_income - (int) $fy->total_expenses);
});

it('reports the same turnover on the 2031 as on line 218, in the PDF and its annex', function () {
    $property = grossRentsProperty($this->user, ['rented_area' => 100]);
    grossRentsIncome($property, '2026-03-10', 250000, 7500);

    $fy = grossRentsYear($this->user);
    $data = $this->tax->pdfData($fy);

    expect($data['form2033B']['218'])->toBe(250000)
        ->and($data['form2033B']['242'])->toBe(7500)
        ->and($data['form2031']['AB'])->toBe($data['form2033B']['218']);

    $html = view('pdf.tax-return', $data)->render();

    expect($html)->toContain('Production vendue — Services (loyers bruts)')
        ->toContain('Commissions des plateformes (ligne 242, sans quote-part)')
        ->not->toContain('Loyers nets retenus')
        ->not->toContain('Commissions des plateformes déduites');
});
