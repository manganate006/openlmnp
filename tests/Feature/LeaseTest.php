<?php

use App\Models\Income;
use App\Models\Lease;
use App\Models\Property;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * Bail d'une location meublée à l'année (issue #24) : le modèle et ses gardes.
 * La génération des loyers a ses propres tests.
 */
function leaseTestProperty(User $user, string $name = 'Appartement'): Property
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

function leaseFor(Property $property, array $overrides = []): Lease
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

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->property = leaseTestProperty($this->user);
});

it('stores a lease in cents and sums rent and charges', function () {
    $lease = leaseFor($this->property);

    expect($lease->fresh()->monthly_total)->toBe(70000)
        ->and($lease->charges_mode)->toBe(Lease::CHARGES_PROVISIONS)
        ->and($this->property->leases()->withoutGlobalScopes()->count())->toBe(1);
});

it('knows whether it is running on a given day, bounds included', function () {
    $lease = leaseFor($this->property, ['start_date' => '2025-01-01', 'end_date' => '2025-12-31']);

    expect($lease->isActiveOn(CarbonImmutable::parse('2024-12-31')))->toBeFalse()
        ->and($lease->isActiveOn(CarbonImmutable::parse('2025-01-01')))->toBeTrue()
        ->and($lease->isActiveOn(CarbonImmutable::parse('2025-12-31')))->toBeTrue()
        ->and($lease->isActiveOn(CarbonImmutable::parse('2026-01-01')))->toBeFalse();
});

it('refuses a new lease while the previous one has no end date', function () {
    leaseFor($this->property);

    leaseFor($this->property, ['tenant_name' => 'Alex Petit', 'start_date' => '2026-03-01']);
})->throws(RuntimeException::class, 'Renseignez la fin du bail précédent');

it('accepts the next lease once the previous one has ended', function () {
    $first = leaseFor($this->property);
    $first->update(['end_date' => '2026-02-28']);

    $next = leaseFor($this->property, ['tenant_name' => 'Alex Petit', 'start_date' => '2026-03-01']);

    expect($next->exists)->toBeTrue()
        ->and(Lease::withoutGlobalScopes()->where('property_id', $this->property->id)->count())->toBe(2);
});

it('treats a shared last and first day as an overlap', function () {
    leaseFor($this->property, ['end_date' => '2026-02-28']);

    leaseFor($this->property, ['tenant_name' => 'Alex Petit', 'start_date' => '2026-02-28']);
})->throws(RuntimeException::class, 'chevauche');

it('refuses a lease slipped in before a running one', function () {
    leaseFor($this->property, ['start_date' => '2025-06-01']);

    leaseFor($this->property, [
        'tenant_name' => 'Alex Petit', 'start_date' => '2025-01-01', 'end_date' => '2025-06-01',
    ]);
})->throws(RuntimeException::class, 'chevauche');

it('lets two properties have leases over the same period', function () {
    $other = leaseTestProperty($this->user, 'Studio');

    leaseFor($this->property);
    $second = leaseFor($other);

    expect($second->exists)->toBeTrue();
});

it('does not see a lease as overlapping itself when it is edited', function () {
    $lease = leaseFor($this->property);

    $lease->update(['rent_amount' => 66000, 'end_date' => '2027-12-31']);

    expect($lease->fresh()->rent_amount)->toBe(66000);
});

it('refuses an inconsistent lease', function (array $overrides, string $message) {
    expect(fn () => leaseFor($this->property, $overrides))
        ->toThrow(RuntimeException::class, $message);
})->with([
    'end before start' => [['end_date' => '2024-12-31'], 'précéder son début'],
    'due day 0' => [['due_day' => 0], 'entre 1 et 31'],
    'due day 32' => [['due_day' => 32], 'entre 1 et 31'],
    'no rent' => [['rent_amount' => 0], 'loyer doit être positif'],
    'negative charges' => [['charges_amount' => -100], 'loyer doit être positif'],
    'unknown charges mode' => [['charges_mode' => 'included'], 'Mode de charges inconnu'],
]);

it('isolates leases between users', function () {
    leaseFor($this->property);
    $intruder = User::factory()->create();

    $this->actingAs($this->user);
    expect(Lease::count())->toBe(1);

    $this->actingAs($intruder);
    expect(Lease::count())->toBe(0)
        ->and(Lease::find(Lease::withoutGlobalScopes()->value('id')))->toBeNull();
});

it('keeps the rents of a deleted lease, without the link', function () {
    $lease = leaseFor($this->property);

    $income = Income::create([
        'property_id' => $this->property->id,
        'lease_id' => $lease->id,
        'income_date' => '2025-01-05',
        'amount' => 70000,
        'charges_amount' => 5000,
        'tva_rate' => 0,
        'source' => Income::SOURCE_DIRECT,
    ]);

    expect($income->lease->is($lease))->toBeTrue();

    $lease->delete();

    $income = Income::withoutGlobalScopes()->find($income->id);

    expect($income)->not->toBeNull()
        ->and($income->lease_id)->toBeNull()
        ->and($income->amount)->toBe(70000)
        ->and($income->charges_amount)->toBe(5000);
});

it('deletes the leases of a deleted property', function () {
    leaseFor($this->property);

    $this->property->delete();

    expect(Lease::withoutGlobalScopes()->count())->toBe(0);
});

it('keeps the payment date as information only', function () {
    $income = Income::create([
        'property_id' => $this->property->id,
        'income_date' => '2025-01-05',
        'paid_date' => '2025-01-09',
        'amount' => 70000,
        'tva_rate' => 0,
        'source' => Income::SOURCE_DIRECT,
    ]);

    expect($income->fresh()->paid_date->format('Y-m-d'))->toBe('2025-01-09')
        ->and($income->fresh()->income_date->format('Y-m-d'))->toBe('2025-01-05');
});
