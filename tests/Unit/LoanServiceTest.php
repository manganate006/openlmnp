<?php

use App\Models\Loan;
use App\Models\Property;
use App\Models\User;
use App\Services\LoanService;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->service = new LoanService();

    $this->property = Property::forceCreate([
        'user_id' => $this->user->id,
        'name' => 'Test',
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
});

it('generates a loan schedule with correct number of payments', function () {
    $loan = Loan::create([
        'property_id' => $this->property->id,
        'bank_name' => 'BNP',
        'amount' => 20000000, // 200k€
        'annual_rate' => 1.500,
        'duration_months' => 240, // 20 ans
        'start_date' => '2020-06-01',
        'monthly_payment' => 0,
        'insurance_monthly' => 3000, // 30€
    ]);

    $this->service->generateSchedule($loan);

    expect($loan->payments()->count())->toBe(240);
    expect($loan->fresh()->monthly_payment)->toBeGreaterThan(0);
});

it('calculates remaining capital to zero at end of loan', function () {
    $loan = Loan::create([
        'property_id' => $this->property->id,
        'amount' => 10000000, // 100k€
        'annual_rate' => 2.000,
        'duration_months' => 120, // 10 ans
        'start_date' => '2020-01-01',
        'monthly_payment' => 0,
        'insurance_monthly' => 0,
    ]);

    $this->service->generateSchedule($loan);

    $lastPayment = $loan->payments()->where('month_number', 120)->first();
    expect($lastPayment)->not->toBeNull();
    expect($lastPayment->remaining_capital)->toBe(0);

    // Vérifier que la somme des capitaux remboursés = montant emprunté
    $totalCapitalPaid = $loan->payments()->sum('capital_amount');
    expect($totalCapitalPaid)->toBe($loan->amount);
});

it('calculates deductible interest with quota share', function () {
    $loan = Loan::create([
        'property_id' => $this->property->id,
        'amount' => 20000000,
        'annual_rate' => 1.500,
        'duration_months' => 240,
        'start_date' => '2020-01-01',
        'monthly_payment' => 0,
        'insurance_monthly' => 0,
    ]);

    $this->service->generateSchedule($loan);

    $deductible = $this->service->getDeductibleInterest($loan, 2024);

    // Quota share = 50%, so deductible interest = total interest * 0.5
    expect($deductible)->toBeGreaterThan(0);

    $totalInterest = $loan->payments()->whereYear('payment_date', 2024)->sum('interest_amount');
    $expected = (int) bcmul((string) $totalInterest, '0.5', 0);
    expect($deductible)->toBe($expected);
});
