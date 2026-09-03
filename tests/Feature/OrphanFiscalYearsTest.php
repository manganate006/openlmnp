<?php

// Supprimer un bien emporte en cascade ses revenus, charges, meubles, travaux, composants
// et emprunts — mais laissait les EXERCICES intacts, avec des totaux calculés sur des
// données qui n'existent plus. `fiscal_years` ne porte aucun lien vers `properties` (un
// exercice agrège tous les biens d'une année), donc aucune contrainte de clé étrangère ne
// pouvait s'en charger.
//
// Rien ne le signalait : l'exercice continuait d'alimenter la liste et le tableau de bord
// avec ses montants figés, et son amortissement différé se propageait aux années suivantes.

use App\Models\Expense;
use App\Models\FiscalYear;
use App\Models\Income;
use App\Models\Property;
use App\Models\User;
use App\Services\FiscalYearService;

function propertyWithFigures(User $user, int $year): Property
{
    $property = Property::forceCreate([
        'user_id' => $user->id,
        'name' => 'Studio',
        'address' => '1 rue Test',
        'city' => 'Paris',
        'postal_code' => '75001',
        'type' => 'studio',
        'total_area' => 30,
        'rented_area' => 30,
        'acquisition_date' => "{$year}-01-01",
        'rental_start_date' => "{$year}-01-01",
        'acquisition_price' => 20000000,
        'notary_fees' => 1500000,
        'agency_fees' => 0,
    ]);

    Income::forceCreate([
        'property_id' => $property->id,
        'income_date' => "{$year}-06-15",
        'amount' => 1200000,
        'platform_fee' => 0,
        'tourist_tax' => 0,
        'source' => 'airbnb',
    ]);

    Expense::forceCreate([
        'property_id' => $property->id,
        'expense_date' => "{$year}-06-20",
        'amount' => 200000,
        'category' => 'insurance',
        'description' => 'Assurance',
        'is_dedicated' => true,
    ]);

    return $property;
}

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->service = app(FiscalYearService::class);
    $this->year = 2025;
});

// === Recalcul automatique à la suppression ===

it('recalculates draft fiscal years when a property is deleted', function () {
    $property = propertyWithFigures($this->user, $this->year);

    $fiscalYear = FiscalYear::forceCreate([
        'user_id' => $this->user->id,
        'year' => $this->year,
        'status' => FiscalYear::STATUS_DRAFT,
    ]);
    $this->service->calculate($fiscalYear);

    expect($fiscalYear->fresh()->total_income)->toBe(1200000);

    $property->delete();

    // Sans le correctif, les 12 000 € d'un bien disparu alimentaient encore la liste
    // des exercices et le widget du tableau de bord.
    $refreshed = $fiscalYear->fresh();
    expect($refreshed->total_income)->toBe(0)
        ->and($refreshed->total_expenses)->toBe(0)
        ->and($refreshed->total_depreciation)->toBe(0);
});

it('leaves a closed fiscal year untouched when a property is deleted', function () {
    // Un exercice clôturé porte ce qui a été déclaré à l'administration : le réécrire
    // sans le dire effacerait un fait. C'est la commande de réparation qui le signale.
    $property = propertyWithFigures($this->user, $this->year);

    $fiscalYear = FiscalYear::forceCreate([
        'user_id' => $this->user->id,
        'year' => $this->year,
        'status' => FiscalYear::STATUS_DRAFT,
    ]);
    $this->service->calculate($fiscalYear);
    $fiscalYear->update(['status' => FiscalYear::STATUS_CLOSED]);

    $property->delete();

    expect($fiscalYear->fresh()->total_income)->toBe(1200000)
        ->and($fiscalYear->fresh()->status)->toBe(FiscalYear::STATUS_CLOSED);
});

