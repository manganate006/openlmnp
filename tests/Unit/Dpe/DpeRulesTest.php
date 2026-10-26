<?php

use App\Support\Dpe\DpeRules;

/**
 * Copie conforme de `openlmnp-vitrine` (`DpeToolTest`) : mêmes échéances, mêmes assertions.
 * Un changement ici doit venir d'un texte relu sur Légifrance, jamais d'un test.
 */
it('follows the decency calendar of the furnished lease', function () {
    expect(DpeRules::verdict('G', 'bail_meuble', '2026-10-13')['allowed'])->toBeFalse()
        ->and(DpeRules::verdict('F', 'bail_meuble', '2026-10-13')['allowed'])->toBeTrue()
        ->and(DpeRules::verdict('F', 'bail_meuble', '2028-01-01')['allowed'])->toBeFalse()
        ->and(DpeRules::verdict('E', 'bail_meuble', '2033-12-31')['allowed'])->toBeTrue()
        ->and(DpeRules::verdict('E', 'bail_meuble', '2034-01-01')['allowed'])->toBeFalse()
        ->and(DpeRules::verdict('F', 'bail_meuble', '2026-10-13')['next'])
            ->toBe(['date' => '2028-01-01', 'worst_allowed' => 'E']);
});

it('has its own calendar overseas', function () {
    expect(DpeRules::verdict('G', 'bail_meuble', '2026-10-13', true)['applies'])->toBeFalse()
        ->and(DpeRules::verdict('G', 'bail_meuble', '2028-01-01', true)['allowed'])->toBeFalse()
        ->and(DpeRules::verdict('E', 'bail_meuble', '2040-01-01', true)['allowed'])->toBeTrue()
        ->and(DpeRules::verdict('F', 'tourisme', '2034-01-01', true)['worst_allowed'])->toBe('E')
        ->and(DpeRules::verdict('G', 'changement_usage', '2026-10-13', true)['applies'])->toBeFalse();
});

it('binds tourist rentals only from 2034, loi du 19 novembre 2024 art. 3 III', function () {
    expect(DpeRules::verdict('G', 'tourisme', '2033-12-31')['applies'])->toBeFalse()
        ->and(DpeRules::verdict('E', 'tourisme', '2034-01-01')['allowed'])->toBeFalse()
        ->and(DpeRules::verdict('D', 'tourisme', '2034-01-01')['allowed'])->toBeTrue()
        ->and(DpeRules::verdict('G', 'tourisme_residence_principale', '2040-01-01')['applies'])->toBeFalse();
});

it('needs class E now and D from 2034 for the change of use authorisation', function () {
    expect(DpeRules::verdict('E', 'changement_usage', '2026-10-13')['allowed'])->toBeTrue()
        ->and(DpeRules::verdict('F', 'changement_usage', '2026-10-13')['allowed'])->toBeFalse()
        ->and(DpeRules::verdict('E', 'changement_usage', '2034-01-01')['allowed'])->toBeFalse();
});

it('never applies the change of use calendar overseas', function () {
    expect(DpeRules::verdict('A', 'changement_usage', '2026-10-13', true)['applies'])->toBeFalse();
});

it('rejects an unknown usage or class', function () {
    expect(fn () => DpeRules::verdict('D', 'inconnu', '2026-10-13'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => DpeRules::verdict('H', 'bail_meuble', '2026-10-13'))->toThrow(InvalidArgumentException::class);
});
