<?php

use App\Models\Property;
use App\Models\User;

/**
 * `Property::quota_share` multiplie la base amortissable ET quasiment toutes les charges
 * au prorata — un `'0'` rendu en silence quand `total_area` est absente ou nulle ne
 * ressemble jamais à une erreur, il ressemble à un résultat fiscal exact, juste faux.
 * L'accesseur REFUSE désormais de calculer plutôt que de rendre ce zéro muet.
 */
function propertyWithArea(User $user, int $totalArea, int $rentedArea): Property
{
    return Property::forceCreate([
        'user_id' => $user->id,
        'name' => 'Bien Test',
        'address' => '1 rue Test',
        'city' => 'Paris',
        'postal_code' => '75001',
        'type' => 'apartment',
        'total_area' => $totalArea,
        'rented_area' => $rentedArea,
        'acquisition_date' => '2023-01-01',
        'acquisition_price' => 20_000_000,
        'notary_fees' => 0,
        'land_percentage' => 15,
        'rental_start_date' => '2023-01-01',
        'rental_type' => 'seasonal',
        'is_primary_residence' => false,
    ]);
}

it('computes the rented share of a property normally', function () {
    $property = propertyWithArea(User::factory()->create(), 126, 35);

    expect($property->quota_share)->toBe(bcdiv('35', '126', 10));
});

it('refuses to compute a quota share when total_area is zero, instead of returning a silent zero', function () {
    $property = propertyWithArea(User::factory()->create(), 0, 0);

    expect(fn () => $property->quota_share)
        ->toThrow(RuntimeException::class, 'surface totale absente ou nulle');
});

it('refuses to compute a quota share when total_area is negative', function () {
    $property = propertyWithArea(User::factory()->create(), -1, 0);

    expect(fn () => $property->quota_share)->toThrow(RuntimeException::class);
});
