<?php

use App\Models\Furniture;
use App\Models\Property;
use App\Models\PropertyWork;
use App\Models\User;
use App\Services\DepreciationService;

/**
 * Fin de plan : la part rognée la première année se déduit l'année qui suit le terme.
 *
 * Jusqu'au 2026-10-16, les quatre familles d'actifs arrêtaient le plan à
 * `startYear + duration - 1`. Un meuble mis en service le 1er juillet sur 10 ans
 * n'amortissait que 95 % de sa base (ticket Zammad #30065). La règle vérifiée ici :
 * le cumul de toutes les dotations retombe exactement sur `annual × duration`.
 */
beforeEach(function () {
    $this->user = User::factory()->create();
    $this->service = new DepreciationService();
});

function planEndProperty(User $user, string $rentalStart, int $notaryFees = 0): Property
{
    return Property::forceCreate([
        'user_id' => $user->id,
        'name' => 'Bien fin de plan',
        'address' => '1 rue Test',
        'city' => 'Paris',
        'postal_code' => '75001',
        'type' => 'apartment',
        'total_area' => 100,
        'rented_area' => 100,
        'acquisition_date' => '2015-01-01',
        'acquisition_price' => 25000000,
        'notary_fees' => $notaryFees,
        'market_value' => null,
        'land_percentage' => 20,
        'rental_start_date' => $rentalStart,
        'rental_type' => 'seasonal',
        'is_primary_residence' => false,
    ]);
}

/** Dotations d'une famille, année par année, de $from à $to inclus. */
function planEndSeries(DepreciationService $service, Property $property, string $key, int $from, int $to): array
{
    $series = [];
    for ($y = $from; $y <= $to; $y++) {
        $series[$y] = (int) $service->calculateAnnualDepreciation($property->fresh(), $y)[$key];
    }

    return $series;
}

it('carries the first-year remainder of furniture to the year after the nominal end', function () {
    $property = planEndProperty($this->user, '2020-01-01');

    Furniture::create([
        'property_id' => $property->id,
        'description' => 'Canapé',
        'purchase_date' => '2026-07-01',
        'amount' => 150000, // 1 500 €
        'tva_rate' => 0,
        'duration_years' => 10,
        'is_dedicated' => true,
    ]);

    $series = planEndSeries($this->service, $property, 'furniture', 2025, 2038);

    // 15 000 c par an ; 184 jours sur 365 la première année.
    $firstYear = (int) floor(15000 * 184 / 365);

    expect($series[2025])->toBe(0)
        ->and($series[2026])->toBe($firstYear)
        ->and($series[2035])->toBe(15000)
        ->and($series[2036])->toBe(15000 - $firstYear)
        ->and($series[2037])->toBe(0)
        ->and(array_sum($series))->toBe(150000);
});

it('carries the remainder for works', function () {
    $property = planEndProperty($this->user, '2020-01-01');

    PropertyWork::create([
        'property_id' => $property->id,
        'description' => 'Réfection toiture',
        'work_date' => '2023-03-15',
        'amount' => 1200000, // 12 000 €
        'tva_rate' => 0,
        'duration_years' => 10,
        'is_dedicated' => true,
    ]);

    $series = planEndSeries($this->service, $property, 'works', 2023, 2035);

    expect($series[2033])->toBeGreaterThan(0)
        ->and($series[2034])->toBe(0)
        ->and(array_sum($series))->toBe(1200000);
});

it('carries the remainder for building components', function () {
    $property = planEndProperty($this->user, '2023-07-01');
    $this->service->generateDefaultComponents($property);

    // Le plus long composant dure 50 ans : 2023 → 2073 avec le rattrapage.
    $series = planEndSeries($this->service, $property, 'building', 2023, 2074);

    $expected = (int) $property->fresh()->components
        ->sum(fn ($c) => $c->annual_depreciation * $c->duration_years);

    expect($series[2073])->toBeGreaterThan(0)
        ->and($series[2074])->toBe(0)
        ->and(array_sum($series))->toBe($expected);
});

it('carries the remainder for acquisition fees', function () {
    $property = planEndProperty($this->user, '2023-07-01', notaryFees: 2500000); // 25 000 €

    if (! $property->emitsSeparateAcquisitionFeeLine()) {
        $this->markTestSkipped('Frais incorporés aux composants par défaut sur cette branche.');
    }

    $duration = $property->acquisitionFeesDurationYears();
    $series = planEndSeries($this->service, $property, 'notary', 2023, 2023 + $duration + 1);
    $annual = intdiv(2500000, $duration);

    expect($series[2023 + $duration])->toBeGreaterThan(0)
        ->and($series[2023 + $duration + 1])->toBe(0)
        ->and(array_sum($series))->toBe($annual * $duration);
});

it('adds no catch-up year when the plan starts on 1 January', function () {
    $property = planEndProperty($this->user, '2020-01-01');

    Furniture::create([
        'property_id' => $property->id,
        'description' => 'Lit',
        'purchase_date' => '2026-01-01',
        'amount' => 100000,
        'tva_rate' => 0,
        'duration_years' => 5,
        'is_dedicated' => true,
    ]);

    $series = planEndSeries($this->service, $property, 'furniture', 2026, 2031);

    expect($series[2030])->toBe(20000)
        ->and($series[2031])->toBe(0)
        ->and(array_sum($series))->toBe(100000);
});
