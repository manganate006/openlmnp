<?php

use App\Filament\Resources\Expenses\Pages\ListExpenses;
use App\Models\Expense;
use App\Models\FiscalYear;
use App\Models\Property;
use App\Models\User;
use App\Services\FiscalYearService;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;

/**
 * L'action « Générer les échéances » de la liste des charges (issue #9).
 *
 * Une action de table ne se rend pas sur un simple `get('/expenses')` : sans un test
 * Livewire qui la monte réellement, une modale cassée passerait inaperçue.
 *
 * ⚠️ La date est posée HORODATÉE, comme le navigateur l'envoie réellement : le
 * `DatePicker` non natif de Filament colle l'heure du clic à l'état (`2026-12-31
 * 12:25:59`) alors qu'il n'affiche qu'une date. Avec une date propre, ces tests
 * passaient au vert pendant que l'action refusait sa propre valeur par défaut à
 * l'écran, sur son `maxDate` fixé à minuit.
 */
const BROWSER_STATE = '2026-12-31 12:25:59';

beforeEach(function () {
    $this->user = User::factory()->create();

    $this->property = Property::forceCreate([
        'user_id'              => $this->user->id,
        'name'                 => 'Studio',
        'address'              => '1 rue Test',
        'city'                 => 'Paris',
        'postal_code'          => '75001',
        'type'                 => 'apartment',
        'total_area'           => 100,
        'rented_area'          => 100,
        'acquisition_date'     => '2020-01-01',
        'acquisition_price'    => 10000000,
        'notary_fees'          => 0,
        'market_value'         => null,
        'land_percentage'      => 0,
        'rental_start_date'    => '2023-01-01',
        'rental_type'          => 'seasonal',
        'is_primary_residence' => false,
    ]);
});

function expenseFor(Property $property, string $type): Expense
{
    return Expense::create([
        'property_id'    => $property->id,
        'expense_date'   => '2026-01-15',
        'amount'         => 8500,
        'tva_rate'       => 0,
        'category'       => 'insurance',
        'description'    => 'Assurance habitation',
        'is_dedicated'   => true,
        'recurring_type' => $type,
    ]);
}

it('creates the missing occurrences of the year', function () {
    $expense = expenseFor($this->property, 'monthly');
    $this->actingAs($this->user);

    Livewire::test(ListExpenses::class)
        ->mountAction(TestAction::make('generate_occurrences')->table($expense))
        ->setActionData(['until' => BROWSER_STATE])
        ->callMountedAction()
        ->assertHasNoErrors();

    expect(Expense::withoutGlobalScopes()->where('property_id', $this->property->id)->count())->toBe(12);
});

it('refreshes the draft fiscal year so the totals follow the new lines', function () {
    $expense = expenseFor($this->property, 'monthly');

    // Exercice calculé AVANT génération : il ne connaît que la charge d'origine.
    $fiscalYear = app(FiscalYearService::class)->getOrCreate($this->user, 2026);
    expect($fiscalYear->total_expenses)->toBe(8500);

    $this->actingAs($this->user);

    Livewire::test(ListExpenses::class)
        ->mountAction(TestAction::make('generate_occurrences')->table($expense))
        ->setActionData(['until' => BROWSER_STATE])
        ->callMountedAction();

    expect($fiscalYear->fresh()->total_expenses)->toBe(8500 * 12);
});

it('offers the action only for recurrences that produce occurrences', function (string $type, bool $expected) {
    $expense = expenseFor($this->property, $type);
    $this->actingAs($this->user);

    $component = Livewire::test(ListExpenses::class);
    $action = TestAction::make('generate_occurrences')->table($expense);

    $expected
        ? $component->assertActionVisible($action)
        : $component->assertActionHidden($action);
})->with([
    ['monthly', true],
    ['quarterly', true],
    ['yearly', false],
    ['once', false],
]);

it('writes nothing when the fiscal year is closed', function () {
    $expense = expenseFor($this->property, 'monthly');

    FiscalYear::forceCreate([
        'user_id' => $this->user->id,
        'year'    => 2026,
        'status'  => FiscalYear::STATUS_CLOSED,
    ]);

    $this->actingAs($this->user);

    Livewire::test(ListExpenses::class)
        ->mountAction(TestAction::make('generate_occurrences')->table($expense))
        ->setActionData(['until' => BROWSER_STATE])
        ->callMountedAction();

    expect(Expense::withoutGlobalScopes()->where('property_id', $this->property->id)->count())->toBe(1);
});

it('never reaches another user\'s expense', function () {
    $other = User::factory()->create();
    $otherProperty = Property::forceCreate([
        'user_id'              => $other->id,
        'name'                 => 'Maison',
        'address'              => '2 rue Test',
        'city'                 => 'Lyon',
        'postal_code'          => '69001',
        'type'                 => 'house',
        'total_area'           => 100,
        'rented_area'          => 100,
        'acquisition_date'     => '2020-01-01',
        'acquisition_price'    => 10000000,
        'notary_fees'          => 0,
        'market_value'         => null,
        'land_percentage'      => 0,
        'rental_start_date'    => '2023-01-01',
        'rental_type'          => 'seasonal',
        'is_primary_residence' => false,
    ]);
    $foreign = expenseFor($otherProperty, 'monthly');
    $mine = expenseFor($this->property, 'monthly');

    $this->actingAs($this->user);

    // La charge d'un tiers n'est même pas listée : l'action n'a aucun moyen d'être
    // montée dessus, et la génération reste cantonnée au bien de l'utilisateur.
    Livewire::test(ListExpenses::class)
        ->assertCanSeeTableRecords([$mine])
        ->assertCanNotSeeTableRecords([$foreign])
        ->mountAction(TestAction::make('generate_occurrences')->table($mine))
        ->setActionData(['until' => BROWSER_STATE])
        ->callMountedAction();

    expect(Expense::withoutGlobalScopes()->where('property_id', $otherProperty->id)->count())->toBe(1);
    expect(Expense::withoutGlobalScopes()->where('property_id', $this->property->id)->count())->toBe(12);
});
