<?php

use App\Models\Property;
use App\Models\User;
use App\Services\AirbnbImportService;
use Illuminate\Http\UploadedFile;

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

it('imports airbnb csv with english headers', function () {
    $csv = "Date,Type,Confirmation code,Start date,Nights,Guest,Listing,Amount,Host fee,Paid out\n";
    $csv .= "2024-06-15,Payout,ABC123,2024-06-10,3,John Doe,My Listing,150.00,4.50,145.50\n";
    $csv .= "2024-07-20,Payout,DEF456,2024-07-18,2,Jane Smith,My Listing,200.00,6.00,194.00\n";

    $file = UploadedFile::fake()->createWithContent('airbnb.csv', $csv);
    $result = $this->service->import($file, $this->property);

    expect($result['imported'])->toBe(2);
    expect($result['skipped'])->toBe(0);
});

it('skips duplicate reservations', function () {
    $csv = "Date,Confirmation code,Amount,Host fee\n";
    $csv .= "2024-06-15,ABC123,150.00,4.50\n";

    $file1 = UploadedFile::fake()->createWithContent('airbnb1.csv', $csv);
    $this->service->import($file1, $this->property);

    // Import again — should skip the duplicate
    $file2 = UploadedFile::fake()->createWithContent('airbnb2.csv', $csv);
    $result = $this->service->import($file2, $this->property);

    expect($result['imported'])->toBe(0);
    expect($result['skipped'])->toBe(1);
});

it('skips negative amounts (refunds)', function () {
    $csv = "Date,Confirmation code,Amount,Host fee\n";
    $csv .= "2024-06-15,REF001,-50.00,0\n";

    $file = UploadedFile::fake()->createWithContent('airbnb.csv', $csv);
    $result = $this->service->import($file, $this->property);

    expect($result['imported'])->toBe(0);
});

it('parses european money format', function () {
    $csv = "Date,Confirmation code,Amount,Host fee\n";
    $csv .= "2024-06-15,EUR001,\"1.234,56\",\"37,04\"\n";

    $file = UploadedFile::fake()->createWithContent('airbnb.csv', $csv);
    $result = $this->service->import($file, $this->property);

    expect($result['imported'])->toBe(1);

    $income = $this->property->incomes()->first();
    expect($income->amount)->toBe(123456); // 1234.56€ in cents
});

it('handles french column headers', function () {
    $csv = "Date,Code de confirmation,Montant,Frais de service hôte,Voyageur\n";
    $csv .= "2024-06-15,FR001,250.00,7.50,Pierre Dupont\n";

    $file = UploadedFile::fake()->createWithContent('airbnb.csv', $csv);
    $result = $this->service->import($file, $this->property);

    expect($result['imported'])->toBe(1);

    $income = $this->property->incomes()->first();
    expect($income->guest_name)->toBe('Pierre Dupont');
    expect($income->platform_fee)->toBe(750); // 7.50€
});
