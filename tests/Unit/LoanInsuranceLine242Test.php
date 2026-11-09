<?php

use App\Models\Expense;
use App\Models\Loan;
use App\Models\Property;
use App\Models\User;
use App\Services\FiscalYearService;
use App\Services\LoanService;
use App\Services\TaxReturnService;

/**
 * L'assurance emprunteur est une prime d'assurance : repère 242 du 2033-B (autres charges
 * externes, « primes d'assurance » dans la notice 2033-NOT-SD 2026), pas 294. La ligne 294
 * (charges financières) ne garde que les intérêts. Le résultat (310) ne change pas, mais les
 * repères 242, 264, 270 et 294 de la liasse imprimée en dépendent.
 * Registre _admin/docs/pieges-fiscaux.md, P24.
 */
beforeEach(function () {
    $this->user = User::factory()->create();
    $this->fiscalYearService = app(FiscalYearService::class);
    $this->loanService = app(LoanService::class);
    $this->taxReturnService = app(TaxReturnService::class);
});

function insuredLoanProperty(User $user): Property
{
    return Property::forceCreate([
        'user_id' => $user->id,
        'name' => 'Studio assuré',
        'address' => '1 rue Test',
        'city' => 'Paris',
        'postal_code' => '75001',
        'type' => 'apartment',
        'total_area' => 100,
        'rented_area' => 50, // quote-part de 50 %
        'acquisition_date' => '2022-01-01',
        'acquisition_price' => 20000000,
        'notary_fees' => 0,
        'land_percentage' => 0,
        'rental_start_date' => '2022-01-01',
        'rental_type' => 'seasonal',
        'is_primary_residence' => true,
    ]);
}

function insuredLoan(Property $property, bool $isDedicated): Loan
{
    return Loan::create([
        'property_id' => $property->id,
        'bank_name' => $isDedicated ? 'Prêt travaux' : 'Prêt immobilier',
        'amount' => 10000000,
        'annual_rate' => 2.0,
        'duration_months' => 120,
        'start_date' => '2024-01-01',
        'monthly_payment' => 0,
        'insurance_monthly' => 3000, // 30 € par mois
        'is_dedicated' => $isDedicated,
    ]);
}

it('puts borrower insurance on line 242 and keeps only interest on line 294', function () {
    $property = insuredLoanProperty($this->user);
    $shared = insuredLoan($property, isDedicated: false);
    $dedicated = insuredLoan($property, isDedicated: true);
    $this->loanService->generateSchedule($shared);
    $this->loanService->generateSchedule($dedicated);

    Expense::forceCreate([
        'property_id' => $property->id,
        'expense_date' => '2024-03-01',
        'category' => 'insurance',
        'description' => 'Assurance PNO',
        'amount' => 20000,
        'is_dedicated' => true,
    ]);

    $fiscalYear = $this->fiscalYearService->getOrCreate($this->user, 2024);
    $properties = Property::withoutGlobalScopes()->where('user_id', $this->user->id)->get();
    $form = $this->taxReturnService->compute2033B($fiscalYear, $properties, 2024);

    $quota = $property->quota_share;
    $interest = (int) $shared->prorate((string) $shared->getInterestsForYear(2024), $quota)
        + (int) $dedicated->prorate((string) $dedicated->getInterestsForYear(2024), $quota);
    $insurance = (int) $shared->prorate((string) $shared->getInsuranceForYear(2024), $quota)
        + (int) $dedicated->prorate((string) $dedicated->getInsuranceForYear(2024), $quota);

    expect($insurance)->toBeGreaterThan(0)
        ->and($form['294'])->toBe($interest)
        ->and($form['242'])->toBe(20000 + $insurance)
        ->and($form['264'])->toBe($form['242'] + $form['244'] + $form['254'])
        ->and($form['270'])->toBe($form['232'] - $form['264'])
        ->and($form['310'])->toBe($form['270'] - $form['294'])
        ->and($form['242'] + $form['244'] + $form['294'])->toBe((int) $fiscalYear->total_expenses);
});

it('books borrower insurance in the accounting entries (616), not only the interest', function () {
    $property = insuredLoanProperty($this->user);
    $loan = insuredLoan($property, isDedicated: false);
    $this->loanService->generateSchedule($loan);

    $fiscalYear = $this->fiscalYearService->getOrCreate($this->user, 2024);
    app(\App\Services\AccountingEntryService::class)->generateForFiscalYear($fiscalYear);

    $expectedInsurance = $loan->payments()->whereYear('payment_date', 2024)->get()
        ->sum(fn ($p) => (int) $loan->prorate((string) $p->insurance_amount, $property->quota_share));
    $expectedInterest = $loan->payments()->whereYear('payment_date', 2024)->get()
        ->sum(fn ($p) => (int) $loan->prorate((string) $p->interest_amount, $property->quota_share));

    expect($expectedInsurance)->toBeGreaterThan(0)
        ->and((int) $fiscalYear->accountingEntries()->where('account_code', '616')->sum('debit'))->toBe($expectedInsurance)
        ->and((int) $fiscalYear->accountingEntries()->where('account_code', '6611')->sum('debit'))->toBe($expectedInterest)
        ->and((int) $fiscalYear->accountingEntries()->sum('debit'))->toBe((int) $fiscalYear->accountingEntries()->sum('credit'));
});
