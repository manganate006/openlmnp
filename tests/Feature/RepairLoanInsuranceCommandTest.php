<?php

use App\Models\FiscalYear;
use App\Models\Loan;
use App\Models\Property;
use App\Models\User;
use App\Services\LoanService;

function makeLoanRepairFixture(): array
{
    $user = User::factory()->create();

    $property = Property::forceCreate([
        'user_id' => $user->id,
        'name' => 'Bien Emprunt',
        'address' => '1 rue Test',
        'city' => 'Paris',
        'postal_code' => '75001',
        'type' => 'apartment',
        'total_area' => 50,
        'rented_area' => 50,
        'acquisition_date' => '2023-01-01',
        'acquisition_price' => 24_118_900,
        'notary_fees' => 0,
        'land_percentage' => 15,
        'rental_start_date' => '2023-01-01',
        'rental_type' => 'seasonal',
        'is_primary_residence' => false,
    ]);

    $loan = Loan::create([
        'property_id' => $property->id,
        'bank_name' => 'Banque Test',
        'amount' => 24_257_300,
        'annual_rate' => 1.95,
        'duration_months' => 240,
        'start_date' => '2023-01-01',
        'monthly_payment' => 122_100,
        'insurance_type' => Loan::INSURANCE_VARIABLE,
        'insurance_rate' => 2.21,
        'insurance_monthly' => 0,
    ]);

    return [$user, $property, $loan];
}

it('regenerates a schedule whose insurance was computed 100x too high', function () {
    [$user, $property, $loan] = makeLoanRepairFixture();

    app(LoanService::class)->generateSchedule($loan);

    // On simule l'état corrompu d'avant le correctif : x100 sur l'assurance.
    $loan->payments()->withoutGlobalScopes()->each(function ($p) {
        $p->forceFill(['insurance_amount' => $p->insurance_amount * 100])->save();
    });

    $before = (int) $loan->payments()->withoutGlobalScopes()->sum('insurance_amount');

    $this->artisan('openlmnp:repair-loan-insurance', ['--fix' => true])->assertSuccessful();

    $after = (int) $loan->payments()->withoutGlobalScopes()->sum('insurance_amount');

    expect($after)->toBe(intdiv($before, 100));
});

it('recalculates draft fiscal years but leaves closed ones alone', function () {
    [$user, $property, $loan] = makeLoanRepairFixture();
    app(LoanService::class)->generateSchedule($loan);

    // Totaux volontairement faux : s'ils changent, un recalcul a eu lieu.
    $draft = FiscalYear::forceCreate([
        'user_id' => $user->id, 'year' => 2023, 'status' => FiscalYear::STATUS_DRAFT,
        'total_income' => 0, 'total_expenses' => 52_982_971, 'fiscal_result' => 0,
    ]);
    $closed = FiscalYear::forceCreate([
        'user_id' => $user->id, 'year' => 2024, 'status' => FiscalYear::STATUS_CLOSED,
        'total_income' => 0, 'total_expenses' => 52_982_971, 'fiscal_result' => 0,
    ]);

    $this->artisan('openlmnp:repair-loan-insurance', ['--fix' => true])
        ->expectsOutputToContain('CLÔTURÉS')
        ->assertSuccessful();

    expect($draft->fresh()->total_expenses)->not->toBe(52_982_971)
        ->and($closed->fresh()->total_expenses)->toBe(52_982_971);
});

it('does not modify anything without --fix', function () {
    [$user, $property, $loan] = makeLoanRepairFixture();
    app(LoanService::class)->generateSchedule($loan);

    $before = (int) $loan->payments()->withoutGlobalScopes()->sum('insurance_amount');

    $this->artisan('openlmnp:repair-loan-insurance')->assertSuccessful();

    expect((int) $loan->payments()->withoutGlobalScopes()->sum('insurance_amount'))->toBe($before);
});
