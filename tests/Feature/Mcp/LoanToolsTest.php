<?php

use App\Models\Loan;
use App\Models\LoanPayment;
use App\Models\Property;
use App\Models\User;

/**
 * Outils d'écriture `create_loan` / `update_loan` (lot 2 des frais d'emprunt) — absents
 * jusqu'ici : get_loan/list_loans existaient en lecture, mais aucun outil MCP ne permettait
 * de créer ou modifier un emprunt.
 */
beforeEach(function () {
    config(['mcp.enabled' => true]);

    $this->user = User::factory()->create(['mcp_enabled' => true]);
    $this->token = $this->user->createToken('test-token');

    $this->property = Property::forceCreate([
        'user_id' => $this->user->id,
        'name' => 'Bien à emprunt',
        'address' => '1 rue de l\'Emprunt',
        'city' => 'Lyon',
        'postal_code' => '69003',
        'type' => 'apartment',
        'total_area' => 100,
        'rented_area' => 100,
        'acquisition_date' => '2022-01-01',
        'acquisition_price' => 20000000,
        'notary_fees' => 0,
        'land_percentage' => 15,
        'rental_start_date' => '2022-01-01',
        'rental_type' => 'seasonal',
        'is_primary_residence' => false,
    ]);
});

function callLoanTool($test, string $tool, array $arguments)
{
    return $test->withToken($test->token->plainTextToken)
        ->postJson('/mcp', [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => ['name' => $tool, 'arguments' => $arguments],
        ]);
}

// === create_loan ===

it('creates a loan via MCP and generates its amortization schedule', function () {
    $response = callLoanTool($this, 'create_loan', [
        'property_id' => $this->property->id,
        'bank_name' => 'Banque MCP',
        'amount' => 200000,
        'annual_rate' => 1.5,
        'duration_months' => 240,
        'start_date' => '2024-01-01',
        'fees_amount' => 1500,
        'fees_paid_on' => '2024-01-01',
    ]);

    $response->assertOk();
    $result = json_decode($response->json('result.content.0.text', '{}'), true);

    expect($result['success'])->toBeTrue()
        ->and($result['amount_eur'])->toBe('200000.00')
        ->and($result['fees_amount_eur'])->toBe('1500.00')
        ->and($result['fees_spread_method'])->toBe('linear')
        ->and($result['schedule_generated'])->toBeTrue()
        ->and($result['payments_count'])->toBe(240);

    $loan = Loan::where('property_id', $this->property->id)->first();
    expect($loan)->not->toBeNull()
        ->and($loan->amount)->toBe(20000000)
        ->and($loan->fees_amount)->toBe(150000)
        ->and($loan->payments()->count())->toBe(240);
});

it('creates a loan with the interest spread method and early repayment fields via MCP', function () {
    $response = callLoanTool($this, 'create_loan', [
        'property_id' => $this->property->id,
        'amount' => 100000,
        'annual_rate' => 2,
        'duration_months' => 120,
        'start_date' => '2024-01-01',
        'fees_amount' => 1000,
        'fees_spread_method' => 'interest',
        'early_repayment_date' => '2027-01-01',
        'early_repayment_amount' => 50000,
    ]);

    $response->assertOk();
    $result = json_decode($response->json('result.content.0.text', '{}'), true);

    expect($result['fees_spread_method'])->toBe('interest')
        ->and($result['early_repayment_date'])->toBe('2027-01-01')
        ->and($result['early_repayment_amount_eur'])->toBe('50000.00');

    $loan = Loan::where('property_id', $this->property->id)->first();
    expect($loan->fees_spread_method)->toBe('interest')
        ->and($loan->early_repayment_amount)->toBe(5000000);
});

it('refuses to create a loan on another user property via MCP', function () {
    $other = User::factory()->create();
    $otherProperty = Property::forceCreate([
        'user_id' => $other->id,
        'name' => 'Bien de B',
        'address' => '2 rue B',
        'city' => 'Lyon',
        'postal_code' => '69001',
        'type' => 'house',
        'total_area' => 80,
        'rented_area' => 80,
        'acquisition_date' => '2021-01-01',
        'acquisition_price' => 20000000,
        'notary_fees' => 0,
        'land_percentage' => 15,
        'rental_start_date' => '2021-06-01',
        'rental_type' => 'seasonal',
        'is_primary_residence' => false,
    ]);

    $response = callLoanTool($this, 'create_loan', [
        'property_id' => $otherProperty->id,
        'amount' => 100000,
        'annual_rate' => 1.5,
        'duration_months' => 120,
        'start_date' => '2024-01-01',
    ]);

    $response->assertNotFound();
    expect(Loan::withoutGlobalScopes()->where('property_id', $otherProperty->id)->count())->toBe(0);
});

