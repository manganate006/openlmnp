<?php

use App\Support\MicroBicRules;

/**
 * Barème micro-BIC (CGI art. 50-0), vérifié le 2026-10-01 sur bofip.impots.gouv.fr
 * (BOI-BAREME-000044, actualité ACTU-2025-00127) : pour les revenus perçus à compter
 * de 2026, l'ensemble des locations en meublé relève d'un seuil de 83 600 € et d'un
 * abattement de 50 %, à l'exception des meublés de tourisme NON classés, qui restent
 * à 15 000 € et 30 %.
 */
it('rates unclassified furnished tourism at 30 percent, capped at 15 000 euros', function () {
    expect(MicroBicRules::rate(MicroBicRules::UNCLASSIFIED, 2026))->toBe('30')
        ->and(MicroBicRules::ceilingCents(MicroBicRules::UNCLASSIFIED, 2026))->toBe(1_500_000);
});

it('rates classified furnished tourism and long-term rentals at 50 percent, capped at 83 600 euros for 2026', function () {
    expect(MicroBicRules::rate(MicroBicRules::CLASSIFIED, 2026))->toBe('50')
        ->and(MicroBicRules::ceilingCents(MicroBicRules::CLASSIFIED, 2026))->toBe(8_360_000)
        ->and(MicroBicRules::rate(MicroBicRules::LONG_TERM, 2026))->toBe('50')
        ->and(MicroBicRules::ceilingCents(MicroBicRules::LONG_TERM, 2026))->toBe(8_360_000);
});

it('keeps the lower 77 700 euro ceiling for 2025, before the triennial revision', function () {
    expect(MicroBicRules::ceilingCents(MicroBicRules::CLASSIFIED, 2025))->toBe(7_770_000)
        ->and(MicroBicRules::ceilingCents(MicroBicRules::UNCLASSIFIED, 2025))->toBe(1_500_000);
});

it('reuses the last known schedule year for an unlisted future year', function () {
    expect(MicroBicRules::rate(MicroBicRules::UNCLASSIFIED, 2027))->toBe('30')
        ->and(MicroBicRules::ceilingCents(MicroBicRules::CLASSIFIED, 2027))->toBe(8_360_000);
});

it('refuses micro-BIC once the gross income crosses the ceiling', function () {
    expect(MicroBicRules::isAvailable(MicroBicRules::UNCLASSIFIED, 2026, 1_500_000))->toBeTrue()
        ->and(MicroBicRules::isAvailable(MicroBicRules::UNCLASSIFIED, 2026, 1_500_001))->toBeFalse();

    $reason = MicroBicRules::unavailableReason(MicroBicRules::UNCLASSIFIED, 2026, 1_600_000);

    expect($reason)->not->toBeNull()
        ->and($reason)->toContain('15 000')
        ->and(MicroBicRules::unavailableReason(MicroBicRules::UNCLASSIFIED, 2026, 1_000_000))->toBeNull();
});

it('finds the ceiling from a raw rate for callers still using "50"/"30"', function () {
    expect(MicroBicRules::ceilingForRate('30', 2026))->toBe(1_500_000)
        ->and(MicroBicRules::ceilingForRate('50', 2026))->toBe(8_360_000)
        ->and(MicroBicRules::isAvailableForRate('30', 2026, 1_600_000))->toBeFalse()
        ->and(MicroBicRules::isAvailableForRate('50', 2026, 1_600_000))->toBeTrue();
});

it('derives a conservative default classification from the rental type', function () {
    expect(MicroBicRules::defaultClassificationForRentalType('seasonal'))->toBe(MicroBicRules::UNCLASSIFIED)
        ->and(MicroBicRules::defaultClassificationForRentalType('mixed'))->toBe(MicroBicRules::UNCLASSIFIED)
        ->and(MicroBicRules::defaultClassificationForRentalType('long_term'))->toBe(MicroBicRules::LONG_TERM)
        ->and(MicroBicRules::defaultClassificationForRentalType(null))->toBe(MicroBicRules::UNCLASSIFIED);
});
