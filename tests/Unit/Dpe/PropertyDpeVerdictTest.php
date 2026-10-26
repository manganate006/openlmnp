<?php

use App\Support\Dpe\PropertyDpeVerdict;
use Illuminate\Support\Carbon;

afterEach(function () {
    Carbon::setTestNow();
});

it('returns no verdict without a DPE class', function () {
    expect(PropertyDpeVerdict::forRentalConfiguration(null, 'long_term', false, '69003'))->toBe([]);
});

it('applies the furnished lease usage only to a long-term or mixed rental', function () {
    expect(PropertyDpeVerdict::forRentalConfiguration('F', 'long_term', false, '69003', '2026-10-13'))
        ->toHaveKey('bail_meuble')
        ->not->toHaveKey('tourisme')
        ->not->toHaveKey('tourisme_residence_principale');

    expect(PropertyDpeVerdict::forRentalConfiguration('F', 'seasonal', false, '69003', '2026-10-13'))
        ->not->toHaveKey('bail_meuble');
});

it('applies the tourist rental usage only to a seasonal or mixed rental', function () {
    expect(PropertyDpeVerdict::forRentalConfiguration('F', 'seasonal', false, '69003', '2026-10-13'))
        ->toHaveKey('tourisme')
        ->not->toHaveKey('tourisme_residence_principale');

    expect(PropertyDpeVerdict::forRentalConfiguration('F', 'mixed', false, '69003', '2026-10-13'))
        ->toHaveKey('bail_meuble')
        ->toHaveKey('tourisme');
});

it('swaps to the residence principale usage when the loueur lives there', function () {
    $verdicts = PropertyDpeVerdict::forRentalConfiguration('F', 'seasonal', true, '69003', '2026-10-13');

    expect($verdicts)->toHaveKey('tourisme_residence_principale')
        ->not->toHaveKey('tourisme');
});

it('detects overseas departments from the postal code', function () {
    expect(PropertyDpeVerdict::overseas('97400'))->toBeTrue()  // La Réunion
        ->and(PropertyDpeVerdict::overseas('97133'))->toBeTrue() // Guadeloupe (971)
        ->and(PropertyDpeVerdict::overseas('69003'))->toBeFalse()
        ->and(PropertyDpeVerdict::overseas(null))->toBeFalse();
});

it('is null before six months of a valid DPE', function () {
    Carbon::setTestNow('2026-10-13');

    expect(PropertyDpeVerdict::expiryStatus(null))->toBeNull()
        ->and(PropertyDpeVerdict::expiryStatus('2028-01-01'))->toBeNull();
});

it('flags as soon exactly six months before expiry, inclusive', function () {
    Carbon::setTestNow('2026-10-13');

    expect(PropertyDpeVerdict::expiryStatus('2027-04-13'))->toBe('soon')
        ->and(PropertyDpeVerdict::expiryStatus('2027-04-14'))->toBeNull();
});

it('flags as expired the day after validity ends, not the last valid day', function () {
    Carbon::setTestNow('2026-10-13');

    expect(PropertyDpeVerdict::expiryStatus('2026-10-13'))->toBe('soon')
        ->and(PropertyDpeVerdict::expiryStatus('2026-10-12'))->toBe('expired');
});
