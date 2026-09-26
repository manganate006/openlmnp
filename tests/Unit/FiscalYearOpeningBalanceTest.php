<?php

use App\Models\FiscalYear;
use App\Models\Income;
use App\Models\Property;
use App\Models\User;
use App\Services\DepreciationService;
use App\Services\FiscalYearService;
use App\Services\TaxReturnService;

/**
 * Soldes d'ouverture — reprise d'un dossier tenu par un cabinet.
 *
 * L'utilisateur n'a AUCUN exercice antérieur dans l'application : son report
 * d'amortissements différés ne peut venir que de ce qu'il recopie de sa liasse.
 */

const OPENING_ARD = 1200000; // 12 000 €, en centimes

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->service = app(FiscalYearService::class);
});

function makeOpeningProperty(User $user, string $rentalStartDate = '2020-06-01'): Property
{
    $property = Property::forceCreate([
        'user_id' => $user->id,
        'name' => 'Bien repris',
        'address' => '1 rue de la Reprise',
        'city' => 'Lyon',
        'postal_code' => '69001',
        'type' => 'apartment',
        'total_area' => 60,
        'rented_area' => 60,
        'acquisition_date' => '2020-01-15',
        'acquisition_price' => 20000000, // 200 000 €
        'notary_fees' => 0,
        'market_value' => null,
        'land_percentage' => 0,
        'rental_start_date' => $rentalStartDate,
        'rental_type' => 'seasonal',
        'is_primary_residence' => false,
    ]);

    app(DepreciationService::class)->generateDefaultComponents($property);

    return $property;
}

/** Exercice de reprise : aucun N-1 en base, un solde d'ouverture recopié de la liasse. */
function makeOpeningYear(User $user, int $year, array $attributes = []): FiscalYear
{
    return FiscalYear::forceCreate(array_merge([
        'user_id' => $user->id,
        'year' => $year,
        'status' => FiscalYear::STATUS_DRAFT,
        'opening_deferred_depreciation' => OPENING_ARD,
        'opening_source' => FiscalYear::OPENING_SOURCE_LIASSE,
    ], $attributes));
}

// === Le report vient du solde d'ouverture quand N-1 n'existe pas ===

it('carries the opening deferred depreciation when the previous fiscal year is absent', function () {
    makeOpeningProperty($this->user);
    $fiscalYear = makeOpeningYear($this->user, 2026);

    $this->service->calculate($fiscalYear);

    expect($fiscalYear->refresh()->previous_deferred)->toBe(OPENING_ARD);
});

// Jusqu'en v1.6.7, ce report s'imprimait en ligne 360 du 2033-B — celle des DÉFICITS
// antérieurs imputés (notice 2033-NOT-SD). Il apparaît désormais là où on le vérifie : dans
// le plafonnement de l'annexe « Détail du résultat » (report disponible), et en ligne 350
// pour la part effectivement reprise sur le résultat de l'exercice (issue #13).
it('shows the opening deferred depreciation in the result breakdown, not in box 360', function () {
    $property = makeOpeningProperty($this->user);
    Income::create([
        'property_id' => $property->id,
        'income_date' => '2026-07-01',
        'amount' => 50000000, // assez de recettes pour absorber dotation et report
        'platform_fee' => 0,
        'tourist_tax' => 0,
        'source' => 'direct',
    ]);
    $fiscalYear = makeOpeningYear($this->user, 2026);

    $this->service->calculate($fiscalYear);
    $fiscalYear->refresh();

    $tax = app(TaxReturnService::class);
    $properties = Property::withoutGlobalScopes()->where('user_id', $this->user->id)->get();
    $breakdown = $tax->resultBreakdown($fiscalYear, $properties, 2026);
    $form = $tax->compute2033B($fiscalYear, $properties, 2026, $breakdown);

    expect($breakdown['capping']['carried_forward'])->toBe(OPENING_ARD)
        ->and($form['350'])->toBe(OPENING_ARD)
        ->and($form['360'])->toBe(0);
});

// === Un N-1 réel l'emporte toujours sur le solde d'ouverture ===

it('lets a real previous fiscal year override the opening balance', function () {
    makeOpeningProperty($this->user);

    // Exercice 2025 réellement tenu : il porte des données, donc un report calculé.
    FiscalYear::forceCreate([
        'user_id' => $this->user->id,
        'year' => 2025,
        'status' => FiscalYear::STATUS_CLOSED,
        'total_income' => 500000,
        'total_expenses' => 200000,
        'total_depreciation' => 800000,
        'capped_depreciation' => 300000,
        'deferred_depreciation' => 500000,
        'fiscal_result' => 0,
    ]);

    $fiscalYear = makeOpeningYear($this->user, 2026);
    $this->service->calculate($fiscalYear);

    expect($fiscalYear->refresh()->previous_deferred)->toBe(500000);
});

// === Le piège : un N-1 VIDE ne doit pas écraser le solde d'ouverture ===

