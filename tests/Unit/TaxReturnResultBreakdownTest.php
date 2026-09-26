<?php

use App\Models\Expense;
use App\Models\FiscalYear;
use App\Models\Income;
use App\Models\Loan;
use App\Models\LoanPayment;
use App\Models\Property;
use App\Models\User;
use App\Services\DepreciationService;
use App\Services\FiscalYearService;
use App\Services\TaxReturnService;

/**
 * Détail du résultat (issue #13) — le 2033-B doit se refaire à la main depuis l'annexe, et
 * retomber sur le résultat fiscal de l'exercice.
 *
 * Avant : le 2033-B était calculé À CÔTÉ de `FiscalYearService::computeTotals()`, avec ses
 * propres règles (TTC là où l'exercice prend le HT, quote-part tronquée charge par charge),
 * la ligne 360 portait l'amortissement différé sous l'intitulé des déficits, et rien ne
 * montrait la reprise d'un report d'amortissement : la partie B ne bouclait pas. Aucun
 * contrôle ne le signalait.
 */

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->fiscal = app(FiscalYearService::class);
    $this->tax = app(TaxReturnService::class);
});

/** Bien sans composant : aucun amortissement, le résultat se pilote au centime. */
function breakdownProperty(User $user, array $extra = []): Property
{
    return Property::forceCreate(array_merge([
        'user_id' => $user->id,
        'name' => 'Appartement partagé',
        'address' => '5 rue du Détail',
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

function breakdownExpense(Property $property, string $date, int $amount, string $category, bool $dedicated, int $tvaRate = 0, string $description = 'Charge'): void
{
    Expense::create([
        'property_id' => $property->id,
        'expense_date' => $date,
        'amount' => $amount,
        'tva_rate' => $tvaRate,
        'category' => $category,
        'description' => $description,
        'is_dedicated' => $dedicated,
        'recurring_type' => 'once',
    ]);
}

function breakdownIncome(Property $property, string $date, int $amount, int $fee = 0, int $tvaRate = 0): void
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

function breakdownYear(User $user, int $year, array $extra = []): FiscalYear
{
    return FiscalYear::forceCreate(array_merge([
        'user_id' => $user->id,
        'year' => $year,
        'status' => FiscalYear::STATUS_DRAFT,
    ], $extra));
}

/** Les trois tableaux que `checks()` attend, comme le PDF les calcule. */
function breakdownChecks(TaxReturnService $tax, FiscalYear $fy, $properties): array
{
    return collect($tax->checks(
        $tax->compute2033A($fy, $properties, $fy->year),
        $tax->compute2033B($fy, $properties, $fy->year),
        $tax->compute2033C($properties, $fy->year),
        $properties,
    ))->keyBy('id')->all();
}

// === UNE SEULE RÈGLE POUR LE 2033-B ET L'EXERCICE ===

it('builds the 2033-B from the same rules as the fiscal year, to the cent', function () {
    $property = breakdownProperty($this->user);

    breakdownIncome($property, '2026-03-10', 250000, 7500);
    // Montants impairs, partagés : tronquer charge par charge donnerait 1 à 2 centimes de moins.
    breakdownExpense($property, '2026-01-15', 33333, Expense::CATEGORY_ENERGY, false);
    breakdownExpense($property, '2026-02-15', 33333, Expense::CATEGORY_TELECOM, false);
    breakdownExpense($property, '2026-10-15', 77777, Expense::CATEGORY_PROPERTY_TAX, false);
    breakdownExpense($property, '2026-04-01', 12000, Expense::CATEGORY_CLEANING, true);

    $loan = Loan::forceCreate([
        'property_id' => $property->id,
        'bank_name' => 'Banque Test',
        'amount' => 10000000,
        'annual_rate' => '2.5',
        'duration_months' => 240,
        'start_date' => '2025-01-01',
        'monthly_payment' => 53000,
    ]);
    LoanPayment::create([
        'loan_id' => $loan->id,
        'payment_date' => '2026-05-05',
        'month_number' => 17,
        'capital_amount' => 30000,
        'interest_amount' => 20001,
        'insurance_amount' => 3333,
        'remaining_capital' => 9000000,
    ]);

    $fy = breakdownYear($this->user, 2026);
    $this->fiscal->calculate($fy);
    $fy->refresh();

    $properties = collect([$property]);
    $form = $this->tax->compute2033B($fy, $properties, 2026);

    expect($form['218'])->toBe((int) $fy->total_income)
        ->and($form['242'] + $form['244'] + $form['294'])->toBe((int) $fy->total_expenses)
        // 244 = taxe foncière partagée × 45 %, tronquée
        ->and($form['244'])->toBe((int) bcmul('77777', $property->quota_share, 0))
        ->and(breakdownChecks($this->tax, $fy, $properties)['resultat']['status'])
        ->toBe(TaxReturnService::CHECK_OK);
});

it('uses amounts before VAT for a property liable to VAT, as the fiscal year does', function () {
    $property = breakdownProperty($this->user, ['tva_regime' => Property::TVA_LIABLE, 'rented_area' => 100]);

    breakdownIncome($property, '2026-06-01', 110000, 0, 1000);           // 10 %
    breakdownExpense($property, '2026-06-02', 24000, Expense::CATEGORY_MAINTENANCE, true, 2000); // 20 %

    $fy = breakdownYear($this->user, 2026);
    $this->fiscal->calculate($fy);
    $fy->refresh();

    $form = $this->tax->compute2033B($fy, collect([$property]), 2026);

    expect($form['218'])->toBe(100000)
        ->and($form['242'])->toBe(20000)
        ->and($form['218'])->toBe((int) $fy->total_income)
        ->and($form['352'] - $form['354'])->toBe($form['310'] + $form['318'] - $form['350']);
});

// === PARTIE B : 318, 350, 360, 370 ===

it('deducts the deferred depreciation taken back this year on line 350', function () {
    $property = breakdownProperty($this->user, ['rented_area' => 100]);
    breakdownIncome($property, '2026-07-01', 1000000);

    $fy = breakdownYear($this->user, 2026, [
        'opening_source' => FiscalYear::OPENING_SOURCE_LIASSE,
        'opening_deferred_depreciation' => 400000,
    ]);
    $this->fiscal->calculate($fy);
    $fy->refresh();

    $form = $this->tax->compute2033B($fy, collect([$property]), 2026);

    expect($fy->previous_deferred)->toBe(400000)
        ->and($form['318'])->toBe(0)
        ->and($form['350'])->toBe(400000)
        ->and($form['352'])->toBe(600000)
        // Sans la ligne 350, 310 + 318 donnait 10 000 € pour un résultat de 6 000 €.
        ->and($form['310'] + $form['318'] - $form['350'])->toBe($form['352']);
});

it('reintegrates on line 318 the depreciation of the year that exceeds the ceiling', function () {
    $property = breakdownProperty($this->user, ['rented_area' => 100, 'land_percentage' => 15]);
    app(DepreciationService::class)->generateDefaultComponents($property);
    breakdownIncome($property, '2026-07-01', 100000);

    $fy = breakdownYear($this->user, 2026);
    $this->fiscal->calculate($fy);
    $fy->refresh();

    $form = $this->tax->compute2033B($fy, collect([$property]), 2026);

    expect($form['254'])->toBeGreaterThan(100000)
        ->and($form['318'])->toBe($form['254'] - 100000)
        ->and($form['350'])->toBe(0)
        ->and($form['352'])->toBe(0)
        ->and($form['310'] + $form['318'] - $form['350'])->toBe(0);
});

// Un déficit LMNP ne s'impute PAS sur la liasse : l'administration le reprend en cases 5GA à
// 5GJ de la 2042-C-PRO et l'impute elle-même (CGI art. 156, I-1° ter). L'imputer aussi en 360,
// et reporter la 370 en 5NA, le déduirait deux fois.
it('keeps line 360 at zero and line 370 before imputation, the deficits going to boxes 5GA-5GJ', function () {
    $property = breakdownProperty($this->user, ['rented_area' => 100]);
    breakdownIncome($property, '2026-07-01', 100000);

    $fy = breakdownYear($this->user, 2026, [
        'opening_source' => FiscalYear::OPENING_SOURCE_LIASSE,
        'opening_deficits' => [['origin_year' => 2022, 'amount' => 30000]],
        'opening_deferred_depreciation' => 0,
    ]);
    $this->fiscal->calculate($fy);
    $fy->refresh();

    $breakdown = $this->tax->resultBreakdown($fy, collect([$property]), 2026);
    $form = $this->tax->compute2033B($fy, collect([$property]), 2026, $breakdown);

    expect($fy->deficit_imputed)->toBe(30000)               // le stock est bien suivi…
        ->and($breakdown['deficits']['imputed'])->toBe(30000) // …et montré dans l'annexe,
        ->and($form['352'])->toBe(100000)
        ->and($form['360'])->toBe(0)                          // …mais jamais déduit ici
        ->and($form['370'])->toBe(100000)
        ->and($this->tax->compute2042($fy)['montant'])->toBe($form['370']);
});

// === CONTRÔLE DE BOUCLAGE ===

it('flags a 2033-B that no longer matches the fiscal result of a closed year', function () {
    $property = breakdownProperty($this->user, ['rented_area' => 100]);
    breakdownIncome($property, '2026-07-01', 100000);

    $fy = breakdownYear($this->user, 2026);
    $this->fiscal->calculate($fy);
    $fy->refresh();
    $fy->update(['status' => FiscalYear::STATUS_CLOSED]);

    // Recette saisie APRÈS la clôture : l'exercice garde ses totaux figés.
    breakdownIncome($property, '2026-12-20', 5000);

    $check = breakdownChecks($this->tax, $fy->refresh(), collect([$property]))['resultat'];

    expect($check['status'])->toBe(TaxReturnService::CHECK_ERROR)
        ->and($check['delta'])->toBe(5000);
});

// === L'ANNEXE DU PDF ===

it('prints every charge with its allocation and the quota share applied to the shared total', function () {
    $property = breakdownProperty($this->user);
    breakdownIncome($property, '2026-03-10', 250000, 7500);
    breakdownExpense($property, '2026-01-15', 40000, Expense::CATEGORY_ENERGY, false, 0, 'Facture EDF janvier');
    breakdownExpense($property, '2026-04-01', 12000, Expense::CATEGORY_CLEANING, true, 0, 'Ménage fin de séjour');

    $fy = breakdownYear($this->user, 2026);
    $data = $this->tax->pdfData($fy);
    $html = view('pdf.tax-return', $data)->render();

    $row = $data['resultBreakdown']['properties'][0];

    expect($html)->toContain('Annexe — Détail du résultat')
        ->toContain('Facture EDF janvier')
        ->toContain('Ménage fin de séjour')
        ->toContain('Partagée')
        ->toContain('Dédiée (100 %)')
        ->toContain('quote-part locative 45 %')
        ->toContain('400,00 € × 45 % = 180,00 €')
        ->and($row['expense_totals']['retained_242'] + $row['expense_totals']['retained_244'])
        ->toBe($data['form2033B']['242'] + $data['form2033B']['244']);
});
