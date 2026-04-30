<?php

use App\Models\Expense;
use App\Models\FiscalYear;
use App\Models\Income;
use App\Models\Property;
use App\Models\User;
use App\Services\AccountingEntryService;
use App\Services\DepreciationService;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->service = app(AccountingEntryService::class);

    $this->property = Property::forceCreate([
        'user_id' => $this->user->id,
        'name' => 'Test AE',
        'address' => '1 rue Test',
        'city' => 'Paris',
        'postal_code' => '75001',
        'type' => 'apartment',
        'total_area' => 100,
        'rented_area' => 50,
        'acquisition_date' => '2020-01-01',
        'acquisition_price' => 30000000,
        'notary_fees' => 0,
        'land_percentage' => 15,
        'rental_start_date' => '2023-01-01',
        'rental_type' => 'seasonal',
        'is_primary_residence' => true,
    ]);

    app(DepreciationService::class)->generateDefaultComponents($this->property);
});

it('generates balanced accounting entries for income', function () {
    Income::create([
        'property_id' => $this->property->id,
        'income_date' => '2024-06-15',
        'amount' => 200000,
        'platform_fee' => 6000,
        'tourist_tax' => 0,
        'source' => 'airbnb',
    ]);

    $fy = FiscalYear::forceCreate([
        'user_id' => $this->user->id,
        'year' => 2024,
        'status' => 'draft',
    ]);

    $count = $this->service->generateForFiscalYear($fy);
    expect($count)->toBeGreaterThan(0);

    // Check balance: total debits = total credits
    $totalDebit = $fy->accountingEntries()->sum('debit');
    $totalCredit = $fy->accountingEntries()->sum('credit');
    expect($totalDebit)->toBe($totalCredit);
});

it('generates depreciation entries with correct accounts', function () {
    $fy = FiscalYear::forceCreate([
        'user_id' => $this->user->id,
        'year' => 2024,
        'status' => 'draft',
    ]);

    $this->service->generateForFiscalYear($fy);

    // Should have depreciation entries (681 debit, 28xx credit)
    $depEntries = $fy->accountingEntries()->where('account_code', 'LIKE', '681%')->get();
    expect($depEntries->count())->toBeGreaterThan(0);

    // Each depreciation entry should have a matching credit in 28xx
    foreach ($depEntries as $entry) {
        $matching = $fy->accountingEntries()
            ->where('piece_ref', $entry->piece_ref)
            ->where('credit', '>', 0)
            ->first();
        expect($matching)->not->toBeNull();
        expect(str_starts_with($matching->account_code, '28'))->toBeTrue();
        expect($matching->credit)->toBe($entry->debit);
    }
});

it('maps expense categories to correct PCG accounts', function () {
    Expense::create([
        'property_id' => $this->property->id,
        'expense_date' => '2024-01-01',
        'amount' => 240000,
        'category' => 'property_tax',
        'description' => 'Taxe foncière',
        'is_dedicated' => false,
        'recurring_type' => 'yearly',
    ]);

    $fy = FiscalYear::forceCreate([
        'user_id' => $this->user->id,
        'year' => 2024,
        'status' => 'draft',
    ]);

    $this->service->generateForFiscalYear($fy);

    // Taxe foncière should use account 6351
    $taxEntry = $fy->accountingEntries()
        ->where('account_code', '6351')
        ->where('debit', '>', 0)
        ->first();
    expect($taxEntry)->not->toBeNull();
    // Quote-part 50% of 240000 = 120000
    expect($taxEntry->debit)->toBe(120000);
});