// === update_loan ===

function createMcpLoan(Property $property, array $overrides = []): Loan
{
    $loan = Loan::create(array_merge([
        'property_id' => $property->id,
        'bank_name' => 'Banque initiale',
        'amount' => 10000000,
        'annual_rate' => 2.0,
        'duration_months' => 120,
        'start_date' => '2024-01-01',
        'monthly_payment' => 0,
        'insurance_monthly' => 0,
    ], $overrides));

    app(\App\Services\LoanService::class)->generateSchedule($loan);

    return $loan;
}

it('updates a loan via MCP without touching the schedule when no amortization field changes', function () {
    $loan = createMcpLoan($this->property);
    $paymentsBefore = $loan->payments()->pluck('interest_amount')->all();

    $response = callLoanTool($this, 'update_loan', [
        'loan_id' => $loan->id,
        'bank_name' => 'Nouvelle banque',
        'fees_amount' => 800,
        'fees_spread_method' => 'interest',
    ]);

    $response->assertOk();
    $result = json_decode($response->json('result.content.0.text', '{}'), true);

    expect($result['success'])->toBeTrue()
        ->and($result['updated_fields'])->toContain('bank_name', 'fees_amount', 'fees_spread_method')
        ->and($result['schedule_regenerated'])->toBeFalse();

    $loan->refresh();
    expect($loan->bank_name)->toBe('Nouvelle banque')
        ->and($loan->fees_amount)->toBe(80000)
        ->and($loan->fees_spread_method)->toBe('interest')
        ->and($loan->payments()->pluck('interest_amount')->all())->toBe($paymentsBefore);
});

it('regenerates the schedule via MCP when an amortization field changes', function () {
    $loan = createMcpLoan($this->property);

    $response = callLoanTool($this, 'update_loan', [
        'loan_id' => $loan->id,
        'duration_months' => 180,
    ]);

    $response->assertOk();
    $result = json_decode($response->json('result.content.0.text', '{}'), true);

    expect($result['schedule_regenerated'])->toBeTrue();

    $loan->refresh();
    expect($loan->duration_months)->toBe(180)
        ->and($loan->payments()->count())->toBe(180);
});

it('does not overwrite an imported schedule via update_loan, even when amortization fields change', function () {
    $loan = createMcpLoan($this->property);
    $loan->payments()->delete();
    LoanPayment::create([
        'loan_id' => $loan->id,
        'payment_date' => '2024-02-01',
        'month_number' => 1,
        'capital_amount' => 77777,
        'interest_amount' => 16666,
        'insurance_amount' => 0,
        'remaining_capital' => 9999999,
    ]);
    $loan->forceFill(['schedule_source' => Loan::SCHEDULE_IMPORTED])->save();

    $response = callLoanTool($this, 'update_loan', [
        'loan_id' => $loan->id,
        'annual_rate' => 3.5,
    ]);

    $response->assertOk();
    $result = json_decode($response->json('result.content.0.text', '{}'), true);

    expect($result['schedule_regenerated'])->toBeFalse()
        ->and($result['schedule_skipped_reason'])->toContain('importé');

    expect($loan->payments()->count())->toBe(1)
        ->and((int) $loan->payments()->first()->interest_amount)->toBe(16666);
});

it('prevents a user from updating another user loan via MCP', function () {
    $other = User::factory()->create();
    $otherProperty = Property::forceCreate([
        'user_id' => $other->id,
        'name' => 'Bien de B',
        'address' => '2 rue B',
        'city' => 'Lyon',
        'postal_code' => '69001',
        'type' => 'house',
        'total_area' => 80,
        'rented_area' => 80,
        'acquisition_date' => '2021-01-01',
        'acquisition_price' => 20000000,
        'notary_fees' => 0,
        'land_percentage' => 15,
        'rental_start_date' => '2021-06-01',
        'rental_type' => 'seasonal',
        'is_primary_residence' => false,
    ]);
    $otherLoan = createMcpLoan($otherProperty);

    $response = callLoanTool($this, 'update_loan', [
        'loan_id' => $otherLoan->id,
        'bank_name' => 'Détournement',
    ]);

    $response->assertNotFound();
    expect($otherLoan->fresh()->bank_name)->toBe('Banque initiale');
});
