<?php

use App\Models\AccountingEntry;
use App\Models\Expense;
use App\Models\FiscalYear;
use App\Models\Income;
use App\Models\Property;
use App\Models\User;
use App\Services\AccountingEntryService;
use App\Services\FiscalYearService;
use App\Services\TaxReturnService;

/**
 * Écritures des recettes : la commission de plateforme comptée UNE fois.
 *
 * Avant : 706 était crédité du loyer NET (brut − commission), puis une seconde écriture
 * débitait encore 622 de la commission par le crédit de 512. La commission sortait donc
 * deux fois : le résultat comptable du FEC et le solde de banque étaient minorés d'autant,
 * et ne retombaient ni sur la ligne 310 du 2033-B ni sur ce qui a été encaissé.
 *
 * Désormais, une seule écriture par recette : 706 crédité du BRUT (HT si le bien est
 * assujetti, 44571 portant la TVA), 622 débité de la commission, 512 débité du NET encaissé.
 *
 * Montants synthétiques uniquement.
 */
beforeEach(function () {
    $this->user = User::factory()->create();
    $this->service = app(AccountingEntryService::class);
});

/** Bien loué en totalité, sans composant : aucun amortissement, aucune quote-part. */
function feeProperty(User $user, array $extra = []): Property
{
    return Property::forceCreate(array_merge([
        'user_id' => $user->id,
        'name' => 'Studio test',
        'address' => '1 rue Fictive',
        'city' => 'Paris',
        'postal_code' => '75001',
        'type' => 'apartment',
        'total_area' => 30,
        'rented_area' => 30,
        'acquisition_date' => '2020-01-01',
        'acquisition_price' => 10000000,
        'notary_fees' => 0,
        'land_percentage' => 0,
        'rental_start_date' => '2023-01-01',
        'rental_type' => 'seasonal',
        'is_primary_residence' => false,
    ], $extra));
}

function feeIncome(Property $property, int $amount, int $fee, int $tvaRate = 0): void
{
    Income::create([
        'property_id' => $property->id,
        'income_date' => '2024-06-15',
        'amount' => $amount,
        'tva_rate' => $tvaRate,
        'platform_fee' => $fee,
        'tourist_tax' => 0,
        'source' => 'airbnb',
    ]);
}

function feeYear(User $user, string $status = FiscalYear::STATUS_DRAFT): FiscalYear
{
    return FiscalYear::forceCreate(['user_id' => $user->id, 'year' => 2024, 'status' => $status]);
}

/** Solde (débit − crédit) des comptes dont le numéro commence par $prefix. */
function feeBalance(FiscalYear $fy, string $prefix): int
{
    $entries = AccountingEntry::where('fiscal_year_id', $fy->id)
        ->where('account_code', 'like', $prefix . '%')
        ->get();

    return (int) $entries->sum('debit') - (int) $entries->sum('credit');
}

it('credits 706 with the gross rent, debits 622 with the fee and 512 with the net received', function () {
    $property = feeProperty($this->user);
    feeIncome($property, 200000, 6000);

    $fy = feeYear($this->user);
    $this->service->generateForFiscalYear($fy);

    expect(-feeBalance($fy, '706'))->toBe(200000)
        ->and(feeBalance($fy, '622'))->toBe(6000)
        ->and(feeBalance($fy, '512'))->toBe(194000)
        ->and((int) $fy->accountingEntries()->sum('debit'))->toBe((int) $fy->accountingEntries()->sum('credit'));
});

it('does the same before VAT for a property liable to VAT', function () {
    $property = feeProperty($this->user, ['tva_regime' => Property::TVA_LIABLE]);
    feeIncome($property, 110000, 3000, 1000);   // 10 % : HT 100 000, TVA 10 000

    $fy = feeYear($this->user);
    $this->service->generateForFiscalYear($fy);

    expect(-feeBalance($fy, '706'))->toBe(100000)
        ->and(-feeBalance($fy, '44571'))->toBe(10000)
        ->and(feeBalance($fy, '622'))->toBe(3000)
        ->and(feeBalance($fy, '512'))->toBe(107000)
        ->and((int) $fy->accountingEntries()->sum('debit'))->toBe((int) $fy->accountingEntries()->sum('credit'));
});

it('gives an accounting result equal to line 310 of the 2033-B', function () {
    $property = feeProperty($this->user);
    feeIncome($property, 200000, 6000);
    Expense::create([
        'property_id' => $property->id,
        'expense_date' => '2024-03-01',
        'amount' => 10000,
        'category' => Expense::CATEGORY_INSURANCE,
        'description' => 'Assurance test',
        'is_dedicated' => true,
        'recurring_type' => 'once',
    ]);

    $fy = feeYear($this->user);
    app(FiscalYearService::class)->calculate($fy);
    $fy->refresh();

    // Résultat comptable = produits (classe 7, crédit − débit) − charges (classe 6).
    $result = -feeBalance($fy, '7') - feeBalance($fy, '6');
    $form = app(TaxReturnService::class)->compute2033B($fy, collect([$property]), 2024);

    expect($result)->toBe(200000 - 6000 - 10000)
        ->and($result)->toBe($form['310'])
        ->and(feeBalance($fy, '512'))->toBe(194000 - 10000);
});
