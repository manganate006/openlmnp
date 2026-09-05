<?php

use App\Models\Expense;
use App\Models\FiscalYear;
use App\Models\Income;
use App\Models\Property;
use App\Models\User;

/**
 * `openlmnp:repair-deficits` — les totaux d'exercice sont figés en base : la correction du
 * 2033-D ne se propage pas seule sur les dossiers déjà tenus.
 */
function makeDeficitCommandProperty(User $user): Property
{
    return Property::forceCreate([
        'user_id' => $user->id,
        'name' => 'Bien à réparer',
        'address' => '7 rue du Report',
        'city' => 'Rennes',
        'postal_code' => '35000',
        'type' => 'apartment',
        'total_area' => 40,
        'rented_area' => 40,
        'acquisition_date' => '2014-01-01',
        'acquisition_price' => 10000000,
        'notary_fees' => 0,
        'agency_fees' => 0,
        'market_value' => null,
        'land_percentage' => 0,
        'rental_start_date' => '2014-06-01',
        'rental_type' => 'seasonal',
        'is_primary_residence' => false,
    ]);
}

/** Un dossier tenu avant la correction : un exercice déficitaire, puis un exercice bénéficiaire. */
function seedLegacyDeficitChain(User $user): array
{
    $property = makeDeficitCommandProperty($user);

    Expense::create([
        'property_id' => $property->id,
        'expense_date' => '2024-03-01',
        'amount' => 40000, // 400 € de charges, aucune recette : déficit de 400 €
        'category' => 'maintenance',
        'description' => 'Réparation',
        'is_dedicated' => true,
        'recurring_type' => 'once',
    ]);

    Income::create([
        'property_id' => $property->id,
        'income_date' => '2025-05-01',
        'amount' => 15000, // 150 € de bénéfice en 2025
        'platform_fee' => 0,
        'tourist_tax' => 0,
        'source' => 'direct',
    ]);

    // Exercices tels que les produisait l'ancien code : aucun suivi de déficit.
    $y2024 = FiscalYear::forceCreate([
        'user_id' => $user->id,
        'year' => 2024,
        'status' => FiscalYear::STATUS_CLOSED,
        'total_expenses' => 40000,
        'fiscal_result' => -40000,
        'pdf_path' => 'tax-returns/2024/liasse_fiscale_2024.pdf',
    ]);

    $y2025 = FiscalYear::forceCreate([
        'user_id' => $user->id,
        'year' => 2025,
        'status' => FiscalYear::STATUS_DRAFT,
        'total_income' => 15000,
        'fiscal_result' => 15000,
    ]);

    return [$y2024, $y2025];
}

it('reports the deficit tracking to reconstitute without writing anything', function () {
    $user = User::factory()->create();
    [$y2024, $y2025] = seedLegacyDeficitChain($user);

    $this->artisan('openlmnp:repair-deficits')
        ->expectsOutputToContain('exercice 2024')
        ->expectsOutputToContain('984')
        ->assertSuccessful();

    expect($y2024->refresh()->deficit_carryforward)->toBe(0)
        ->and($y2025->refresh()->previous_deficit)->toBe(0);
});

it('reconstitutes the deficit chain with --fix', function () {
    $user = User::factory()->create();
    [$y2024, $y2025] = seedLegacyDeficitChain($user);

    $this->artisan('openlmnp:repair-deficits', ['--fix' => true])->assertSuccessful();

    expect($y2024->refresh()->deficit_carryforward)->toBe(40000)
        ->and($y2024->deficit_detail[0]['origin_year'])->toBe(2024)
        ->and($y2025->refresh()->previous_deficit)->toBe(40000)
        ->and($y2025->deficit_imputed)->toBe(15000)
        ->and($y2025->deficit_carryforward)->toBe(25000);
});

it('never rewrites the declared result of a closed fiscal year', function () {
    $user = User::factory()->create();
    [$y2024] = seedLegacyDeficitChain($user);

    // On dégrade volontairement les totaux figés : la commande ne doit pas y toucher.
    $y2024->forceFill(['total_expenses' => 111111, 'fiscal_result' => -40000])->save();

    $this->artisan('openlmnp:repair-deficits', ['--fix' => true])->assertSuccessful();

    expect($y2024->refresh()->total_expenses)->toBe(111111)
        ->and($y2024->fiscal_result)->toBe(-40000)
        ->and($y2024->deficit_carryforward)->toBe(40000);
});

it('is idempotent once the chain has been reconstituted', function () {
    $user = User::factory()->create();
    seedLegacyDeficitChain($user);

    $this->artisan('openlmnp:repair-deficits', ['--fix' => true])->assertSuccessful();

    $this->artisan('openlmnp:repair-deficits')
        ->expectsOutputToContain('à jour')
        ->assertSuccessful();
});

it('limits the repair to one user', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    [$y2024] = seedLegacyDeficitChain($user);
    [$otherYear] = seedLegacyDeficitChain($other);

    $this->artisan('openlmnp:repair-deficits', ['--fix' => true, '--user' => $user->email])
        ->assertSuccessful();

    expect($y2024->refresh()->deficit_carryforward)->toBe(40000)
        ->and($otherYear->refresh()->deficit_carryforward)->toBe(0);
});
