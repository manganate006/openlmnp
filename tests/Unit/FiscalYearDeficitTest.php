<?php

use App\Models\FiscalYear;
use App\Models\Income;
use App\Models\Property;
use App\Models\User;
use App\Services\DepreciationService;
use App\Services\FiscalYearService;
use App\Services\TaxReturnService;

/**
 * Déficits reportables — et correction du 2033-D.
 *
 * Les cases 982/983/984 suivent les DÉFICITS ; elles recevaient l'AMORTISSEMENT RÉPUTÉ DIFFÉRÉ.
 * Deux stocks distincts : le déficit LMNP s'impute sur les bénéfices de même nature des dix
 * années suivantes (CGI art. 156, I-1° ter), l'amortissement différé se reporte sans limite
 * de durée (art. 39 C, II-3).
 */

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->service = app(FiscalYearService::class);
    $this->tax = app(TaxReturnService::class);
});

/** Bien SANS aucun amortissement : le résultat fiscal se pilote alors au centime près. */
function makeDeficitProperty(User $user): Property
{
    return Property::forceCreate([
        'user_id' => $user->id,
        'name' => 'Studio sans amortissement',
        'address' => '3 rue des Déficits',
        'city' => 'Nantes',
        'postal_code' => '44000',
        'type' => 'apartment',
        'total_area' => 30,
        'rented_area' => 30,
        'acquisition_date' => '2014-01-01',
        'acquisition_price' => 10000000,
        'notary_fees' => 0,
        'agency_fees' => 0,
        'market_value' => null,
        'land_percentage' => 0,
        'rental_start_date' => '2014-06-01',
        'rental_type' => 'seasonal',
        'is_primary_residence' => false,
    ]);
}

function addRent(Property $property, string $date, int $amountCents): void
{
    Income::create([
        'property_id' => $property->id,
        'income_date' => $date,
        'amount' => $amountCents,
        'platform_fee' => 0,
        'tourist_tax' => 0,
        'source' => 'direct',
    ]);
}

/** Exercice porteur de déficits d'ouverture recopiés d'une liasse. */
function makeDeficitYear(User $user, int $year, array $vintages, array $extra = []): FiscalYear
{
    return FiscalYear::forceCreate(array_merge([
        'user_id' => $user->id,
        'year' => $year,
        'status' => FiscalYear::STATUS_DRAFT,
        'opening_source' => FiscalYear::OPENING_SOURCE_LIASSE,
        'opening_deficits' => $vintages,
    ], $extra));
}

// === LE DÉFAUT DE CONFORMITÉ : 982/983/984 n'est PAS l'amortissement différé ===

it('no longer prints the deferred depreciation in the deficit boxes of form 2033-D', function () {
    $fiscalYear = FiscalYear::forceCreate([
        'user_id' => $this->user->id,
        'year' => 2026,
        'status' => FiscalYear::STATUS_DRAFT,
        'previous_deferred' => 1200000,      // 12 000 € d'amortissement différé antérieur
        'deferred_depreciation' => 1500000,  // 15 000 € reportables à la clôture
        'fiscal_result' => 300000,
        'previous_deficit' => 0,             // …et AUCUN déficit
    ]);

    $form = $this->tax->compute2033D($fiscalYear);

    expect($form['982'])->toBe(0)
        ->and($form['983'])->toBe(0)
        ->and($form['984'])->toBe(0)
        ->and($form['870'])->toBe(1500000);
});

it('prints the deficit stock in boxes 982, 983 and 984', function () {
    $fiscalYear = FiscalYear::forceCreate([
        'user_id' => $this->user->id,
        'year' => 2026,
        'status' => FiscalYear::STATUS_DRAFT,
        'previous_deferred' => 1200000,
        'deferred_depreciation' => 1500000,
        'fiscal_result' => 300000,
        'previous_deficit' => 400000,
        'deficit_imputed' => 300000,
        'deficit_carryforward' => 100000,
    ]);

    $form = $this->tax->compute2033D($fiscalYear);

    expect($form['982'])->toBe(400000)
        ->and($form['983'])->toBe(300000)
        ->and($form['984'])->toBe(100000);
});

