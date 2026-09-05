<?php

use App\Filament\Resources\Expenses\Pages\CreateExpense;
use App\Filament\Resources\Expenses\Pages\EditExpense;
use App\Filament\Resources\Expenses\Pages\ListExpenses;
use App\Models\Expense;
use App\Models\Property;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Notifications\Livewire\Notifications;
use Filament\Notifications\Notification;
use Livewire\Livewire;

/**
 * L'action « Dupliquer » et l'invitation à générer posée après enregistrement.
 *
 * Les deux répondent au même angle mort de l'issue #9 : la génération ne couvre que le
 * mensuel et le trimestriel, et elle ne se découvrait que dans la liste.
 */
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

/**
 * La dernière notification réellement envoyée.
 *
 * `assertNotified()` ne sait comparer qu'un titre ou une notification entière ; ici le
 * titre est celui de Filament et c'est le CORPS qui porte l'invitation. On relit donc la
 * même source qu'elle, le composant `Notifications` alimenté par la session.
 *
 * ⚠️ `mount()` fait un `session()->pull()` : il CONSOMME les notifications. Appeler ce
 * helper deux fois dans le même test rend `null` la seconde fois — ce qui ferait passer
 * pour absent un bouton bel et bien présent.
 */
function lastNotification(): ?Notification
{
    $component = new Notifications;
    $component->mount();

    return $component->notifications->last();
}

/** @return list<string> libellés des boutons d'une notification */
function notificationActionLabels(?Notification $notification): array
{
    return array_map(
        fn ($action) => (string) $action->getLabel(),
        $notification?->getActions() ?? [],
    );
}

function taxExpense(Property $property, string $type = 'yearly'): Expense
{
    return Expense::create([
        'property_id'    => $property->id,
        'expense_date'   => '2026-02-03',
        'amount'         => 32000,
        'tva_rate'       => 0,
        'category'       => 'property_tax',
        'description'    => 'Taxe foncière 2026',
        'is_dedicated'   => false,
        'recurring_type' => $type,
        'notes'          => 'Avis reçu en septembre',
    ]);
}

// --- Dupliquer ---------------------------------------------------------------------

it('duplicates a yearly expense onto the following year by default', function () {
    $expense = taxExpense($this->property);
    $this->actingAs($this->user);

    Livewire::test(ListExpenses::class)
        ->mountAction(TestAction::make('duplicate_expense')->table($expense))
        ->assertActionDataSet(['expense_date' => '2027-02-03'])
        ->callMountedAction()
        ->assertHasNoErrors();

    $copies = Expense::withoutGlobalScopes()->where('property_id', $this->property->id)->get();
    expect($copies)->toHaveCount(2);

    $copy = $copies->first(fn (Expense $e) => $e->expense_date->toDateString() === '2027-02-03');
    expect($copy->description)->toBe('Taxe foncière 2026');
    expect($copy->amount)->toBe(32000);
    expect($copy->category)->toBe('property_tax');
    expect($copy->is_dedicated)->toBeFalse();
    expect($copy->notes)->toBe('Avis reçu en septembre');
});

it('lets the amount be corrected on the copy, in euros', function () {
    $expense = taxExpense($this->property);
    $this->actingAs($this->user);

    Livewire::test(ListExpenses::class)
        ->mountAction(TestAction::make('duplicate_expense')->table($expense))
        // Le champ est en euros là où la base est en centimes : sans conversion, 341 €
        // deviendrait 3,41 €.
        ->setActionData(['expense_date' => '2027-02-03', 'amount' => '341.00'])
        ->callMountedAction()
        ->assertHasNoErrors();

    $copy = Expense::withoutGlobalScopes()
        ->where('property_id', $this->property->id)
        ->whereDate('expense_date', '2027-02-03')
        ->first();

    expect($copy->amount)->toBe(34100);
});

it('offers duplication whatever the recurrence, unlike generation', function (string $type) {
    $expense = taxExpense($this->property, $type);
    $this->actingAs($this->user);

    Livewire::test(ListExpenses::class)
        ->assertActionVisible(TestAction::make('duplicate_expense')->table($expense));
})->with(['once', 'yearly', 'monthly', 'quarterly']);

