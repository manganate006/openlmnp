<?php

use App\Models\Loan;
use App\Models\Property;
use App\Models\User;
use App\Services\LoanService;

beforeEach(function () {
    $this->user = User::factory()->create();
});

function createLoanForUser(User $user, string $propertyName, string $bankName): Loan
{
    $property = Property::forceCreate([
        'user_id' => $user->id,
        'name' => $propertyName,
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

    $loan = Loan::create([
        'property_id' => $property->id,
        'bank_name' => $bankName,
        'amount' => 20000000,
        'annual_rate' => 1.500,
        'duration_months' => 240,
        'start_date' => '2021-01-01',
        'monthly_payment' => 0,
        'insurance_monthly' => 3000,
        'insurance_type' => 'fixed',
        'insurance_rate' => 0,
    ]);

    app(LoanService::class)->generateSchedule($loan);

    return $loan;
}

it('shows loan detail page for own loan', function () {
    $loan = createLoanForUser($this->user, 'Mon Bien', 'Ma Banque Test');

    $this->actingAs($this->user)
        ->get('/loan-detail?loanId=' . $loan->id)
        ->assertOk()
        ->assertSee('Ma Banque Test')
        ->assertSee('Mon Bien');
});

it('redirects instead of crashing when opening another user loan detail', function () {
    $otherUser = User::factory()->create();
    $foreignLoan = createLoanForUser($otherUser, 'Bien Étranger', 'Banque Étrangère');

    $this->actingAs($this->user)
        ->get('/loan-detail?loanId=' . $foreignLoan->id)
        ->assertRedirect('/loans');
});

it('redirects when loan does not exist', function () {
    $this->actingAs($this->user)
        ->get('/loan-detail?loanId=999999')
        ->assertRedirect('/loans');
});

it('falls back to the first own loan when no loanId is given', function () {
    $otherUser = User::factory()->create();
    createLoanForUser($otherUser, 'Bien Étranger', 'Banque Étrangère');
    createLoanForUser($this->user, 'Mon Bien', 'Ma Banque Test');

    $this->actingAs($this->user)
        ->get('/loan-detail')
        ->assertOk()
        ->assertSee('Ma Banque Test')
        ->assertDontSee('Banque Étrangère');
});

it('shows the empty state when the user has no loan', function () {
    $this->actingAs($this->user)
        ->get('/loan-detail')
        ->assertOk()
        ->assertSee('Aucun emprunt trouvé');
});

it('does not list other users loans on the loans page', function () {
    createLoanForUser($this->user, 'Mon Bien', 'Ma Banque Test');
    createLoanForUser(User::factory()->create(), 'Bien Étranger', 'Banque Étrangère');

    $this->actingAs($this->user)
        ->get('/loans')
        ->assertOk()
        ->assertSee('Ma Banque Test')
        ->assertDontSee('Banque Étrangère');
});