it('keeps box 870 on the deferred depreciation and box 360 on the previous deferred', function () {
    $fiscalYear = FiscalYear::forceCreate([
        'user_id' => $this->user->id,
        'year' => 2026,
        'status' => FiscalYear::STATUS_DRAFT,
        'previous_deferred' => 1200000,
        'deferred_depreciation' => 1500000,
        'fiscal_result' => 0,
        'previous_deficit' => 400000,
    ]);

    expect($this->tax->compute2033D($fiscalYear)['870'])->toBe(1500000)
        ->and($this->tax->compute2033B($fiscalYear, collect(), 2026)['360'])->toBe(1200000);
});

// === IMPUTATION ===

it('imputes a previous deficit on the profit of the year', function () {
    $property = makeDeficitProperty($this->user);
    addRent($property, '2026-05-01', 100000); // 1 000 € de bénéfice

    $fiscalYear = makeDeficitYear($this->user, 2026, [
        ['origin_year' => 2022, 'amount' => 50000], // 500 €
    ]);
    $this->service->calculate($fiscalYear);
    $fiscalYear->refresh();

    expect($fiscalYear->previous_deficit)->toBe(50000)
        ->and($fiscalYear->deficit_imputed)->toBe(50000)
        ->and($fiscalYear->deficit_carryforward)->toBe(0);
});

it('imputes only up to the profit and carries the rest forward', function () {
    $property = makeDeficitProperty($this->user);
    addRent($property, '2026-05-01', 30000); // 300 € de bénéfice

    $fiscalYear = makeDeficitYear($this->user, 2026, [
        ['origin_year' => 2022, 'amount' => 50000], // 500 €
    ]);
    $this->service->calculate($fiscalYear);
    $fiscalYear->refresh();

    expect($fiscalYear->deficit_imputed)->toBe(30000)
        ->and($fiscalYear->deficit_carryforward)->toBe(20000);
});

it('imputes the oldest vintage first', function () {
    $property = makeDeficitProperty($this->user);
    addRent($property, '2026-05-01', 25000); // 250 € de bénéfice

    $fiscalYear = makeDeficitYear($this->user, 2026, [
        ['origin_year' => 2022, 'amount' => 30000], // 300 €, le plus récent
        ['origin_year' => 2020, 'amount' => 20000], // 200 €, le plus ancien
    ]);
    $this->service->calculate($fiscalYear);
    $fiscalYear->refresh();

    $byYear = collect($fiscalYear->deficit_detail)->keyBy('origin_year');

    expect($fiscalYear->deficit_imputed)->toBe(25000)
        ->and($byYear[2020]['imputed'])->toBe(20000)   // épuisé en premier
        ->and($byYear[2020]['remaining'])->toBe(0)
        ->and($byYear[2022]['imputed'])->toBe(5000)    // puis le reliquat
        ->and($byYear[2022]['remaining'])->toBe(25000);
});

// === PÉREMPTION À DIX ANS ===

it('lets a deficit expire after the tenth following year', function () {
    $property = makeDeficitProperty($this->user);
    addRent($property, '2026-05-01', 100000);

    // Déficit né en 2015 : imputable de 2016 à 2025 inclus, perdu en 2026.
    $fiscalYear = makeDeficitYear($this->user, 2026, [
        ['origin_year' => 2015, 'amount' => 50000],
    ]);
    $this->service->calculate($fiscalYear);
    $fiscalYear->refresh();

    expect($fiscalYear->previous_deficit)->toBe(50000)
        ->and($fiscalYear->deficit_imputed)->toBe(0)
        ->and($fiscalYear->deficit_carryforward)->toBe(0)
        ->and($fiscalYear->deficit_detail[0]['expired'])->toBe(50000);
});

