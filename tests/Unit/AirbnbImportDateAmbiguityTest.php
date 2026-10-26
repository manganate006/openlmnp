<?php

use App\Models\Income;
use App\Models\Property;
use App\Models\User;
use App\Services\AirbnbImportService;
use Illuminate\Http\UploadedFile;

/**
 * `CsvValues::date()` essaie `d/m/Y` avant `m/d/Y` : pour les douze premiers jours du
 * mois, les deux lectures sont également valides et rien dans une date isolée ne permet
 * de trancher. Mais un fichier Airbnb n'est jamais un mélange des deux formats — dès
 * qu'UNE SEULE date du fichier est impossible en `d/m/Y` (un « mois 30 », par exemple),
 * c'est la preuve que le fichier entier est en `m/d/Y`, ambiguës comprises.
 */
beforeEach(function () {
    $this->user = User::factory()->create();
    $this->service = new AirbnbImportService();

    $this->property = Property::forceCreate([
        'user_id' => $this->user->id,
        'name' => 'Test Airbnb',
        'address' => '1 rue Test',
        'city' => 'Paris',
        'postal_code' => '75001',
        'type' => 'apartment',
        'total_area' => 100,
        'rented_area' => 100,
        'acquisition_date' => '2020-01-01',
        'acquisition_price' => 30000000,
        'notary_fees' => 0,
        'land_percentage' => 15,
        'rental_start_date' => '2023-01-01',
        'rental_type' => 'seasonal',
        'is_primary_residence' => false,
    ]);
});

it('switches an ambiguous date to month-first once another row in the same file proves the whole file is', function () {
    $csv = "Date,Confirmation code,Start date,End date,Amount,Host fee\n";
    // Ambiguë à elle seule : jour 4 mars (m/d/Y) ou jour 3 avril (d/m/Y).
    $csv .= "03/04/2026,AMB1,03/04/2026,03/06/2026,100.00,3.00\n";
    // Impossible en d/m/Y (mois 30) : la seule lecture licite est m/d/Y — et elle
    // fait basculer TOUT le fichier, y compris la ligne ambiguë ci-dessus.
    $csv .= "04/30/2026,SIG1,04/30/2026,05/02/2026,120.00,3.60\n";

    $file = UploadedFile::fake()->createWithContent('airbnb.csv', $csv);
    $result = $this->service->import($file, $this->property);

    expect($result['imported'])->toBe(2)
        ->and($result['errors'])->toBe([]);

    $ambiguous = Income::where('reservation_ref', 'AMB1')->firstOrFail();
    $signal = Income::where('reservation_ref', 'SIG1')->firstOrFail();

    expect($ambiguous->income_date->format('Y-m-d'))->toBe('2026-03-04')
        ->and($signal->income_date->format('Y-m-d'))->toBe('2026-04-30');
});

it('reads an unambiguous file day-first when nothing in it proves otherwise', function () {
    $csv = "Date,Confirmation code,Amount,Host fee\n"
        . "03/04/2026,DAYFIRST1,100.00,3.00\n";

    $file = UploadedFile::fake()->createWithContent('airbnb.csv', $csv);
    $this->service->import($file, $this->property);

    $income = Income::where('reservation_ref', 'DAYFIRST1')->firstOrFail();
    expect($income->income_date->format('Y-m-d'))->toBe('2026-04-03');
});

it('rejects a stay whose end date precedes its start date', function () {
    $csv = "Date,Confirmation code,Start date,End date,Amount,Host fee\n"
        . "2026-01-10,BAD1,2026-01-15,2026-01-12,100.00,3.00\n";

    $file = UploadedFile::fake()->createWithContent('airbnb.csv', $csv);
    $result = $this->service->import($file, $this->property);

    expect($result['imported'])->toBe(0)
        ->and($result['skipped'])->toBe(1)
        ->and($result['errors'])->toHaveCount(1)
        ->and($result['errors'][0])->toContain('antérieure');

    expect(Income::where('reservation_ref', 'BAD1')->exists())->toBeFalse();
});
