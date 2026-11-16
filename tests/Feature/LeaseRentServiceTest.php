<?php

use App\Models\FiscalYear;
use App\Models\Income;
use App\Models\Lease;
use App\Models\Property;
use App\Models\User;
use App\Services\LeaseRentService;
use Carbon\CarbonImmutable;

/**
 * Génération des loyers d'un bail sur une année civile (issue #24).
 */
function rentProperty(User $user, string $name = 'Appartement'): Property
{
    return Property::withoutGlobalScopes()->forceCreate([
        'user_id' => $user->id,
        'name' => $name,
        'address' => '1 rue Test',
        'city' => 'Lyon',
        'postal_code' => '69001',
        'type' => 'apartment',
        'total_area' => 40,
        'rented_area' => 40,
        'acquisition_date' => '2020-01-01',
        'acquisition_price' => 15000000,
        'notary_fees' => 1100000,
        'land_percentage' => 15,
        'rental_start_date' => '2024-01-01',
        'rental_type' => Property::RENTAL_LONG_TERM,
        'is_primary_residence' => false,
    ]);
}

function rentLease(Property $property, array $overrides = []): Lease
{
    return Lease::create([
        'property_id' => $property->id,
        'tenant_name' => 'Camille Martin',
        'start_date' => '2025-01-01',
        'end_date' => null,
        'rent_amount' => 65000,
        'charges_amount' => 5000,
        'charges_mode' => Lease::CHARGES_PROVISIONS,
        'due_day' => 5,
        ...$overrides,
    ]);
}

/** @return list<string> */
function rentDates(Lease $lease): array
{
    return Income::withoutGlobalScopes()
        ->where('lease_id', $lease->id)
        ->orderBy('income_date')
        ->pluck('income_date')
        ->map(fn ($date) => CarbonImmutable::parse($date)->toDateString())
        ->all();
}

beforeEach(function () {
    CarbonImmutable::setTestNow('2026-11-16 10:00:00');
    $this->user = User::factory()->create();
    $this->property = rentProperty($this->user);
    $this->service = app(LeaseRentService::class);
});

afterEach(fn () => CarbonImmutable::setTestNow());

// --- Le calendrier --------------------------------------------------------------------

it('generates twelve rents on the due day of a full year', function () {
    $lease = rentLease($this->property);

    expect($this->service->generate($lease, 2026))->toBe(['created' => 12, 'skipped' => 0]);

    $dates = rentDates($lease);

    expect($dates)->toHaveCount(12)
        ->and($dates[0])->toBe('2026-01-05')
        ->and($dates[11])->toBe('2026-12-05');
});

it('moves a due day beyond the end of the month to its last day', function (int $dueDay, int $year, string $february) {
    $lease = rentLease($this->property, ['start_date' => '2024-01-01', 'due_day' => $dueDay]);

    expect($this->service->dueDate($lease, $year, 2)->toDateString())->toBe($february)
        ->and($this->service->dueDate($lease, $year, 3)->day)->toBe($dueDay);
})->with([
    'the 31st in a leap year' => [31, 2024, '2024-02-29'],
    'the 31st in a common year' => [31, 2025, '2025-02-28'],
    'the 30th in a leap year' => [30, 2024, '2024-02-29'],
    'the 29th in a common year' => [29, 2025, '2025-02-28'],
    'the 29th in a leap year' => [29, 2024, '2024-02-29'],
]);

it('dates every rent of a due day of 31 inside its own month', function () {
    $lease = rentLease($this->property, ['due_day' => 31]);

    $this->service->generate($lease, 2025);

    expect(rentDates($lease))->toBe([
        '2025-01-31', '2025-02-28', '2025-03-31', '2025-04-30', '2025-05-31', '2025-06-30',
        '2025-07-31', '2025-08-31', '2025-09-30', '2025-10-31', '2025-11-30', '2025-12-31',
    ]);
});

// --- Les mois partiels ----------------------------------------------------------------

