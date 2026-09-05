<?php

use App\Models\Expense;
use App\Models\FiscalYear;
use App\Models\Property;
use App\Models\User;
use App\Services\RecurringExpenseService;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->service = app(RecurringExpenseService::class);
});

function recurringProperty(int $userId): Property
{
    return Property::forceCreate([
        'user_id'              => $userId,
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
}

function recurringExpense(Property $property, string $date, string $type, int $amount = 8500): Expense
{
    return Expense::create([
        'property_id'    => $property->id,
        'expense_date'   => $date,
        'amount'         => $amount,
        'tva_rate'       => 0,
        'category'       => 'insurance',
        'description'    => 'Assurance habitation',
        'is_dedicated'   => false,
        'recurring_type' => $type,
    ]);
}

// --- Le calendrier des échéances -------------------------------------------------------

it('generates eleven monthly dates until the end of the civil year', function () {
    $dates = $this->service->occurrenceDates(
        CarbonImmutable::parse('2026-01-15'),
        'monthly',
        CarbonImmutable::parse('2026-12-31'),
    );

    expect($dates)->toHaveCount(11);
    expect($dates[0]->toDateString())->toBe('2026-02-15');
    expect(end($dates)->toDateString())->toBe('2026-12-15');
});

it('anchors monthly dates on the source day without overflowing short months', function () {
    // Piège Carbon : 31/01 + 1 mois déborde sur le 03/03, et un pas à pas depuis la date
    // clampée dériverait (28/02 → 28/03). Chaque date se calcule depuis l'origine.
    $dates = $this->service->occurrenceDates(
        CarbonImmutable::parse('2026-01-31'),
        'monthly',
        CarbonImmutable::parse('2026-12-31'),
    );

    expect(array_map(fn ($d) => $d->toDateString(), $dates))->toBe([
        '2026-02-28',
        '2026-03-31',
        '2026-04-30',
        '2026-05-31',
        '2026-06-30',
        '2026-07-31',
        '2026-08-31',
        '2026-09-30',
        '2026-10-31',
        '2026-11-30',
        '2026-12-31',
    ]);
});

it('steps quarterly dates three months apart', function () {
    $dates = $this->service->occurrenceDates(
        CarbonImmutable::parse('2026-01-15'),
        'quarterly',
        CarbonImmutable::parse('2026-12-31'),
    );

    expect(array_map(fn ($d) => $d->toDateString(), $dates))
        ->toBe(['2026-04-15', '2026-07-15', '2026-10-15']);
});

it('generates nothing for yearly and once', function (string $type) {
    $dates = $this->service->occurrenceDates(
        CarbonImmutable::parse('2026-01-15'),
        $type,
        CarbonImmutable::parse('2026-12-31'),
    );

    expect($dates)->toBe([]);
    expect(RecurringExpenseService::isGeneratable($type))->toBeFalse();
})->with(['yearly', 'once']);

it('ignores the time of day on both bounds', function () {
    // Le `DatePicker` non natif de Filament renvoie l'heure du CLIC collée à la date
    // (`2026-12-31 12:25:59`) alors qu'il n'affiche qu'un jour. Le calendrier doit
    // raisonner en journées : sinon une échéance tombant le dernier jour est écartée
    // pour quelques heures d'écart, selon le moment où l'utilisateur a cliqué.
    $withTime = $this->service->occurrenceDates(
        CarbonImmutable::parse('2026-01-15 14:00:00'),
        'monthly',
        CarbonImmutable::parse('2026-06-15 09:30:00'),
    );
    $cleanDays = $this->service->occurrenceDates(
        CarbonImmutable::parse('2026-01-15'),
        'monthly',
        CarbonImmutable::parse('2026-06-15'),
    );

    expect(array_map(fn ($d) => $d->toDateString(), $withTime))
        ->toBe(array_map(fn ($d) => $d->toDateString(), $cleanDays));
    // La dernière échéance tombe le jour de la borne : elle doit être retenue, même
    // quand l'heure de départ (14 h) est postérieure à celle de la borne (9 h 30).
    expect(end($withTime)->toDateString())->toBe('2026-06-15');
});

it('stops at the requested end date', function () {
    $dates = $this->service->occurrenceDates(
        CarbonImmutable::parse('2026-01-15'),
        'monthly',
        CarbonImmutable::parse('2026-06-30'),
    );

    expect($dates)->toHaveCount(5);
    expect(end($dates)->toDateString())->toBe('2026-06-15');
});

// --- La génération ---------------------------------------------------------------------

it('materialises the missing occurrences as real expenses', function () {
    $property = recurringProperty($this->user->id);
    $expense = recurringExpense($property, '2026-01-15', 'monthly');

    $result = $this->service->generate($expense, CarbonImmutable::parse('2026-12-31'));

    expect($result)->toBe(['created' => 11, 'skipped' => 0]);

    $all = Expense::withoutGlobalScopes()->where('property_id', $property->id)->get();
    expect($all)->toHaveCount(12);
    expect($all->sum('amount'))->toBe(8500 * 12);

    // ⚠️ `expense_date` est hydratée en Carbon (cast `date`) : comparer à une chaîne échoue.
    $generated = $all->first(fn (Expense $e) => $e->expense_date->toDateString() === '2026-03-15');
    expect($generated->category)->toBe('insurance');
    expect($generated->description)->toBe('Assurance habitation');
    expect($generated->is_dedicated)->toBeFalse();
    expect($generated->recurring_type)->toBe('monthly');
});

it('lets the model recompute HT and TVA rather than copying them', function () {
    $property = recurringProperty($this->user->id);
    // 120,00 € TTC à 20 % → 100,00 € HT et 20,00 € de TVA.
    $expense = Expense::create([
        'property_id'    => $property->id,
        'expense_date'   => '2026-01-15',
        'amount'         => 12000,
        'tva_rate'       => 2000,
        'category'       => 'energy',
        'description'    => 'Électricité',
        'is_dedicated'   => true,
        'recurring_type' => 'monthly',
    ]);

    $this->service->generate($expense, CarbonImmutable::parse('2026-03-31'));

    $generated = Expense::withoutGlobalScopes()
        ->where('property_id', $property->id)
        ->whereDate('expense_date', '2026-02-15')
        ->first();

    expect($generated->amount_ht)->toBe(10000);
    expect($generated->amount_tva)->toBe(2000);
});

it('never creates a second occurrence on a date already taken', function () {
    $property = recurringProperty($this->user->id);
    $expense = recurringExpense($property, '2026-01-15', 'monthly');

    $this->service->generate($expense, CarbonImmutable::parse('2026-12-31'));
    $second = $this->service->generate($expense, CarbonImmutable::parse('2026-12-31'));

    expect($second)->toBe(['created' => 0, 'skipped' => 11]);
    expect(Expense::withoutGlobalScopes()->where('property_id', $property->id)->count())->toBe(12);
});

it('skips an occurrence whose amount was corrected by hand', function () {
    // La clé d'unicité est (bien, catégorie, date), sans le montant : une échéance
    // rectifiée ne doit pas être recréée à côté de sa version corrigée.
    $property = recurringProperty($this->user->id);
    $expense = recurringExpense($property, '2026-01-15', 'monthly');
    recurringExpense($property, '2026-02-15', 'monthly', amount: 9100);

    $result = $this->service->generate($expense, CarbonImmutable::parse('2026-12-31'));

    expect($result)->toBe(['created' => 10, 'skipped' => 1]);
    expect(
        Expense::withoutGlobalScopes()->whereDate('expense_date', '2026-02-15')->sum('amount')
    )->toBe(9100);
});

it('reports what it would create without writing anything', function () {
    $property = recurringProperty($this->user->id);
    $expense = recurringExpense($property, '2026-01-15', 'monthly');

    $plan = $this->service->plan($expense, CarbonImmutable::parse('2026-12-31'));

    expect($plan['to_create'])->toBe(11);
    expect($plan['existing'])->toBe(0);
    expect($plan['total_cents'])->toBe(8500 * 11);
    expect(Expense::withoutGlobalScopes()->where('property_id', $property->id)->count())->toBe(1);
});

// --- Les refus -------------------------------------------------------------------------

it('refuses to generate from a yearly expense', function () {
    $property = recurringProperty($this->user->id);
    $expense = recurringExpense($property, '2026-01-15', 'yearly');

    expect(fn () => $this->service->generate($expense, CarbonImmutable::parse('2026-12-31')))
        ->toThrow(RuntimeException::class, 'ne produit pas d\'échéance');

    expect(Expense::withoutGlobalScopes()->where('property_id', $property->id)->count())->toBe(1);
});

it('refuses to spill over into the next civil year', function () {
    $property = recurringProperty($this->user->id);
    $expense = recurringExpense($property, '2026-01-15', 'monthly');

    expect(fn () => $this->service->generate($expense, CarbonImmutable::parse('2027-06-30')))
        ->toThrow(RuntimeException::class, 'année civile');

    expect(Expense::withoutGlobalScopes()->where('property_id', $property->id)->count())->toBe(1);
});

it('refuses to write into a closed fiscal year', function () {
    $property = recurringProperty($this->user->id);
    $expense = recurringExpense($property, '2026-01-15', 'monthly');

    FiscalYear::forceCreate([
        'user_id' => $this->user->id,
        'year'    => 2026,
        'status'  => FiscalYear::STATUS_CLOSED,
    ]);

    expect(fn () => $this->service->generate($expense, CarbonImmutable::parse('2026-12-31')))
        ->toThrow(RuntimeException::class, 'clôturé');

    expect(Expense::withoutGlobalScopes()->where('property_id', $property->id)->count())->toBe(1);
});

it('still generates when another user has closed the same year', function () {
    $other = User::factory()->create();
    FiscalYear::forceCreate([
        'user_id' => $other->id,
        'year'    => 2026,
        'status'  => FiscalYear::STATUS_CLOSED,
    ]);

    $property = recurringProperty($this->user->id);
    $expense = recurringExpense($property, '2026-01-15', 'quarterly');

    expect($this->service->generate($expense, CarbonImmutable::parse('2026-12-31')))
        ->toBe(['created' => 3, 'skipped' => 0]);
});

it('bounds the default generation to the end of the expense year', function () {
    $property = recurringProperty($this->user->id);
    $expense = recurringExpense($property, '2025-03-10', 'monthly');

    expect($this->service->defaultUntil($expense)->toDateString())->toBe('2025-12-31');
    expect($this->service->firstOccurrence($expense)->toDateString())->toBe('2025-04-10');
});
