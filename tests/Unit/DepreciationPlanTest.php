<?php

use App\Filament\Pages\DepreciationPlan;
use App\Models\Furniture;
use App\Models\Property;
use App\Models\PropertyWork;
use App\Models\User;
use App\Services\DepreciationService;
use App\Services\TaxReturnService;
use Livewire\Livewire;

/**
 * Plan d'amortissement vérifiable (issue #13, OpenProject n° 1170 et 1171).
 *
 * Le plan et l'annexe de la liasse n'ont de valeur que s'ils disent EXACTEMENT ce que la
 * liasse calcule : le test qui compte est l'égalité des cumuls, année par année, entre
 * `planForProperty()` et `depreciationDetailForYear()`.
 */
function verifiablePlanProperty(User $user, array $overrides = []): Property
{
    return Property::forceCreate(array_merge([
        'user_id' => $user->id,
        'name' => 'Bien plan',
        'address' => '1 rue Test',
        'city' => 'Paris',
        'postal_code' => '75001',
        'type' => 'apartment',
        'total_area' => 100,
        'rented_area' => 100,
        'acquisition_date' => '2024-03-01',
        'acquisition_price' => 20000000,
        'notary_fees' => 1500000,
        'agency_fees' => 0,
        'market_value' => null,
        'land_percentage' => 20,
        'rental_start_date' => '2025-01-01',
        'rental_type' => 'seasonal',
        'is_primary_residence' => false,
        'acquisition_fees_treatment' => Property::ACQUISITION_FEES_AMORTIZED,
        'acquisition_fees_duration' => Property::ACQUISITION_FEES_DEFAULT_DURATION,
    ], $overrides));
}

function planFor(Property $property, string $name): array
{
    $plans = app(DepreciationService::class)->planForProperty($property->fresh(['components', 'works', 'furniture']));

    return collect($plans)->firstWhere('name', $name);
}

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

it('prorates the first year in days and adds a catch-up year that ends on the gross value', function () {
    $property = verifiablePlanProperty($this->user);
    Furniture::create([
        'property_id' => $property->id, 'description' => 'Literie',
        'purchase_date' => '2025-07-01', 'amount' => 100000, 'tva_rate' => 0,
        'duration_years' => 10, 'is_dedicated' => true,
    ]);

    $plan = planFor($property, 'Literie');

    // 1er juillet d'une année non bissextile : 184 jours restants, jour compris.
    expect($plan['prorata'])->toBe(['days' => 184, 'of' => 365])
        ->and($plan['duration'])->toBe(10)
        ->and($plan['full_annual'])->toBe(10000)
        ->and($plan['last_year'])->toBe(2035)
        ->and(count($plan['years']))->toBe(11)
        ->and($plan['years'][0]['annual'])->toBe(5041)   // 10 000 × 184/365, tronqué
        ->and($plan['years'][1]['annual'])->toBe(10000)
        ->and($plan['years'][10]['annual'])->toBe(10000 - 5041)
        ->and(end($plan['years'])['cumul'])->toBe(100000)
        ->and(end($plan['years'])['remaining'])->toBe(0);
});

it('ends a plan started on the 1st of January after exactly its duration', function () {
    $property = verifiablePlanProperty($this->user);
    Furniture::create([
        'property_id' => $property->id, 'description' => 'Canapé',
        'purchase_date' => '2025-01-01', 'amount' => 50000, 'tva_rate' => 0,
        'duration_years' => 5, 'is_dedicated' => true,
    ]);

    $plan = planFor($property, 'Canapé');

    expect($plan['prorata'])->toBe(['days' => 365, 'of' => 365])
        ->and($plan['last_year'])->toBe(2029)
        ->and(count($plan['years']))->toBe(5)
        ->and(end($plan['years'])['remaining'])->toBe(0);
});