it('leaves the month of entry out and reports it', function () {
    $lease = rentLease($this->property, ['start_date' => '2026-03-15']);

    $plan = $this->service->plan($lease, 2026);

    expect($plan['to_create'])->toBe(9)
        ->and($plan['rents'][0]['date']->toDateString())->toBe('2026-04-05')
        ->and($plan['partial'])->toHaveCount(1)
        ->and($plan['partial'][0]['from']->toDateString())->toBe('2026-03-15')
        ->and($plan['partial'][0]['to']->toDateString())->toBe('2026-03-31');
});

it('leaves the month of departure out, even when its due day falls inside the lease', function () {
    // Échéance le 5, sortie le 20 : le 05/09 tombe pendant le bail, mais le loyer de
    // septembre est partiel. C'est le mois qui décide, pas la date d'échéance.
    $lease = rentLease($this->property, ['start_date' => '2026-01-01', 'end_date' => '2026-09-20']);

    $plan = $this->service->plan($lease, 2026);

    expect($plan['to_create'])->toBe(8)
        ->and(end($plan['rents'])['date']->toDateString())->toBe('2026-08-05')
        ->and($plan['partial'][0]['from']->toDateString())->toBe('2026-09-01')
        ->and($plan['partial'][0]['to']->toDateString())->toBe('2026-09-20');
});

it('keeps a departure on the last day of the month as a full month', function () {
    $lease = rentLease($this->property, ['start_date' => '2026-01-01', 'end_date' => '2026-09-30']);

    $plan = $this->service->plan($lease, 2026);

    expect($plan['to_create'])->toBe(9)
        ->and($plan['partial'])->toBe([]);
});

it('reports a lease shorter than a month as a single partial month', function () {
    $lease = rentLease($this->property, ['start_date' => '2026-06-10', 'end_date' => '2026-06-25']);

    $plan = $this->service->plan($lease, 2026);

    expect($plan['to_create'])->toBe(0)
        ->and($plan['partial'])->toHaveCount(1)
        ->and($plan['partial'][0]['from']->toDateString())->toBe('2026-06-10')
        ->and($plan['partial'][0]['to']->toDateString())->toBe('2026-06-25');
});

// --- Ce qui est déjà là ---------------------------------------------------------------

it('creates nothing on a second run', function () {
    $lease = rentLease($this->property);
    $this->service->generate($lease, 2026);

    expect($this->service->generate($lease, 2026))->toBe(['created' => 0, 'skipped' => 12])
        ->and(Income::withoutGlobalScopes()->count())->toBe(12);
});

it('creates no second rent after the due day was changed', function () {
    $lease = rentLease($this->property);
    $this->service->generate($lease, 2026);

    $lease->update(['due_day' => 10]);

    expect($this->service->generate($lease->fresh(), 2026))->toBe(['created' => 0, 'skipped' => 12]);
});

it('keeps the rents already corrected by hand', function () {
    $lease = rentLease($this->property);
    $this->service->generate($lease, 2026);

    Income::withoutGlobalScopes()->where('lease_id', $lease->id)->orderBy('income_date')->first()
        ->update(['amount' => 68000]);

    $this->service->generate($lease, 2026);

    expect(Income::withoutGlobalScopes()->where('amount', 68000)->count())->toBe(1)
        ->and(Income::withoutGlobalScopes()->count())->toBe(12);
});

it('takes a direct income entered by hand on the due date for the rent', function () {
    Income::create([
        'property_id' => $this->property->id,
        'income_date' => '2026-03-05',
        'amount' => 70000,
        'tva_rate' => 0,
        'source' => Income::SOURCE_DIRECT,
    ]);

    $lease = rentLease($this->property);

    expect($this->service->generate($lease, 2026))->toBe(['created' => 11, 'skipped' => 1]);
});

it('does not take an income from a platform for the rent', function () {
    Income::create([
        'property_id' => $this->property->id,
        'income_date' => '2026-03-05',
        'amount' => 70000,
        'tva_rate' => 0,
        'source' => Income::SOURCE_AIRBNB,
    ]);

    $lease = rentLease($this->property);

    expect($this->service->generate($lease, 2026))->toBe(['created' => 12, 'skipped' => 0]);
});

