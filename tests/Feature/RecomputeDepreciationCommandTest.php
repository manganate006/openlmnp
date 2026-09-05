<?php

use App\Models\Furniture;
use App\Models\Property;
use App\Models\PropertyComponent;
use App\Models\PropertyWork;
use App\Models\User;
use App\Services\DepreciationService;
use Illuminate\Support\Facades\DB;

/**
 * `openlmnp:recompute-depreciation` — les dotations sont des données DÉRIVÉES, mais
 * STOCKÉES : un correctif de calcul ne se propage pas tout seul aux bases existantes.
 *
 * Ce que la commande doit garantir, et que ces tests verrouillent :
 *   - rapport par défaut, `--fix` pour agir (convention commune aux réparations) ;
 *   - une dotation déclarée manuelle n'est JAMAIS écrasée : c'est exactement ce qu'un
 *     utilisateur a recopié de sa liasse ;
 *   - un cumul d'ouverture supérieur à la valeur brute est signalé, jamais corrigé.
 */
function recomputeProperty(User $user): Property
{
    return Property::forceCreate([
        'user_id' => $user->id,
        'name' => 'Bien recompute',
        'address' => '1 rue Test',
        'city' => 'Paris',
        'postal_code' => '75001',
        'type' => 'apartment',
        'total_area' => 100,
        'rented_area' => 100,
        'acquisition_date' => '2020-01-01',
        'acquisition_price' => 25000000,
        'notary_fees' => 0,
        'land_percentage' => 20,
        'rental_start_date' => '2023-01-01',
        'rental_type' => 'seasonal',
        'is_primary_residence' => false,
    ]);
}

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->property = recomputeProperty($this->user);
    app(DepreciationService::class)->generateDefaultComponents($this->property);
});

it('reports a drifted component dotation without touching it', function () {
    $roof = $this->property->components()->where('name', 'Toiture')->firstOrFail();
    // Écriture SQL directe : le hook `saving()` recalculerait la dotation.
    DB::table('property_components')->where('id', $roof->id)->update(['annual_depreciation' => 1]);

    $this->artisan('openlmnp:recompute-depreciation')
        ->expectsOutputToContain('Dotations désynchronisées')
        ->assertExitCode(0);

    expect((int) $roof->fresh()->annual_depreciation)->toBe(1);
});

it('recomputes a drifted component dotation with --fix', function () {
    $roof = $this->property->components()->where('name', 'Toiture')->firstOrFail();
    $expected = (int) $roof->annual_depreciation;
    DB::table('property_components')->where('id', $roof->id)->update(['annual_depreciation' => 1]);

    $this->artisan('openlmnp:recompute-depreciation --fix')->assertExitCode(0);

    expect((int) $roof->fresh()->annual_depreciation)->toBe($expected);
});

it('never overwrites a dotation recopied from a tax return', function () {
    $work = PropertyWork::create([
        'property_id' => $this->property->id,
        'description' => 'Rénovation',
        'amount' => 1000000,
        'tva_rate' => 0,
        'work_date' => '2023-01-01',
        'duration_years' => 10,
        'is_dedicated' => true,
        'depreciation_source' => PropertyWork::DEPRECIATION_SOURCE_MANUAL,
        'annual_depreciation' => 97531,
    ]);

    $item = Furniture::create([
        'property_id' => $this->property->id,
        'description' => 'Canapé',
        'amount' => 150000,
        'tva_rate' => 0,
        'purchase_date' => '2023-01-01',
        'duration_years' => 5,
        'is_dedicated' => true,
        'depreciation_source' => Furniture::DEPRECIATION_SOURCE_MANUAL,
        'annual_depreciation' => 29876,
    ]);

    $this->artisan('openlmnp:recompute-depreciation --fix')
        ->expectsOutputToContain('non touchées')
        ->assertExitCode(0);

    expect((int) $work->fresh()->annual_depreciation)->toBe(97531)
        ->and((int) $item->fresh()->annual_depreciation)->toBe(29876);
});

it('recomputes a drifted works dotation with --fix', function () {
    $work = PropertyWork::create([
        'property_id' => $this->property->id,
        'description' => 'Rénovation',
        'amount' => 1000000,
        'tva_rate' => 0,
        'work_date' => '2023-01-01',
        'duration_years' => 10,
        'is_dedicated' => true,
    ]);

    DB::table('property_works')->where('id', $work->id)->update(['annual_depreciation' => 42]);

    $this->artisan('openlmnp:recompute-depreciation --fix')->assertExitCode(0);

    expect((int) $work->fresh()->annual_depreciation)->toBe(100000);
});

it('flags an opening cumul larger than the gross value without correcting it', function () {
    $roof = $this->property->components()->where('name', 'Toiture')->firstOrFail();
    $roof->forceFill(['opening_accumulated_depreciation' => (int) $roof->base_amount + 1])->save();

    $this->artisan('openlmnp:recompute-depreciation --fix')
        ->expectsOutputToContain('Cumuls d\'ouverture supérieurs')
        ->assertExitCode(0);

    expect((int) $roof->fresh()->opening_accumulated_depreciation)
        ->toBe((int) $roof->base_amount + 1);
});

it('says nothing is wrong when every dotation follows the rule', function () {
    $this->artisan('openlmnp:recompute-depreciation')
        ->expectsOutputToContain('Aucune dotation désynchronisée')
        ->assertExitCode(0);
});
