<?php

use App\Filament\Actions\GenerateLeaseRentsAction;
use App\Filament\Resources\Incomes\Pages\EditIncome;
use App\Filament\Resources\Leases\LeaseResource;
use App\Filament\Resources\Leases\Pages\CreateLease;
use App\Filament\Resources\Leases\Pages\EditLease;
use App\Filament\Resources\Leases\Pages\ListLeases;
use App\Models\FiscalYear;
use App\Models\Income;
use App\Models\Lease;
use App\Models\Property;
use App\Models\User;
use App\Services\Ai\Assistant\ConversationRedactor;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;

/**
 * L'écran des baux et son action « Générer les loyers » (issue #24).
 *
 * Comme pour les charges récurrentes, une action de table ne se rend pas sur un simple
 * `get()` : seul un test Livewire qui la monte voit une modale cassée.
 */
function leaseScreenProperty(User $user, string $name = 'Appartement'): Property
{
    return Property::withoutGlobalScopes()->forceCreate([
        'user_id'              => $user->id,
        'name'                 => $name,
        'address'              => '1 rue Test',
        'city'                 => 'Lyon',
        'postal_code'          => '69001',
        'type'                 => 'apartment',
        'total_area'           => 40,
        'rented_area'          => 40,
        'acquisition_date'     => '2020-01-01',
        'acquisition_price'    => 15000000,
        'notary_fees'          => 0,
        'land_percentage'      => 15,
        'rental_start_date'    => '2025-01-01',
        'rental_type'          => Property::RENTAL_LONG_TERM,
        'is_primary_residence' => false,
    ]);
}

function leaseScreenLease(Property $property, array $overrides = []): Lease
{
    return Lease::create([
        'property_id'    => $property->id,
        'tenant_name'    => 'Camille Martin',
        'start_date'     => '2025-01-01',
        'end_date'       => null,
        'rent_amount'    => 65000,
        'charges_amount' => 5000,
        'charges_mode'   => Lease::CHARGES_PROVISIONS,
        'due_day'        => 5,
        ...$overrides,
    ]);
}

beforeEach(function () {
    $this->travelTo('2026-11-16 10:00:00');
    $this->user = User::factory()->create();
    $this->property = leaseScreenProperty($this->user);
    $this->actingAs($this->user);
});