it('ignores the rents of another property', function () {
    $other = rentProperty($this->user, 'Studio');
    $this->service->generate(rentLease($other), 2026);

    $lease = rentLease($this->property);

    expect($this->service->generate($lease, 2026))->toBe(['created' => 12, 'skipped' => 0]);
});

// --- Ce que porte un loyer ------------------------------------------------------------

it('writes the rent as a direct income, charges included and kept apart', function () {
    $lease = rentLease($this->property);
    $this->service->generate($lease, 2026);

    $rent = Income::withoutGlobalScopes()->where('lease_id', $lease->id)->first();

    expect($rent->amount)->toBe(70000)
        ->and((int) $rent->charges_amount)->toBe(5000)
        ->and($rent->source)->toBe(Income::SOURCE_DIRECT)
        ->and($rent->guest_name)->toBe('Camille Martin')
        ->and((int) $rent->tva_rate)->toBe(0)
        ->and((int) $rent->platform_fee)->toBe(0)
        ->and($rent->paid_date)->toBeNull();
});

it('takes the TVA rate of the property, as any rental income', function () {
    $liable = rentProperty($this->user, 'Résidence services');
    $liable->forceFill(['tva_regime' => Property::TVA_LIABLE])->save();

    $lease = rentLease($liable);
    $this->service->generate($lease, 2026);

    $rent = Income::withoutGlobalScopes()->where('lease_id', $lease->id)->first();

    expect((int) $rent->tva_rate)->toBe($liable->defaultIncomeTvaRate())
        ->and((int) $rent->tva_collected)->toBeGreaterThan(0);
});

it('previews the total of the rents to create', function () {
    $lease = rentLease($this->property);

    $plan = $this->service->plan($lease, 2026);

    expect($plan['total_cents'])->toBe(840000)
        ->and($plan['existing'])->toBe(0);
});

// --- Les bornes -----------------------------------------------------------------------

it('stops a running lease at the current year', function () {
    $lease = rentLease($this->property, ['start_date' => '2025-01-01']);

    expect($this->service->years($lease))->toBe([2025, 2026])
        ->and($this->service->defaultYear($lease))->toBe(2026);
});

it('keeps the first year of a lease signed for next year', function () {
    $lease = rentLease($this->property, ['start_date' => '2027-01-01']);

    expect($this->service->years($lease))->toBe([2027])
        ->and($this->service->defaultYear($lease))->toBe(2027);
});

it('stops an ended lease at its last year', function () {
    $lease = rentLease($this->property, ['start_date' => '2023-01-01', 'end_date' => '2024-06-30']);

    expect($this->service->years($lease))->toBe([2023, 2024])
        ->and($this->service->defaultYear($lease))->toBe(2024);
});

it('refuses a year the lease does not cover', function () {
    $lease = rentLease($this->property, ['start_date' => '2025-01-01']);

    expect(fn () => $this->service->generate($lease, 2027))
        ->toThrow(RuntimeException::class, 'ne couvre pas 2027');

    expect(Income::withoutGlobalScopes()->count())->toBe(0);
});

it('refuses to write into a closed fiscal year', function () {
    FiscalYear::forceCreate([
        'user_id' => $this->user->id,
        'year' => 2026,
        'status' => FiscalYear::STATUS_CLOSED,
    ]);

    $lease = rentLease($this->property);

    expect(fn () => $this->service->generate($lease, 2026))
        ->toThrow(RuntimeException::class, 'clôturé');

    expect(Income::withoutGlobalScopes()->count())->toBe(0);
});

it('still generates when another user has closed the same year', function () {
    FiscalYear::forceCreate([
        'user_id' => User::factory()->create()->id,
        'year' => 2026,
        'status' => FiscalYear::STATUS_CLOSED,
    ]);

    $lease = rentLease($this->property);

    expect($this->service->generate($lease, 2026)['created'])->toBe(12);
});

it('recalculates the draft fiscal years after generating', function () {
    $draft = FiscalYear::forceCreate([
        'user_id' => $this->user->id,
        'year' => 2026,
        'status' => FiscalYear::STATUS_DRAFT,
    ]);

    $this->service->generate(rentLease($this->property), 2026);

    expect((int) $draft->fresh()->total_income)->toBe(840000);
});
