<?php

use App\Models\AccountingEntry;
use App\Models\FiscalYear;
use App\Models\User;
use App\Services\FecService;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->user = User::factory()->create(['siren' => '123456789']);
    $this->service = new FecService();
});

it('generates a valid fec file with correct column names', function () {
    $fiscalYear = FiscalYear::forceCreate([
        'user_id' => $this->user->id,
        'year' => 2024,
        'status' => 'draft',
    ]);

    AccountingEntry::create([
        'fiscal_year_id' => $fiscalYear->id,
        'entry_date' => '2024-06-15',
        'account_code' => '706',
        'label' => 'Loyer Airbnb',
        'debit' => 0,
        'credit' => 150000,
        'piece_ref' => 'REC-1',
        'journal' => 'VE',
    ]);

    $path = $this->service->generate($fiscalYear);

    // File naming: {SIREN}FEC{YYYYMMDD}.txt
    expect($path)->toContain('123456789FEC20241231');

    $content = Storage::get($path);
    $lines = explode("\r\n", trim($content));

    expect(count($lines))->toBe(2);

    // Header: 18 columns with correct names
    $header = explode("\t", $lines[0]);
    expect(count($header))->toBe(18);
    expect($header[0])->toBe('JournalCode');
    expect($header[13])->toBe('EcritureLet'); // Not EcrtureLettrage
    expect($header[14])->toBe('DateLet'); // Not DateLettrage
    expect($header[17])->toBe('Idevise');
});

it('formats amounts with comma decimal separator', function () {
    $fiscalYear = FiscalYear::forceCreate([
        'user_id' => $this->user->id,
        'year' => 2024,
        'status' => 'draft',
    ]);

    AccountingEntry::create([
        'fiscal_year_id' => $fiscalYear->id,
        'entry_date' => '2024-01-01',
        'account_code' => '681',
        'label' => 'Amortissement',
        'debit' => 123456,
        'credit' => 0,
        'piece_ref' => 'AMO-1',
        'journal' => 'OD',
    ]);

    $path = $this->service->generate($fiscalYear);
    $content = Storage::get($path);
    $lines = explode("\r\n", trim($content));
    $entry = explode("\t", $lines[1]);
    // Ensure we have 18 columns (trailing empty fields may be trimmed)
    while (count($entry) < 18) { $entry[] = ''; }

    expect($entry[11])->toBe('1234,56'); // Debit with comma
    expect($entry[12])->toBe('0,00'); // Credit zero
    // Idevise and Montantdevise empty for EUR
    expect($entry[16])->toBe(''); // Montantdevise empty
    expect($entry[17])->toBe(''); // Idevise empty (not EUR)
});
