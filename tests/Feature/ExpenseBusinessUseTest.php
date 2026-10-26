<?php

use App\Filament\Resources\Expenses\Pages\EditExpense;
use App\Models\AccountingEntry;
use App\Models\Expense;
use App\Models\Property;
use App\Models\User;
use App\Services\AccountingEntryService;
use App\Services\FiscalYearService;
use App\Services\RecurringExpenseService;
use App\Services\TaxReturnService;
use App\Services\TvaDeclarationService;
use Carbon\CarbonImmutable;
use Livewire\Livewire;

/**
 * Part d'usage pour la location d'une charge mixte (ticket #17, cocool97) : un forfait
 * téléphone utilisé à 30 % pour la location n'est déductible qu'à 30 %, indépendamment de la
 * quote-part surface, qui vaut 1 pour un logement loué en entier.
 */
function bienUsage(User $user, array $attrs = []): Property
{
    return Property::forceCreate($attrs + [
        'user_id' => $user->id, 'name' => 'Studio', 'address' => '1 rue du Test',
        'city' => 'Lyon', 'postal_code' => '69003', 'type' => 'apartment',
        'total_area' => 40, 'rented_area' => 40, 'acquisition_date' => '2022-01-01',
        'acquisition_price' => 20000000, 'notary_fees' => 0, 'land_percentage' => 15,
        'rental_start_date' => '2022-03-01', 'rental_type' => 'seasonal',
        'is_primary_residence' => false,
    ]);
}

function chargeUsage(Property $property, int $amount, ?int $bp, bool $dedicated = false, array $attrs = []): Expense
{
    return Expense::create($attrs + [
        'property_id' => $property->id, 'category' => Expense::CATEGORY_TELECOM,
        'description' => 'Forfait mobile', 'amount' => $amount, 'expense_date' => '2025-03-10',
        'is_dedicated' => $dedicated, 'business_use_bp' => $bp, 'tva_rate' => 0,
    ]);
}

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

it('arrondit au centime le plus proche, à l\'identique en PHP et en SQL', function () {
    $property = bienUsage($this->user);
    $cas = [[3333, 3000], [1001, 3333], [999, 1250], [-2500, 3000], [12345, 0], [100, null]];
    foreach ($cas as [$amount, $bp]) {
        chargeUsage($property, $amount, $bp);
    }

    $php = $property->expenses()->get()->sum(fn (Expense $e) => $e->usageAdjusted());
    $sql = (int) $property->expenses()->sum(Expense::usageAdjustedSql());

    expect(Expense::applyUsage(3333, 3000))->toBe(1000)   // 999,9 → 1000
        ->and(Expense::applyUsage(999, 1250))->toBe(125)  // 124,875 → 125
        ->and(Expense::applyUsage(100, null))->toBe(100)
        ->and($sql)->toBe($php);
});

it('convertit la saisie en pourcentage', function (mixed $saisie, ?int $bp) {
    expect(Expense::percentToBp($saisie))->toBe($bp);
})->with([
    'vide' => ['', null],
    'null' => [null, null],
    '30' => ['30', 3000],
    'virgule' => ['12,5', 1250],
    '100 = entièrement à la location' => ['100', null],
    'au-delà de 100' => [150, null],
]);

it('retient la part d\'usage dans le résultat, puis la quote-part surface', function (int $rented, bool $dedicated, int $attendu) {
    $property = bienUsage($this->user, ['rented_area' => $rented]);
    chargeUsage($property, 10000, 3000, $dedicated); // 100 € à 30 %

    $fy = app(FiscalYearService::class)->getOrCreate($this->user, 2025);

    expect(app(FiscalYearService::class)->computeTotals($fy)['total_expenses'])->toBe($attendu);
})->with([
    'logement loué en entier' => [40, false, 3000],
    'moitié louée, charge partagée' => [20, false, 1500],
    'moitié louée, charge dédiée' => [20, true, 3000],
]);

it('laisse une charge sans part d\'usage inchangée', function () {
    $property = bienUsage($this->user, ['rented_area' => 20]);
    $expense = chargeUsage($property, 10001, null);

    $fy = app(FiscalYearService::class)->getOrCreate($this->user, 2025);

    expect(app(FiscalYearService::class)->computeTotals($fy)['total_expenses'])->toBe(5000)
        ->and($expense->fresh()->effective_amount)->toBe('5000');
});