it('leaves the documents behind', function () {
    $expense = taxExpense($this->property);
    $expense->documents()->create([
        'label'     => 'Avis d\'imposition',
        'file_path' => 'documents/avis-2026.pdf',
    ]);
    $this->actingAs($this->user);

    Livewire::test(ListExpenses::class)
        ->mountAction(TestAction::make('duplicate_expense')->table($expense))
        ->callMountedAction();

    $copy = Expense::withoutGlobalScopes()
        ->where('property_id', $this->property->id)
        ->whereDate('expense_date', '2027-02-03')
        ->first();

    // Une facture appartient à une échéance et à une seule : la recopier ferait croire
    // que la copie est justifiée.
    expect($copy->documents()->count())->toBe(0);
    expect($expense->documents()->count())->toBe(1);
});

// --- L'invitation posée après enregistrement -----------------------------------------

it('offers to generate right after creating a monthly expense', function () {
    $this->actingAs($this->user);

    Livewire::test(CreateExpense::class)
        ->fillForm([
            'property_id'    => $this->property->id,
            'expense_date'   => '2026-01-15',
            'amount'         => '85.00',
            'tva_rate'       => 0,
            'category'       => 'insurance',
            'description'    => 'Assurance habitation',
            'recurring_type' => 'monthly',
        ])
        ->call('create')
        ->assertHasNoErrors();

    $notification = lastNotification();
    expect((string) $notification?->getBody())->toContain('11 échéance(s) restent à saisir pour 2026');
    expect(notificationActionLabels($notification))->toContain('Générer les échéances');
});

it('says nothing extra for a recurrence that generates nothing', function (string $type) {
    $this->actingAs($this->user);

    Livewire::test(CreateExpense::class)
        ->fillForm([
            'property_id'    => $this->property->id,
            'expense_date'   => '2026-01-15',
            'amount'         => '85.00',
            'tva_rate'       => 0,
            'category'       => 'insurance',
            'description'    => 'Assurance habitation',
            'recurring_type' => $type,
        ])
        ->call('create')
        ->assertHasNoErrors();

    $notification = lastNotification();
    expect((string) $notification?->getBody())->not->toContain('restent à saisir');
    expect(notificationActionLabels($notification))->not->toContain('Générer les échéances');
})->with(['once', 'yearly']);

it('offers to generate when an expense becomes monthly after the fact', function () {
    $expense = Expense::create([
        'property_id'    => $this->property->id,
        'expense_date'   => '2026-01-15',
        'amount'         => 8500,
        'tva_rate'       => 0,
        'category'       => 'insurance',
        'description'    => 'Assurance habitation',
        'is_dedicated'   => false,
        'recurring_type' => 'once',
    ]);
    $this->actingAs($this->user);

    Livewire::test(EditExpense::class, ['record' => $expense->getKey()])
        ->fillForm(['recurring_type' => 'monthly'])
        ->call('save')
        ->assertHasNoErrors();

    $notification = lastNotification();
    expect((string) $notification?->getBody())->toContain('11 échéance(s) restent à saisir pour 2026');
    expect(notificationActionLabels($notification))->toContain('Générer les échéances');
});

it('stops offering once the occurrences exist', function () {
    $expense = Expense::create([
        'property_id'    => $this->property->id,
        'expense_date'   => '2026-01-15',
        'amount'         => 8500,
        'tva_rate'       => 0,
        'category'       => 'insurance',
        'description'    => 'Assurance habitation',
        'is_dedicated'   => false,
        'recurring_type' => 'monthly',
    ]);
    app(App\Services\RecurringExpenseService::class)
        ->generate($expense, Carbon\CarbonImmutable::parse('2026-12-31'));

    $this->actingAs($this->user);

    Livewire::test(EditExpense::class, ['record' => $expense->getKey()])
        ->fillForm(['description' => 'Assurance PNO'])
        ->call('save')
        ->assertHasNoErrors();

    $notification = lastNotification();
    expect((string) $notification?->getBody())->not->toContain('restent à saisir');
    expect(notificationActionLabels($notification))->not->toContain('Générer les échéances');
});
