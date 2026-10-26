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

// === TABLEAU IMPORTÉ : garde et recalcul explicite ===

it('protège les échéances importées d\'un recalcul silencieux', function () {
    $loan = createLoanForUser($this->user, 'Studio', 'Banque A');

    // L'utilisateur remplace le tableau calculé par celui de sa banque.
    $loan->payments()->delete();
    $loan->payments()->create([
        'payment_date' => '2026-02-01',
        'month_number' => 1,
        'capital_amount' => 50000,
        'interest_amount' => 25000,
        'insurance_amount' => 3000,
        'remaining_capital' => 19950000,
    ]);
    $loan->forceFill(['schedule_source' => Loan::SCHEDULE_IMPORTED])->save();

    // Un appel ordinaire ne doit rien écraser.
    $recalcule = app(LoanService::class)->generateSchedule($loan->refresh());

    expect($recalcule)->toBeFalse()
        ->and($loan->payments()->count())->toBe(1)
        ->and($loan->refresh()->hasImportedSchedule())->toBeTrue();
});

it('recalcule le tableau théorique sur demande explicite et repasse en calculé', function () {
    $loan = createLoanForUser($this->user, 'Studio', 'Banque A');

    $loan->payments()->delete();
    $loan->payments()->create([
        'payment_date' => '2026-02-01',
        'month_number' => 1,
        'capital_amount' => 50000,
        'interest_amount' => 25000,
        'insurance_amount' => 3000,
        'remaining_capital' => 19950000,
    ]);
    $loan->forceFill(['schedule_source' => Loan::SCHEDULE_IMPORTED])->save();

    $recalcule = app(LoanService::class)->generateSchedule($loan->refresh(), force: true);

    expect($recalcule)->toBeTrue()
        ->and($loan->payments()->count())->toBe(240)
        // Sans ce retour à « computed », la garde protégerait des lignes qui ne
        // viennent plus de la banque.
        ->and($loan->refresh()->hasImportedSchedule())->toBeFalse();
});

it('n\'offre l\'action de recalcul que sur un tableau importé', function () {
    $loan = createLoanForUser($this->user, 'Studio', 'Banque A');

    $this->actingAs($this->user);

    // Tableau calculé : l'action n'a pas lieu d'être.
    Livewire\Livewire::test(\App\Filament\Pages\LoanDetail::class, ['loanId' => $loan->id])
        ->assertActionHidden('recomputeSchedule');

    $loan->forceFill(['schedule_source' => Loan::SCHEDULE_IMPORTED])->save();

    Livewire\Livewire::test(\App\Filament\Pages\LoanDetail::class, ['loanId' => $loan->id])
        ->assertActionVisible('recomputeSchedule')
        ->assertSee('Échéances importées de votre banque');
});

it('affiche l\'écart d\'intérêts entre tableau importé et tableau théorique', function () {
    $loan = createLoanForUser($this->user, 'Studio', 'Banque A');
    $annee = (int) date('Y');

    // Échéances « réelles » de l'année en cours, volontairement différentes.
    $loan->payments()->delete();
    foreach (range(1, 12) as $mois) {
        $loan->payments()->create([
            'payment_date' => sprintf('%d-%02d-01', $annee, $mois),
            'month_number' => $mois,
            'capital_amount' => 60000,
            'interest_amount' => 20000,
            'insurance_amount' => 3000,
            'remaining_capital' => 20000000 - 60000 * $mois,
        ]);
    }
    $loan->forceFill(['schedule_source' => Loan::SCHEDULE_IMPORTED])->save();

    $this->actingAs($this->user);

    $ecart = Livewire\Livewire::test(\App\Filament\Pages\LoanDetail::class, ['loanId' => $loan->id])
        ->instance()->theoreticalGap;

    expect($ecart['year'])->toBe($annee)
        ->and($ecart['imported'])->toBe(240000)
        ->and($ecart['gap'])->toBe($ecart['imported'] - $ecart['theoretical'])
        // La simulation théorique ne doit RIEN écrire : le tableau reste intact.
        ->and($loan->payments()->count())->toBe(12);
});

it('rattache un justificatif à un emprunt', function () {
    $loan = createLoanForUser($this->user, 'Studio', 'Banque A');

    $loan->documents()->create([
        'label' => 'Tableau d\'amortissement 2026',
        'file_path' => "documents/{$this->user->id}/emprunts/tableau.pdf",
        'sort_order' => 0,
    ]);

    expect($loan->documents)->toHaveCount(1)
        ->and($loan->documents->first()->label)->toBe('Tableau d\'amortissement 2026');
});