it('applique la part d\'usage au HT et à la TVA déductible', function () {
    $property = bienUsage($this->user, ['tva_regime' => 'liable']);
    // 120 € TTC à 20 % = 100 € HT + 20 € de TVA, utilisés à 30 % pour la location.
    $expense = chargeUsage($property, 12000, 3000, true, ['tva_rate' => 2000]);

    $fy = app(FiscalYearService::class)->getOrCreate($this->user, 2025);
    $totals = app(FiscalYearService::class)->computeTotals($fy);

    expect($expense->fresh()->amount_ht)->toBe(10000)
        ->and($totals['total_expenses'])->toBe(3000)
        ->and(app(TvaDeclarationService::class)->calculate($this->user, 2025)['totals']['deductible'])->toBe(600);

    app(AccountingEntryService::class)->generateForFiscalYear($fy);
    $entries = AccountingEntry::where('piece_ref', 'like', 'CHG-%')->get();

    expect($entries->where('account_code', '44566')->sum('debit'))->toBe(600)
        ->and($entries->where('account_code', '512')->sum('credit'))->toBe(3600);
});

it('porte la part d\'usage dans le détail de la liasse', function () {
    $property = bienUsage($this->user);
    chargeUsage($property, 10000, 3000);

    $fy = app(FiscalYearService::class)->getOrCreate($this->user, 2025);
    $breakdown = app(TaxReturnService::class)->resultBreakdown($fy, collect([$property]), 2025);
    $row = $breakdown['properties'][0]['expenses'][0];

    expect($row['amount'])->toBe(3000)
        ->and($row['usage_bp'])->toBe(3000);
});

it('recopie la part d\'usage sur les échéances générées', function () {
    $property = bienUsage($this->user);
    $expense = chargeUsage($property, 3000, 3000, true, ['recurring_type' => 'monthly', 'expense_date' => '2025-01-05']);

    app(RecurringExpenseService::class)->generate($expense, CarbonImmutable::parse('2025-03-31'));

    expect($property->expenses()->pluck('business_use_bp')->unique()->values()->all())->toBe([3000])
        ->and($property->expenses()->count())->toBe(3);
});

it('enregistre la part d\'usage saisie dans le formulaire', function () {
    $property = bienUsage($this->user);
    $expense = chargeUsage($property, 3000, null, true);

    Livewire::test(EditExpense::class, ['record' => $expense->getRouteKey()])
        ->assertFormSet(['business_use_bp' => null])
        ->fillForm(['business_use_bp' => '12.5'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($expense->fresh()->business_use_bp)->toBe(1250);

    Livewire::test(EditExpense::class, ['record' => $expense->getRouteKey()])
        ->assertFormSet(['business_use_bp' => '12.5'])
        ->fillForm(['business_use_bp' => ''])
        ->call('save');

    expect($expense->fresh()->business_use_bp)->toBeNull();
});

it('refuse une part d\'usage au-delà de 100 %', function () {
    $expense = chargeUsage(bienUsage($this->user), 3000, null, true);

    Livewire::test(EditExpense::class, ['record' => $expense->getRouteKey()])
        ->fillForm(['business_use_bp' => '150'])
        ->call('save')
        ->assertHasFormErrors(['business_use_bp']);
});

it('donne au simulateur le même total de charges que l\'exercice', function () {
    $property = bienUsage($this->user, ['rented_area' => 20]);
    chargeUsage($property, 10000, 3000);        // partagée, 30 % : 30 € puis × 0,5
    chargeUsage($property, 4000, 2500, true);   // dédiée, 25 % : 10 €
    chargeUsage($property, 5000, null);         // partagée sans part d'usage : 25 €

    $fy = app(FiscalYearService::class)->getOrCreate($this->user, 2025);
    $attendu = app(FiscalYearService::class)->computeTotals($fy)['total_expenses'];

    $chart = Livewire::test(\App\Filament\Pages\Simulator::class)
        ->set('year', 2025)
        ->instance()->simulationResults()['chart_data'];

    expect($attendu)->toBe(5000)
        ->and($chart['expenses_dedicated'] + $chart['expenses_shared'])->toBe($attendu);
});

it('recopie la part d\'usage sur une charge dupliquée', function () {
    $property = bienUsage($this->user);
    $expense = chargeUsage($property, 3000, 3000, true);

    Livewire::test(\App\Filament\Resources\Expenses\Pages\ListExpenses::class)
        ->mountAction(\Filament\Actions\Testing\TestAction::make('duplicate_expense')->table($expense))
        ->setActionData(['expense_date' => '2025-04-10'])
        ->callMountedAction()
        ->assertHasNoErrors();

    expect($property->expenses()->pluck('business_use_bp')->all())->toBe([3000, 3000]);
});