it('still imputes a deficit on the tenth following year', function () {
    $property = makeDeficitProperty($this->user);
    addRent($property, '2025-05-01', 100000);

    // Déficit né en 2015 : 2025 est la dixième année suivante, encore imputable.
    $fiscalYear = makeDeficitYear($this->user, 2025, [
        ['origin_year' => 2015, 'amount' => 50000],
    ]);
    $this->service->calculate($fiscalYear);

    expect($fiscalYear->refresh()->deficit_imputed)->toBe(50000);
});

// === LE DÉFICIT DE L'EXERCICE ===

it('turns the deficit of the year into a new vintage, not imputable on itself', function () {
    $property = makeDeficitProperty($this->user);
    \App\Models\Expense::create([
        'property_id' => $property->id,
        'expense_date' => '2026-03-01',
        'amount' => 40000, // 400 € de charges, aucune recette
        'category' => 'maintenance',
        'description' => 'Réparation',
        'is_dedicated' => true,
        'recurring_type' => 'once',
    ]);

    $fiscalYear = FiscalYear::forceCreate([
        'user_id' => $this->user->id,
        'year' => 2026,
        'status' => FiscalYear::STATUS_DRAFT,
    ]);
    $this->service->calculate($fiscalYear);
    $fiscalYear->refresh();

    $form = $this->tax->compute2033D($fiscalYear);

    expect($fiscalYear->fiscal_result)->toBe(-40000)
        ->and($fiscalYear->deficit_imputed)->toBe(0)
        ->and($fiscalYear->deficit_carryforward)->toBe(40000)
        ->and($fiscalYear->deficit_detail[0]['origin_year'])->toBe(2026)
        ->and($form['860'])->toBe(40000)
        ->and($form['984'])->toBe(40000);
});

it('carries the deficit stock from one fiscal year to the next', function () {
    $property = makeDeficitProperty($this->user);
    \App\Models\Expense::create([
        'property_id' => $property->id,
        'expense_date' => '2025-03-01',
        'amount' => 40000,
        'category' => 'maintenance',
        'description' => 'Réparation',
        'is_dedicated' => true,
        'recurring_type' => 'once',
    ]);
    addRent($property, '2026-05-01', 15000); // 150 € de bénéfice en 2026

    $y2025 = FiscalYear::forceCreate([
        'user_id' => $this->user->id,
        'year' => 2025,
        'status' => FiscalYear::STATUS_DRAFT,
    ]);
    $this->service->calculate($y2025);

    $y2026 = FiscalYear::forceCreate([
        'user_id' => $this->user->id,
        'year' => 2026,
        'status' => FiscalYear::STATUS_DRAFT,
    ]);
    $this->service->calculate($y2026);

    expect($y2026->refresh()->previous_deficit)->toBe(40000)
        ->and($y2026->deficit_imputed)->toBe(15000)
        ->and($y2026->deficit_carryforward)->toBe(25000);
});

// === ORDRE D'IMPUTATION : AMORTISSEMENTS DIFFÉRÉS D'ABORD, DÉFICITS ENSUITE ===

it('consumes the deferred depreciation before any previous deficit', function () {
    // Règle figée ici : l'amortissement écarté par le 2 du II de l'art. 39 C se déduit DU
    // RÉSULTAT de l'exercice (BOI-BIC-AMT-20-40-10-30 § 10) ; le déficit antérieur, lui,
    // s'impute sur un résultat déjà déterminé (BOI-BIC-DEF-20-10 § 70 ; CE 10/04/2015
    // n° 369667). Tant qu'il reste du stock différé, aucun déficit n'est consommé.
    $property = makeDeficitProperty($this->user);
    app(DepreciationService::class)->generateDefaultComponents($property);
    addRent($property, '2026-05-01', 100000); // 1 000 € de recettes, largement absorbés

    $fiscalYear = makeDeficitYear($this->user, 2026, [
        ['origin_year' => 2022, 'amount' => 50000],
    ]);
    $this->service->calculate($fiscalYear);
    $fiscalYear->refresh();

    expect($fiscalYear->capped_depreciation)->toBe(100000)  // l'amortissement absorbe tout
        ->and($fiscalYear->fiscal_result)->toBe(0)
        ->and($fiscalYear->deferred_depreciation)->toBeGreaterThan(0)
        ->and($fiscalYear->deficit_imputed)->toBe(0)        // le déficit attend son tour
        ->and($fiscalYear->deficit_carryforward)->toBe(50000);
});

