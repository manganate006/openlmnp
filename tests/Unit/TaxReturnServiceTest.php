<?php

use App\Models\Expense;
use App\Models\Income;
use App\Models\Property;
use App\Models\User;
use App\Services\DepreciationService;
use App\Services\TaxReturnService;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->service = app(TaxReturnService::class);

    $this->property = Property::forceCreate([
        'user_id' => $this->user->id,
        'name' => 'Test PDF',
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

    app(DepreciationService::class)->generateDefaultComponents($this->property);
});

it('generates a PDF tax return', function () {
    Income::create([
        'property_id' => $this->property->id,
        'income_date' => '2024-06-15',
        'amount' => 500000,
        'platform_fee' => 15000,
        'tourist_tax' => 0,
        'source' => 'airbnb',
    ]);

    Expense::create([
        'property_id' => $this->property->id,
        'expense_date' => '2024-01-01',
        'amount' => 100000,
        'category' => 'property_tax',
        'description' => 'TF',
        'is_dedicated' => true,
        'recurring_type' => 'yearly',
    ]);

    $fiscalYear = \App\Models\FiscalYear::forceCreate([
        'user_id' => $this->user->id,
        'year' => 2024,
        'status' => 'draft',
    ]);

    $path = $this->service->generatePdf($fiscalYear);

    expect($path)->toContain('liasse_fiscale_2024');
    expect(Storage::exists($path))->toBeTrue();

    $fiscalYear->refresh();
    expect($fiscalYear->pdf_path)->toBe($path);
    expect($fiscalYear->fiscal_result)->toBeGreaterThanOrEqual(0);
});
