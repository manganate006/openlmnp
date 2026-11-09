<?php

use App\Models\AccountingEntry;
use App\Models\FiscalYear;
use App\Models\Income;
use App\Models\Property;
use App\Models\User;
use App\Services\AccountingEntryService;

/**
 * `openlmnp:repair-accounting-entries` — les écritures du FEC sont stockées et ne se
 * régénèrent qu'au calcul d'un exercice. Celles produites avant le 30/10/2026 comptaient la
 * commission de plateforme deux fois (706 au net + 622 par 512) ; un exercice clôturé les
 * garde. Rapport par défaut, `--fix` pour régénérer, `--closed` en plus pour un exercice
 * clôturé. Montants synthétiques uniquement.
 */
beforeEach(function () {
    $this->user = User::factory()->create();

    $this->property = Property::forceCreate([
        'user_id' => $this->user->id,
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
    ]);

    Income::create([
        'property_id' => $this->property->id,
        'income_date' => '2024-06-15',
        'amount' => 200000,
        'platform_fee' => 6000,
        'tourist_tax' => 0,
        'source' => 'airbnb',
    ]);
});

/** Reproduit les écritures d'avant le correctif : 706 au net, puis 622 / 512 en plus. */
function legacyFeeEntries(FiscalYear $fy, Property $property): void
{
    AccountingEntry::withoutGlobalScopes()->where('fiscal_year_id', $fy->id)->delete();

    $rows = [
        ['512', 194000, 0, 'REC-1', 'VE'],
        ['706', 0, 194000, 'REC-1', 'VE'],
        ['622', 6000, 0, 'COM-1', 'HA'],
        ['512', 0, 6000, 'COM-1', 'HA'],
    ];

    foreach ($rows as [$account, $debit, $credit, $ref, $journal]) {
        AccountingEntry::create([
            'fiscal_year_id' => $fy->id,
            'property_id' => $property->id,
            'entry_date' => '2024-06-15',
            'account_code' => $account,
            'label' => 'Ancienne écriture',
            'debit' => $debit,
            'credit' => $credit,
            'piece_ref' => $ref,
            'journal' => $journal,
        ]);
    }
}

function stored706(FiscalYear $fy): int
{
    return (int) AccountingEntry::withoutGlobalScopes()
        ->where('fiscal_year_id', $fy->id)
        ->where('account_code', '706')
        ->sum('credit');
}

it('reports legacy entries without touching them', function () {
    $fy = FiscalYear::forceCreate(['user_id' => $this->user->id, 'year' => 2024, 'status' => FiscalYear::STATUS_DRAFT]);
    legacyFeeEntries($fy, $this->property);

    $this->artisan('openlmnp:repair-accounting-entries')
        ->expectsOutputToContain('diffèrent du calcul actuel')
        ->expectsOutputToContain('résultat comptable : 1 880,00 € → 1 940,00 €')
        ->expectsOutputToContain('Rapport seul')
        ->assertExitCode(0);

    expect(stored706($fy))->toBe(194000);
});

it('regenerates the entries of a draft with --fix', function () {
    $fy = FiscalYear::forceCreate(['user_id' => $this->user->id, 'year' => 2024, 'status' => FiscalYear::STATUS_DRAFT]);
    legacyFeeEntries($fy, $this->property);

    $this->artisan('openlmnp:repair-accounting-entries', ['--fix' => true])->assertExitCode(0);

    expect(stored706($fy))->toBe(200000);

    $this->artisan('openlmnp:repair-accounting-entries')
        ->expectsOutputToContain('correspondent au calcul actuel')
        ->assertExitCode(0);
});

it('leaves a closed fiscal year alone unless --closed is given', function () {
    $fy = FiscalYear::forceCreate(['user_id' => $this->user->id, 'year' => 2024, 'status' => FiscalYear::STATUS_CLOSED]);
    legacyFeeEntries($fy, $this->property);

    $this->artisan('openlmnp:repair-accounting-entries', ['--fix' => true])
        ->expectsOutputToContain('--closed')
        ->assertExitCode(0);
    expect(stored706($fy))->toBe(194000);

    $this->artisan('openlmnp:repair-accounting-entries', ['--fix' => true, '--closed' => true])->assertExitCode(0);
    expect(stored706($fy))->toBe(200000)
        // On régénère les écritures, on ne rouvre pas l'exercice.
        ->and($fy->fresh()->status)->toBe(FiscalYear::STATUS_CLOSED);
});

it('stays silent when the stored entries are current', function () {
    $fy = FiscalYear::forceCreate(['user_id' => $this->user->id, 'year' => 2024, 'status' => FiscalYear::STATUS_DRAFT]);
    app(AccountingEntryService::class)->generateForFiscalYear($fy);

    $this->artisan('openlmnp:repair-accounting-entries')
        ->expectsOutputToContain('correspondent au calcul actuel')
        ->assertExitCode(0);
});
