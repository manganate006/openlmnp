<?php

use App\Models\Furniture;
use App\Models\Property;
use App\Models\PropertyWork;
use App\Models\User;
use App\Services\DepreciationService;

/**
 * Prorata temporis de la première année.
 *
 * Jusqu'au 2026-09-03, les quatre familles d'actifs calculaient les jours restants
 * par `Carbon::diffInDays(...) + 1`. `diffInDays()` rend un flottant qui inclut déjà
 * la journée en cours (364,999… du 1er janvier au 31 décembre 23 h 59) : le « + 1 »
 * la comptait deux fois et majorait toute première année de 1/365, soit +0,27 %.
 *
 * Ces tests ancrent le comportement correct sur les deux bornes de l'année, où
 * l'erreur d'un jour est la plus lisible, et sur une année bissextile.
 */
beforeEach(function () {
    $this->user = User::factory()->create();
    $this->service = new DepreciationService();
});

/** Bien à base amortissable ronde : 200 000 € bâti, quote-part 1. */
function prorataProperty(User $user, string $rentalStart, int $notaryFees = 0): Property
{
    return Property::forceCreate([
        'user_id' => $user->id,
        'name' => 'Bien prorata',
        'address' => '1 rue Test',
        'city' => 'Paris',
        'postal_code' => '75001',
        'type' => 'apartment',
        'total_area' => 100,
        'rented_area' => 100,
        'acquisition_date' => '2015-01-01',
        'acquisition_price' => 25000000, // 250 000 €
        'notary_fees' => $notaryFees,
        'market_value' => null,
        'land_percentage' => 20, // base amortissable = 200 000 €
        'rental_start_date' => $rentalStart,
        'rental_type' => 'seasonal',
        'is_primary_residence' => false,
    ]);
}

it('applies no reduction at all when the rental starts on 1 January', function () {
    $property = prorataProperty($this->user, '2023-01-01');
    $this->service->generateDefaultComponents($property);

    $fullYear = $this->service->calculateAnnualDepreciation($property->fresh(), 2024);
    $firstYear = $this->service->calculateAnnualDepreciation($property->fresh(), 2023);

    // Une location qui couvre l'année entière ne doit rien proratiser.
    expect($firstYear['building'])->toBe($fullYear['building']);
});

it('does not overstate the first year by one day', function () {
    $property = prorataProperty($this->user, '2023-01-01');
    $this->service->generateDefaultComponents($property);

    // Base 200 000 € : gros œuvre 50 % / 50 ans = 2 000 €, toiture 10 % / 25 ans = 800 €,
    // électricité 800 €, étanchéité 10 % du bâti sur 15 ans… total connu = 106 666 c.
    $expected = $property->components->sum('annual_depreciation');

    $firstYear = $this->service->calculateAnnualDepreciation($property->fresh(), 2023);

    expect((int) $firstYear['building'])->toBe($expected);
});

it('counts a single day when the rental starts on 31 December', function () {
    $property = prorataProperty($this->user, '2023-12-31');
    $this->service->generateDefaultComponents($property);

    $annual = $property->components->sum('annual_depreciation');
    $firstYear = (int) $this->service->calculateAnnualDepreciation($property->fresh(), 2023)['building'];

    // 1/365 de la dotation, et non 2/365 comme avant le correctif.
    expect($firstYear)->toBeLessThan((int) ceil($annual / 365) * 6 + 6)
        ->and($firstYear)->toBeGreaterThan(0);

    $doubleCounted = (int) $this->service->calculateAnnualDepreciation($property->fresh(), 2024)['building'];
    expect($firstYear * 300)->toBeLessThan($doubleCounted);
});

it('prorates a mid-year start on the remaining days, that day included', function () {
    // Du 1er juillet au 31 décembre : 184 jours (31+31+30+31+30+31).
    $property = prorataProperty($this->user, '2023-07-01');
    $this->service->generateDefaultComponents($property);

    $annual = (int) $property->components->sum('annual_depreciation');
    $firstYear = (int) $this->service->calculateAnnualDepreciation($property->fresh(), 2023)['building'];

    // Tolérance de quelques centimes : chaque composant est tronqué séparément.
    $theoretical = (int) floor($annual * 184 / 365);
    expect(abs($firstYear - $theoretical))->toBeLessThanOrEqual(6);
});

it('uses 366 days for a leap year', function () {
    $property = prorataProperty($this->user, '2024-01-01');
    $this->service->generateDefaultComponents($property);

    $expected = (int) $property->components->sum('annual_depreciation');
    $firstYear = (int) $this->service->calculateAnnualDepreciation($property->fresh(), 2024)['building'];

    expect($firstYear)->toBe($expected);
});

it('prorates works from their own date without overstating', function () {
    $property = prorataProperty($this->user, '2020-01-01');

    PropertyWork::create([
        'property_id' => $property->id,
        'description' => 'Réfection toiture',
        'work_date' => '2023-01-01',
        'amount' => 1200000, // 12 000 €
        'tva_rate' => 0,
        'duration_years' => 10,
        'is_dedicated' => true,
    ]);

    $firstYear = (int) $this->service->calculateAnnualDepreciation($property->fresh(), 2023)['works'];
    $fullYear = (int) $this->service->calculateAnnualDepreciation($property->fresh(), 2024)['works'];

    expect($firstYear)->toBe($fullYear)->and($firstYear)->toBe(120000);
});

it('prorates furniture from its own date without overstating', function () {
    $property = prorataProperty($this->user, '2020-01-01');

    Furniture::create([
        'property_id' => $property->id,
        'description' => 'Canapé',
        'purchase_date' => '2023-01-01',
        'amount' => 150000, // 1 500 €
        'tva_rate' => 0,
        'duration_years' => 5,
        'is_dedicated' => true,
    ]);

    $firstYear = (int) $this->service->calculateAnnualDepreciation($property->fresh(), 2023)['furniture'];
    $fullYear = (int) $this->service->calculateAnnualDepreciation($property->fresh(), 2024)['furniture'];

    expect($firstYear)->toBe($fullYear)->and($firstYear)->toBe(30000);
});

it('prorates acquisition fees from the rental start without overstating', function () {
    $property = prorataProperty($this->user, '2023-01-01', notaryFees: 2500000); // 25 000 €

    $firstYear = (int) $this->service->calculateAnnualDepreciation($property->fresh(), 2023)['notary'];
    $fullYear = (int) $this->service->calculateAnnualDepreciation($property->fresh(), 2024)['notary'];

    // 25 000 € sur 25 ans = 1 000 € par an, sans réduction pour une année pleine.
    expect($firstYear)->toBe($fullYear)->and($firstYear)->toBe(100000);
});
