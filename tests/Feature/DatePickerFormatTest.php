<?php

use App\Filament\Resources\Expenses\Pages\CreateExpense;
use App\Filament\Resources\Incomes\Pages\CreateIncome;
use App\Models\Expense;
use App\Models\Income;
use App\Models\Property;
use App\Models\User;
use Filament\Forms\Components\DatePicker;
use Livewire\Livewire;

// `DatePicker` est NATIF par défaut : il rend un `<input type="date">` qui IGNORE
// `displayFormat()` et suit la locale du navigateur. Les 16 champs de l'app déclaraient
// `->displayFormat('d/m/Y')` sans aucun effet — la saisie partait en ISO alors que les
// colonnes des tables affichaient `d/m/Y` (issue #6). `AppServiceProvider` applique
// désormais `native(false)` à tous les DatePicker, ce qui rend `displayFormat` effectif.

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->property = Property::create([
        'user_id' => $this->user->id,
        'name' => 'Studio Mougins',
        'address' => '1 rue du Test',
        'city' => 'Lyon',
        'postal_code' => '69003',
        'type' => 'apartment',
        'total_area' => 45,
        'rented_area' => 45,
        'acquisition_date' => '2022-01-01',
        'acquisition_price' => 20000000,
        'land_percentage' => 15,
        'rental_start_date' => '2022-03-01',
        'rental_type' => 'seasonal',
        'is_primary_residence' => false,
    ]);
});

it('renders every date picker in French, not with the browser locale', function () {
    $picker = DatePicker::make('any_date');

    expect($picker->isNative())->toBeFalse()
        ->and($picker->getDisplayFormat())->toBe('d/m/Y');
});

it('still records an expense once the picker is no longer native', function () {
    // `native(false)` bascule `getInternalFormat()` sur `Y-m-d H:i:s` : on vérifie que la
    // date traverse bien le formulaire jusqu'à la colonne `date` du modèle.
    Livewire::actingAs($this->user)
        ->test(CreateExpense::class)
        ->fillForm([
            'property_id' => $this->property->id,
            'expense_date' => '2026-03-17',
            'category' => 'insurance',
            'description' => 'Assurance PNO',
            'amount' => 420.50,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $expense = Expense::latest('id')->first();

    expect($expense)->not->toBeNull()
        ->and($expense->expense_date->format('Y-m-d'))->toBe('2026-03-17');
});

it('still records an income once the picker is no longer native', function () {
    Livewire::actingAs($this->user)
        ->test(CreateIncome::class)
        ->fillForm([
            'property_id' => $this->property->id,
            'income_date' => '2026-07-04',
            'source' => 'airbnb',
            'amount' => 1250,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $income = Income::latest('id')->first();

    expect($income)->not->toBeNull()
        ->and($income->income_date->format('Y-m-d'))->toBe('2026-07-04');
});
