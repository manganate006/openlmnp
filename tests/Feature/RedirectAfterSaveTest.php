<?php

use App\Filament\Actions\GenerateOccurrencesAction;
use App\Filament\Resources\Expenses\ExpenseResource;
use App\Filament\Resources\Expenses\Pages\EditExpense;
use App\Filament\Resources\Furniture\FurnitureResource;
use App\Filament\Resources\Furniture\Pages\EditFurniture;
use App\Filament\Resources\Incomes\IncomeResource;
use App\Filament\Resources\Incomes\Pages\EditIncome;
use App\Filament\Resources\Loans\LoanResource;
use App\Filament\Resources\Loans\Pages\EditLoan;
use App\Filament\Resources\Properties\Pages\EditProperty;
use App\Filament\Resources\PropertyWorks\Pages\EditPropertyWork;
use App\Filament\Resources\PropertyWorks\PropertyWorkResource;
use App\Models\Expense;
use App\Models\Furniture;
use App\Models\Income;
use App\Models\Loan;
use App\Models\Property;
use App\Models\PropertyWork;
use App\Models\User;
use Livewire\Livewire;

/**
 * Ticket #16 (cocool97) : après « Enregistrer », on restait sur le formulaire. Les pages de
 * saisie courante reviennent à leur liste ; la fiche d'un bien reste en place.
 */
beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);

    $this->property = Property::create([
        'user_id' => $this->user->id, 'name' => 'Studio', 'address' => '1 rue du Test',
        'city' => 'Lyon', 'postal_code' => '69003', 'type' => 'apartment',
        'total_area' => 45, 'rented_area' => 45, 'acquisition_date' => '2022-01-01',
        'acquisition_price' => 20000000, 'land_percentage' => 15,
        'rental_start_date' => '2022-03-01', 'rental_type' => 'seasonal',
        'is_primary_residence' => false,
    ]);
});

it('revient à la liste des charges', function () {
    $expense = Expense::create([
        'property_id' => $this->property->id, 'category' => 'insurance',
        'description' => 'Assurance PNO', 'amount' => 25000, 'expense_date' => '2024-05-10',
    ]);

    Livewire::test(EditExpense::class, ['record' => $expense->getRouteKey()])
        ->call('save')
        ->assertHasNoFormErrors()
        ->assertRedirect(ExpenseResource::getUrl());
});

it('garde la proposition de générer les échéances d\'une charge mensuelle', function () {
    $expense = Expense::create([
        'property_id' => $this->property->id, 'category' => 'insurance',
        'description' => 'Box internet', 'amount' => 3000, 'expense_date' => '2024-01-10',
        'recurring_type' => 'monthly',
    ]);

    // La même notification qu'avant la redirection, bouton « Générer les échéances » compris :
    // flashée en session, elle s'affiche sur la liste.
    $attendue = GenerateOccurrencesAction::proposal(
        $expense,
        __('filament-panels::resources/pages/edit-record.notifications.saved.title'),
    );
    expect($attendue)->not->toBeNull();

    Livewire::test(EditExpense::class, ['record' => $expense->getRouteKey()])
        ->call('save')
        ->assertRedirect(ExpenseResource::getUrl())
        ->assertNotified($attendue);
});

it('revient à la liste des recettes', function () {
    $income = Income::create([
        'property_id' => $this->property->id, 'income_date' => '2024-03-01',
        'amount' => 100000, 'platform_fee' => 0, 'tourist_tax' => 0, 'source' => 'airbnb',
    ]);

    Livewire::test(EditIncome::class, ['record' => $income->getRouteKey()])
        ->call('save')
        ->assertHasNoFormErrors()
        ->assertRedirect(IncomeResource::getUrl());
});

it('revient à la liste des emprunts', function () {
    $loan = Loan::create([
        'property_id' => $this->property->id, 'bank_name' => 'Banque', 'amount' => 10000000,
        'annual_rate' => 2.0, 'duration_months' => 120, 'start_date' => '2024-01-01',
        'monthly_payment' => 0, 'insurance_monthly' => 0,
    ]);

    Livewire::test(EditLoan::class, ['record' => $loan->getRouteKey()])
        ->call('save')
        ->assertHasNoFormErrors()
        ->assertRedirect(LoanResource::getUrl());
});

it('revient au mobilier du bien', function () {
    $item = Furniture::create([
        'property_id' => $this->property->id, 'description' => 'Canapé', 'amount' => 150000,
        'tva_rate' => 0, 'purchase_date' => '2024-01-01', 'duration_years' => 5,
    ]);

    Livewire::test(EditFurniture::class, ['record' => $item->getRouteKey()])
        ->call('save')
        ->assertHasNoFormErrors()
        ->assertRedirect(FurnitureResource::getUrl('property', ['propertyId' => $this->property->id]));
});

it('revient aux travaux du bien', function () {
    $work = PropertyWork::create([
        'property_id' => $this->property->id, 'description' => 'Salle de bain',
        'amount' => 1000000, 'tva_rate' => 0, 'work_date' => '2024-01-01', 'duration_years' => 10,
    ]);

    Livewire::test(EditPropertyWork::class, ['record' => $work->getRouteKey()])
        ->call('save')
        ->assertHasNoFormErrors()
        ->assertRedirect(PropertyWorkResource::getUrl('property', ['propertyId' => $this->property->id]));
});

it('laisse la fiche d\'un bien sur le formulaire', function () {
    Livewire::test(EditProperty::class, ['record' => $this->property->getRouteKey()])
        ->call('save')
        ->assertNoRedirect();
});
