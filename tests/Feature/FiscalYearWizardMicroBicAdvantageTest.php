<?php

use App\Models\Income;
use App\Models\Property;
use App\Models\User;
use App\Services\DepreciationService;
use Illuminate\Support\Facades\Auth;

/**
 * Défaut gênant n°11 du test de bout en bout (2026-10-15) : la colonne « Avantage
 * vs réel » de l'assistant de clôture affichait en VERT, avec un « + », un
 * micro-BIC dont la base imposable est PLUS ÉLEVÉE que celle du réel — donc plus
 * cher pour le foyer, pas un avantage. `formatSignedEuros()` colore tout positif
 * en vert ; la valeur qu'on lui passait avait le signe inversé
 * (`$microBicX - $realResult` au lieu de `$realResult - $microBicX`).
 */
function fabriquerComparaisonWizard(int $acquisitionPrice, int $incomeAmount, int $year = 2024): \App\Filament\Pages\FiscalYearWizard
{
    $user = User::factory()->create();

    $property = Property::forceCreate([
        'user_id' => $user->id, 'name' => 'Bien test', 'address' => '1 rue test', 'city' => 'Lyon',
        'postal_code' => '69003', 'type' => 'apartment', 'total_area' => 40, 'rented_area' => 40,
        'acquisition_date' => '2020-01-01', 'acquisition_price' => $acquisitionPrice, 'notary_fees' => 0,
        'agency_fees' => 0, 'acquisition_fees_treatment' => Property::ACQUISITION_FEES_AMORTIZED,
        'acquisition_fees_duration' => 20, 'land_percentage' => 15, 'rental_start_date' => '2020-01-01',
        'rental_type' => 'seasonal', 'tva_regime' => Property::TVA_EXEMPT, 'is_primary_residence' => false,
    ]);

    app(DepreciationService::class)->generateDefaultComponents($property);

    Income::create([
        'property_id' => $property->id, 'income_date' => "{$year}-06-01", 'amount' => $incomeAmount,
        'tva_rate' => 0, 'source' => Income::SOURCE_AIRBNB,
    ]);

    Auth::login($user);

    $page = new \App\Filament\Pages\FiscalYearWizard;
    $page->data = ['year' => $year];

    return $page;
}

function microBicComparisonHtml(\App\Filament\Pages\FiscalYearWizard $page): string
{
    $method = new ReflectionMethod($page, 'buildMicroBicComparisonHtml');
    $method->setAccessible(true);

    return $method->invoke($page)->toHtml();
}

it('affiche en rouge un micro-BIC plus cher que le réel, jamais en vert', function () {
    // Gros bien, peu de revenus : la dotation aux amortissements écrase le
    // résultat réel très en dessous des deux bases micro-BIC.
    $html = microBicComparisonHtml(fabriquerComparaisonWizard(80_000_000, 5_000_000));

    expect($html)->toContain('wz-red')
        ->not->toContain('wz-green');
});

it('affiche en vert un micro-BIC réellement moins cher que le réel', function () {
    // Petit bien, peu de dotation : le réel reste au-dessus des deux bases
    // micro-BIC, qui deviennent un vrai gain.
    $html = microBicComparisonHtml(fabriquerComparaisonWizard(30_000_000, 5_000_000));

    expect($html)->toContain('wz-green')
        ->not->toContain('wz-red');
});