it('saves a lease typed in euros as cents', function () {
    Livewire::test(CreateLease::class)
        ->fillForm([
            'property_id'    => $this->property->id,
            'tenant_name'    => 'Camille Martin',
            'start_date'     => '2026-02-01',
            'rent_amount'    => '650.50',
            'charges_amount' => '50',
            'charges_mode'   => Lease::CHARGES_FLAT,
            'due_day'        => 5,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $lease = Lease::withoutGlobalScopes()->sole();

    expect($lease->rent_amount)->toBe(65050)
        ->and($lease->charges_amount)->toBe(5000)
        ->and($lease->charges_mode)->toBe(Lease::CHARGES_FLAT)
        ->and($lease->end_date)->toBeNull();
});

it('refuses an overlapping lease under the field, not with an error page', function () {
    leaseScreenLease($this->property, ['tenant_name' => 'Alex Durand']);

    Livewire::test(CreateLease::class)
        ->fillForm([
            'property_id'  => $this->property->id,
            'tenant_name'  => 'Camille Martin',
            'start_date'   => '2026-09-01',
            'rent_amount'  => '700',
            'charges_mode' => Lease::CHARGES_PROVISIONS,
            'due_day'      => 1,
        ])
        ->call('create')
        ->assertHasFormErrors(['start_date']);

    expect(Lease::withoutGlobalScopes()->count())->toBe(1);
});

it('refuses an end date before the start date', function () {
    Livewire::test(CreateLease::class)
        ->fillForm([
            'property_id'  => $this->property->id,
            'tenant_name'  => 'Camille Martin',
            'start_date'   => '2026-09-01',
            'end_date'     => '2026-08-31',
            'rent_amount'  => '700',
            'charges_mode' => Lease::CHARGES_PROVISIONS,
            'due_day'      => 1,
        ])
        ->call('create')
        ->assertHasFormErrors(['end_date']);
});

it('refuses a due day outside 1 to 31', function (int $day) {
    Livewire::test(CreateLease::class)
        ->fillForm([
            'property_id'  => $this->property->id,
            'tenant_name'  => 'Camille Martin',
            'start_date'   => '2026-09-01',
            'rent_amount'  => '700',
            'charges_mode' => Lease::CHARGES_PROVISIONS,
            'due_day'      => $day,
        ])
        ->call('create')
        ->assertHasFormErrors(['due_day']);
})->with([0, 32]);

it('lets a lease be edited without seeing itself as an overlap', function () {
    $lease = leaseScreenLease($this->property);

    Livewire::test(EditLease::class, ['record' => $lease->getRouteKey()])
        ->fillForm(['end_date' => '2026-12-31'])
        ->call('save')
        ->assertHasNoFormErrors()
        ->assertRedirect(LeaseResource::getUrl());

    expect($lease->fresh()->end_date->toDateString())->toBe('2026-12-31');
});

it('offers to generate the rents right after the lease is created', function () {
    Livewire::test(CreateLease::class)
        ->fillForm([
            'property_id'  => $this->property->id,
            'tenant_name'  => 'Camille Martin',
            'start_date'   => '2026-01-01',
            'rent_amount'  => '650',
            'charges_mode' => Lease::CHARGES_PROVISIONS,
            'due_day'      => 5,
        ])
        ->call('create')
        ->assertNotified(GenerateLeaseRentsAction::proposal(
            Lease::withoutGlobalScopes()->sole(),
            __('filament-panels::resources/pages/create-record.notifications.created.title'),
        ));
});

it('generates the rents of the chosen year from the list', function () {
    $lease = leaseScreenLease($this->property);

    Livewire::test(ListLeases::class)
        ->mountAction(TestAction::make('generate_rents')->table($lease))
        ->assertSchemaStateSet(['year' => 2026])
        ->setActionData(['year' => 2025])
        ->callMountedAction()
        ->assertHasNoErrors()
        ->assertNotified('12 loyer(s) créé(s)');

    $dates = Income::withoutGlobalScopes()->where('lease_id', $lease->id)->pluck('income_date');

    expect($dates)->toHaveCount(12)
        ->and($dates->every(fn ($date) => $date->year === 2025))->toBeTrue();
});

it('writes nothing when the fiscal year is closed', function () {
    $lease = leaseScreenLease($this->property);

    FiscalYear::forceCreate([
        'user_id' => $this->user->id,
        'year'    => 2026,
        'status'  => FiscalYear::STATUS_CLOSED,
    ]);

    Livewire::test(ListLeases::class)
        ->mountAction(TestAction::make('generate_rents')->table($lease))
        ->setActionData(['year' => 2026])
        ->callMountedAction()
        ->assertNotified('Génération impossible');

    expect(Income::withoutGlobalScopes()->count())->toBe(0);
});

it('shows the lease on a generated rent and keeps its payment date', function () {
    $lease = leaseScreenLease($this->property);
    app(App\Services\LeaseRentService::class)->generate($lease, 2026);
    $rent = Income::withoutGlobalScopes()->where('lease_id', $lease->id)->orderBy('income_date')->first();

    Livewire::test(EditIncome::class, ['record' => $rent->getRouteKey()])
        ->assertSee('Loyer du bail de Camille Martin, commencé le 01/01/2025, dont 50,00')
        ->fillForm(['paid_date' => '2026-01-07'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($rent->fresh()->paid_date->toDateString())->toBe('2026-01-07')
        ->and($rent->fresh()->income_date->toDateString())->toBe('2026-01-05');
});

it('masks the tenant name in a reported AI conversation', function () {
    leaseScreenLease($this->property);

    expect(app(ConversationRedactor::class)->knownValues($this->user))
        ->toHaveKey('Camille Martin', '[locataire]');
});

it('never lists another user\'s lease', function () {
    $foreign = leaseScreenLease(leaseScreenProperty(User::factory()->create(), 'Maison'));
    $mine = leaseScreenLease($this->property);

    Livewire::test(ListLeases::class)
        ->assertCanSeeTableRecords([$mine])
        ->assertCanNotSeeTableRecords([$foreign]);

    $this->get(LeaseResource::getUrl('edit', ['record' => $foreign]))->assertNotFound();
});