it('does not touch another user\'s fiscal years', function () {
    $other = User::factory()->create();
    propertyWithFigures($other, $this->year);

    $otherYear = FiscalYear::forceCreate([
        'user_id' => $other->id,
        'year' => $this->year,
        'status' => FiscalYear::STATUS_DRAFT,
    ]);
    $this->service->calculate($otherYear);

    $mine = propertyWithFigures($this->user, $this->year);
    $mine->delete();

    expect($otherYear->fresh()->total_income)->toBe(1200000);
});

// === computeTotals : lecture pure ===

it('computes totals without writing anything', function () {
    propertyWithFigures($this->user, $this->year);

    $fiscalYear = FiscalYear::forceCreate([
        'user_id' => $this->user->id,
        'year' => $this->year,
        'status' => FiscalYear::STATUS_DRAFT,
    ]);

    $computed = $this->service->computeTotals($fiscalYear);

    expect($computed['total_income'])->toBe(1200000)
        // Rien n'a été persisté : c'est ce qui rend le mode rapport honnête.
        ->and($fiscalYear->fresh()->total_income)->toBe(0);
});

// === Commande de réparation ===

it('reports a desynchronised fiscal year without fixing it', function () {
    $property = propertyWithFigures($this->user, $this->year);

    $fiscalYear = FiscalYear::forceCreate([
        'user_id' => $this->user->id,
        'year' => $this->year,
        'status' => FiscalYear::STATUS_CLOSED,
    ]);
    $this->service->calculate($fiscalYear, force: true);

    // Suppression sous le radar du hook : reproduit une base déjà corrompue.
    Property::withoutGlobalScopes()->whereKey($property->id)->delete();

    $this->artisan('openlmnp:repair-orphan-fiscal-years')
        ->expectsOutputToContain('désynchronisé')
        ->expectsOutputToContain('total_income')
        ->assertExitCode(0);

    expect($fiscalYear->fresh()->total_income)->toBe(1200000);
});

it('refuses to rewrite a closed fiscal year without --closed', function () {
    $property = propertyWithFigures($this->user, $this->year);

    $fiscalYear = FiscalYear::forceCreate([
        'user_id' => $this->user->id,
        'year' => $this->year,
        'status' => FiscalYear::STATUS_CLOSED,
    ]);
    $this->service->calculate($fiscalYear, force: true);
    Property::withoutGlobalScopes()->whereKey($property->id)->delete();

    $this->artisan('openlmnp:repair-orphan-fiscal-years', ['--fix' => true])
        ->expectsOutputToContain('clôturé')
        ->assertExitCode(0);

    expect($fiscalYear->fresh()->total_income)->toBe(1200000);
});

it('rewrites a closed fiscal year only when explicitly asked', function () {
    $property = propertyWithFigures($this->user, $this->year);

    $fiscalYear = FiscalYear::forceCreate([
        'user_id' => $this->user->id,
        'year' => $this->year,
        'status' => FiscalYear::STATUS_CLOSED,
    ]);
    $this->service->calculate($fiscalYear, force: true);
    Property::withoutGlobalScopes()->whereKey($property->id)->delete();

    $this->artisan('openlmnp:repair-orphan-fiscal-years', ['--fix' => true, '--closed' => true])
        ->assertExitCode(0);

    expect($fiscalYear->fresh()->total_income)->toBe(0)
        // Le statut ne change pas : on recalcule, on ne rouvre pas.
        ->and($fiscalYear->fresh()->status)->toBe(FiscalYear::STATUS_CLOSED);
});

it('stays silent when everything is consistent', function () {
    propertyWithFigures($this->user, $this->year);

    $fiscalYear = FiscalYear::forceCreate([
        'user_id' => $this->user->id,
        'year' => $this->year,
        'status' => FiscalYear::STATUS_DRAFT,
    ]);
    $this->service->calculate($fiscalYear);

    $this->artisan('openlmnp:repair-orphan-fiscal-years')
        ->expectsOutputToContain('reflètent les données saisies')
        ->assertExitCode(0);
});