it('does not let an empty previous fiscal year silently erase the opening balance', function () {
    makeOpeningProperty($this->user);

    // Exercice 2025 créé par erreur, jamais alimenté : tous ses totaux sont à 0.
    FiscalYear::forceCreate([
        'user_id' => $this->user->id,
        'year' => 2025,
        'status' => FiscalYear::STATUS_DRAFT,
    ]);

    $fiscalYear = makeOpeningYear($this->user, 2026);
    $this->service->calculate($fiscalYear);

    expect($fiscalYear->refresh()->previous_deferred)->toBe(OPENING_ARD);
});

it('flags an empty previous fiscal year that contradicts the opening balance', function () {
    makeOpeningProperty($this->user);

    FiscalYear::forceCreate([
        'user_id' => $this->user->id,
        'year' => 2025,
        'status' => FiscalYear::STATUS_DRAFT,
    ]);

    $fiscalYear = makeOpeningYear($this->user, 2026);

    expect($this->service->openingBalanceWarning($fiscalYear))
        ->toContain('2025')
        ->toContain('aucune donnée');
});

it('stays silent when the previous fiscal year actually holds data', function () {
    makeOpeningProperty($this->user);

    FiscalYear::forceCreate([
        'user_id' => $this->user->id,
        'year' => 2025,
        'status' => FiscalYear::STATUS_CLOSED,
        'total_income' => 500000,
        'deferred_depreciation' => 500000,
    ]);

    $fiscalYear = makeOpeningYear($this->user, 2026);

    expect($this->service->openingBalanceWarning($fiscalYear))->toBeNull();
});

it('stays silent when no opening balance was entered at all', function () {
    makeOpeningProperty($this->user);

    FiscalYear::forceCreate([
        'user_id' => $this->user->id,
        'year' => 2025,
        'status' => FiscalYear::STATUS_DRAFT,
    ]);

    $fiscalYear = makeOpeningYear($this->user, 2026, [
        'opening_deferred_depreciation' => 0,
        'opening_source' => null,
    ]);

    expect($this->service->openingBalanceWarning($fiscalYear))->toBeNull();
});

// === La chaîne de recalcul ne perd pas le solde ===

it('keeps the opening balance through a full chain recalculation', function () {
    makeOpeningProperty($this->user);
    $fiscalYear = makeOpeningYear($this->user, 2026);

    $this->service->calculate($fiscalYear);
    $this->service->recalculateChain($this->user);

    expect($fiscalYear->refresh()->previous_deferred)->toBe(OPENING_ARD)
        ->and($fiscalYear->opening_deferred_depreciation)->toBe(OPENING_ARD);
});

it('propagates the opening balance to the following fiscal year', function () {
    makeOpeningProperty($this->user);
    $opening = makeOpeningYear($this->user, 2026);
    $this->service->calculate($opening);

    $next = FiscalYear::forceCreate([
        'user_id' => $this->user->id,
        'year' => 2027,
        'status' => FiscalYear::STATUS_DRAFT,
    ]);
    $this->service->calculate($next);

    // Sans recette en 2026, tout est différé : le report transmis à 2027 contient au moins
    // le solde d'ouverture. L'égalité seule ne prouverait rien (0 = 0 sur l'ancien code).
    expect($next->refresh()->previous_deferred)
        ->toBe((int) $opening->refresh()->deferred_depreciation)
        ->toBeGreaterThan(OPENING_ARD);
});

// === Le cumul d'amortissements d'ouverture est un CONTRÔLE, jamais une entrée de calcul ===

it('never feeds the opening accumulated depreciation into the computation', function () {
    makeOpeningProperty($this->user);

    $withControl = makeOpeningYear($this->user, 2026, [
        'opening_accumulated_depreciation' => 5000000, // 50 000 €
    ]);
    $computedWithControl = $this->service->computeTotals($withControl);

    $withControl->update(['opening_accumulated_depreciation' => 0]);
    $computedWithout = $this->service->computeTotals($withControl->refresh());

    expect($computedWithControl)->toBe($computedWithout);
});

// === La création d'un exercice de reprise n'est plus bloquée par l'absence de N-1 ===

it('blocks a fiscal year with no predecessor and no opening balance', function () {
    makeOpeningProperty($this->user);

    expect($this->service->missingPreviousYearError($this->user, 2026))
        ->toContain('L\'exercice 2025 n\'existe pas');
});

it('allows a fiscal year with no predecessor when opening balances are entered', function () {
    makeOpeningProperty($this->user);

    expect($this->service->missingPreviousYearError($this->user, 2026, hasOpeningBalances: true))
        ->toBeNull();
});

// === Modèle ===

it('sums the opening deficits across vintages', function () {
    $fiscalYear = makeOpeningYear($this->user, 2026, [
        'opening_deferred_depreciation' => 0,
        'opening_deficits' => [
            ['origin_year' => 2022, 'amount' => 120000],
            ['origin_year' => 2023, 'amount' => 80000],
        ],
    ]);

    expect($fiscalYear->openingDeficitsTotal())->toBe(200000)
        ->and($fiscalYear->hasOpeningBalances())->toBeTrue();
});