it('gives, year after year, the same cumulative depreciation as the tax return', function () {
    $property = verifiablePlanProperty($this->user, ['activity_start_date' => '2024-06-15']);
    $service = app(DepreciationService::class);
    $service->generateDefaultComponents($property);

    PropertyWork::create([
        'property_id' => $property->id, 'description' => 'Cuisine',
        'work_date' => '2025-09-10', 'amount' => 800000, 'tva_rate' => 0,
        'duration_years' => 10, 'is_dedicated' => false,
        'opening_accumulated_depreciation' => 0,
    ]);
    Furniture::create([
        'property_id' => $property->id, 'description' => 'Literie',
        'purchase_date' => '2023-02-01', 'amount' => 300000, 'tva_rate' => 0,
        'duration_years' => 5, 'is_dedicated' => true,
        'opening_accumulated_depreciation' => 40000, 'opening_accumulated_year' => 2025,
    ]);

    $property = $property->fresh(['components', 'works', 'furniture']);
    $plans = collect($service->planForProperty($property));

    expect($plans->pluck('type')->unique()->values()->all())
        ->toBe(['building', 'work', 'furniture', 'notary']);

    for ($year = 2024; $year <= 2040; $year++) {
        foreach ($service->depreciationDetailForYear($property, $year) as $line) {
            $plan = $plans->first(fn ($p) => $p['type'] === $line['type'] && $p['name'] === $line['name']);
            $row = collect($plan['years'])->last(fn ($y) => $y['year'] <= $year);

            // Avant la première ligne du plan (cumul repris), le cumul est le stock repris.
            $cumul = $row['cumul'] ?? $plan['opening'];

            expect($cumul)->toBe((int) $line['cumul'], "{$line['name']} en {$year}");

            if ($row !== null && $row['year'] === $year) {
                expect($row['deducted'])->toBe((int) $line['annual'], "dotation {$line['name']} en {$year}");
            }
        }
    }
});

it('marks the years before the real regime as deemed, not deducted', function () {
    $property = verifiablePlanProperty($this->user, ['activity_start_date' => '2023-01-01']);
    Furniture::create([
        'property_id' => $property->id, 'description' => 'Table',
        'purchase_date' => '2023-01-01', 'amount' => 50000, 'tva_rate' => 0,
        'duration_years' => 5, 'is_dedicated' => true,
    ]);

    $years = collect(planFor($property, 'Table')['years'])->keyBy('year');

    expect($years[2023]['annual'])->toBe(10000)
        ->and($years[2023]['deducted'])->toBe(0)
        ->and($years[2024]['deducted'])->toBe(0)
        ->and($years[2025]['deducted'])->toBe(10000)
        ->and($years[2024]['cumul'])->toBe(20000);
});

it('gives the tax return annex what it needs to redo each depreciation by hand', function () {
    $property = verifiablePlanProperty($this->user);
    Furniture::create([
        'property_id' => $property->id, 'description' => 'Literie',
        'purchase_date' => '2025-07-01', 'amount' => 100000, 'tva_rate' => 0,
        'duration_years' => 10, 'is_dedicated' => true,
    ]);

    $properties = Property::withoutGlobalScopes()->where('user_id', $this->user->id)
        ->with(['components', 'works', 'furniture'])->get();
    $rows = collect(app(TaxReturnService::class)->assetBreakdown($properties, 2026));

    $land = $rows->firstWhere('cerfa', '420');
    expect($land['in_service'])->toBeNull()
        ->and($land['duration'])->toBeNull()
        ->and($land['prorata'])->toBeNull()
        ->and($land['remaining'])->toBeNull();

    $bedding = $rows->firstWhere('name', 'Literie');
    expect($bedding['in_service'])->toBe('2025-07-01')
        ->and($bedding['duration'])->toBe(10)
        ->and($bedding['prorata'])->toBe(['days' => 184, 'of' => 365])
        ->and($bedding['cumul'])->toBe(5041 + 10000)
        ->and($bedding['remaining'])->toBe(100000 - 15041);

    $fees = $rows->firstWhere('name', 'Frais de notaire');
    expect($fees['duration'])->toBe(Property::ACQUISITION_FEES_DEFAULT_DURATION)
        ->and($fees['remaining'])->toBe($fees['base'] - $fees['cumul']);
});

it('renders the plan tab for the owner and hides other users\' properties', function () {
    $property = verifiablePlanProperty($this->user);
    Furniture::create([
        'property_id' => $property->id, 'description' => 'Literie',
        'purchase_date' => '2025-07-01', 'amount' => 100000, 'tva_rate' => 0,
        'duration_years' => 10, 'is_dedicated' => true,
    ]);

    $this->get("/depreciation-plan/{$property->id}")
        ->assertOk()
        ->assertSee('Literie')
        ->assertSee('184/365 jours')
        ->assertSee('Plan d&#039;amortissement', false);

    $other = User::factory()->create();
    $foreign = verifiablePlanProperty($other, ['name' => 'Bien d\'autrui']);

    $this->get("/depreciation-plan/{$foreign->id}")->assertNotFound();
});

it('links the plan tab from the property tabs', function () {
    $property = verifiablePlanProperty($this->user);

    $this->get("/depreciation-editor/{$property->id}")
        ->assertOk()
        ->assertSee("/depreciation-plan/{$property->id}", false);
});