// === REPRISE : les déficits d'ouverture ne sont pas écrasés par un N-1 vide ===

it('does not let an empty previous fiscal year erase the opening deficits', function () {
    $property = makeDeficitProperty($this->user);
    addRent($property, '2026-05-01', 20000);

    FiscalYear::forceCreate([
        'user_id' => $this->user->id,
        'year' => 2025,
        'status' => FiscalYear::STATUS_DRAFT,
    ]);

    $fiscalYear = makeDeficitYear($this->user, 2026, [
        ['origin_year' => 2022, 'amount' => 50000],
    ]);
    $this->service->calculate($fiscalYear);

    expect($fiscalYear->refresh()->previous_deficit)->toBe(50000);
});

// === NORMALISATION DES MILLÉSIMES ===

it('merges duplicate vintages and drops the settled ones', function () {
    $normalized = FiscalYearService::normalizeDeficitVintages([
        ['origin_year' => 2022, 'amount' => 30000],
        ['origin_year' => 2022, 'amount' => 20000],
        ['origin_year' => 2020, 'remaining' => 10000],
        ['origin_year' => 2019, 'remaining' => 0],
        ['origin_year' => 0, 'amount' => 99999],
    ]);

    expect($normalized)->toBe([
        ['origin_year' => 2020, 'remaining' => 10000],
        ['origin_year' => 2022, 'remaining' => 50000],
    ]);
});

// === LE RECALCUL EN CASCADE NE ROUVRE PAS UN EXERCICE CLÔTURÉ ===

it('propagates the deficit stock to a draft following year', function () {
    $property = makeDeficitProperty($this->user);
    \App\Models\Expense::create([
        'property_id' => $property->id,
        'expense_date' => '2025-03-01',
        'amount' => 40000,
        'category' => 'maintenance',
        'description' => 'Réparation',
        'is_dedicated' => true,
        'recurring_type' => 'once',
    ]);

    $y2025 = FiscalYear::forceCreate([
        'user_id' => $this->user->id, 'year' => 2025, 'status' => FiscalYear::STATUS_DRAFT,
    ]);
    $y2026 = FiscalYear::forceCreate([
        'user_id' => $this->user->id, 'year' => 2026, 'status' => FiscalYear::STATUS_DRAFT,
    ]);

    // Le recalcul de 2025 doit entraîner 2026 : son stock de déficits vient de changer.
    $this->service->calculate($y2025);

    expect($y2026->refresh()->previous_deficit)->toBe(40000);
});

it('never reopens a closed following year over a deficit gap alone', function () {
    $property = makeDeficitProperty($this->user);
    \App\Models\Expense::create([
        'property_id' => $property->id,
        'expense_date' => '2025-03-01',
        'amount' => 40000,
        'category' => 'maintenance',
        'description' => 'Réparation',
        'is_dedicated' => true,
        'recurring_type' => 'once',
    ]);

    $y2025 = FiscalYear::forceCreate([
        'user_id' => $this->user->id, 'year' => 2025, 'status' => FiscalYear::STATUS_DRAFT,
    ]);
    // Exercice clôturé : il porte une déclaration déposée, ses totaux font foi.
    $y2026 = FiscalYear::forceCreate([
        'user_id' => $this->user->id,
        'year' => 2026,
        'status' => FiscalYear::STATUS_CLOSED,
        'total_income' => 777777,
    ]);

    $this->service->calculate($y2025);

    expect($y2026->refresh()->total_income)->toBe(777777)
        ->and($y2026->previous_deficit)->toBe(0);
});
