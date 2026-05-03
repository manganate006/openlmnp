<?php

use App\Models\BadgeDefinition;
use App\Models\Document;
use App\Models\Expense;
use App\Models\FiscalYear;
use App\Models\Income;
use App\Models\Loan;
use App\Models\Property;
use App\Models\PropertyComponent;
use App\Models\User;
use App\Services\BadgeService;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->service = new BadgeService();

    // Seed badge definitions
    $this->seed(\Database\Seeders\BadgeSeeder::class);
});

function createTestProperty(User $user, array $overrides = []): Property
{
    return Property::forceCreate(array_merge([
        'user_id' => $user->id,
        'name' => 'Test',
        'address' => '1 rue Test',
        'city' => 'Paris',
        'postal_code' => '75001',
        'type' => 'apartment',
        'total_area' => 100,
        'rented_area' => 50,
        'acquisition_date' => '2020-01-01',
        'acquisition_price' => 30000000,
        'notary_fees' => 0,
        'market_value' => null,
        'land_percentage' => 20,
        'rental_start_date' => '2023-01-01',
        'rental_type' => 'seasonal',
        'is_primary_residence' => true,
    ], $overrides));
}

// === Onboarding badges ===

it('awards premier_bien badge when property is created', function () {
    createTestProperty($this->user);

    $this->service->evaluate($this->user, 'property_created');

    expect($this->user->hasBadge('premier_bien'))->toBeTrue();
});

it('awards multi_biens badge when 2+ properties exist', function () {
    createTestProperty($this->user, ['name' => 'Bien 1']);
    createTestProperty($this->user, ['name' => 'Bien 2']);

    $this->service->evaluate($this->user, 'property_created');

    expect($this->user->hasBadge('multi_biens'))->toBeTrue();
});

it('awards architecte badge when 5+ components exist', function () {
    $property = createTestProperty($this->user);

    for ($i = 1; $i <= 5; $i++) {
        PropertyComponent::forceCreate([
            'property_id' => $property->id,
            'name' => "Component {$i}",
            'percentage' => 20,
            'duration_years' => 25,
            'base_amount' => 1000000,
            'annual_depreciation' => 40000,
            'sort_order' => $i,
        ]);
    }

    $this->service->evaluate($this->user, 'component_created');

    expect($this->user->hasBadge('architecte'))->toBeTrue();
});

it('awards banquier badge when loan exists', function () {
    $property = createTestProperty($this->user);

    Loan::forceCreate([
        'property_id' => $property->id,
        'bank_name' => 'Test Bank',
        'amount' => 20000000,
        'annual_rate' => 2.5,
        'duration_months' => 240,
        'start_date' => '2020-01-01',
        'monthly_payment' => 84700,
        'insurance_monthly' => 3000,
    ]);

    $this->service->evaluate($this->user, 'loan_created');

    expect($this->user->hasBadge('banquier'))->toBeTrue();
});

it('awards premiere_annee badge when fiscal year exists', function () {
    FiscalYear::forceCreate([
        'user_id' => $this->user->id,
        'year' => 2025,
        'status' => 'draft',
        'total_income' => 0,
        'total_expenses' => 0,
        'total_depreciation' => 0,
        'capped_depreciation' => 0,
        'deferred_depreciation' => 0,
        'previous_deferred' => 0,
        'fiscal_result' => 0,
    ]);

    $this->service->evaluate($this->user, 'fiscal_year_created');

    expect($this->user->hasBadge('premiere_annee'))->toBeTrue();
});

// === Yearly badges ===

it('awards mois_complet badge when a month has income and expenses', function () {
    $property = createTestProperty($this->user);

    Income::forceCreate([
        'property_id' => $property->id,
        'income_date' => '2025-03-15',
        'amount' => 100000,
        'platform_fee' => 0,
        'tourist_tax' => 0,
        'source' => 'airbnb',
    ]);

    Expense::forceCreate([
        'property_id' => $property->id,
        'expense_date' => '2025-03-20',
        'amount' => 5000,
        'category' => 'cleaning',
        'description' => 'Test',
        'is_dedicated' => true,
    ]);

    $this->service->evaluate($this->user, 'income_created', ['fiscal_year' => 2025]);

    expect($this->user->hasBadge('mois_complet', 2025))->toBeTrue();
});

it('awards rigoureux badge when all expenses have receipts', function () {
    $property = createTestProperty($this->user);

    $expense1 = Expense::forceCreate([
        'property_id' => $property->id,
        'expense_date' => '2025-06-01',
        'amount' => 5000,
        'category' => 'insurance',
        'description' => 'Test',
        'is_dedicated' => true,
    ]);
    Document::forceCreate([
        'documentable_type' => Expense::class,
        'documentable_id' => $expense1->id,
        'label' => 'Justificatif',
        'file_path' => 'receipts/test.pdf',
    ]);

    $expense2 = Expense::forceCreate([
        'property_id' => $property->id,
        'expense_date' => '2025-07-01',
        'amount' => 3000,
        'category' => 'cleaning',
        'description' => 'Test',
        'is_dedicated' => true,
    ]);
    Document::forceCreate([
        'documentable_type' => Expense::class,
        'documentable_id' => $expense2->id,
        'label' => 'Justificatif',
        'file_path' => 'receipts/test2.pdf',
    ]);

    $this->service->evaluate($this->user, 'expense_created', ['fiscal_year' => 2025]);

    expect($this->user->hasBadge('rigoureux', 2025))->toBeTrue();
});

it('does not award rigoureux badge when some expenses lack receipts', function () {
    $property = createTestProperty($this->user);

    $expense1 = Expense::forceCreate([
        'property_id' => $property->id,
        'expense_date' => '2025-06-01',
        'amount' => 5000,
        'category' => 'insurance',
        'description' => 'Test',
        'is_dedicated' => true,
    ]);
    Document::forceCreate([
        'documentable_type' => Expense::class,
        'documentable_id' => $expense1->id,
        'label' => 'Justificatif',
        'file_path' => 'receipts/test.pdf',
    ]);

    // This expense has NO document attached
    Expense::forceCreate([
        'property_id' => $property->id,
        'expense_date' => '2025-07-01',
        'amount' => 3000,
        'category' => 'cleaning',
        'description' => 'Test',
        'is_dedicated' => true,
    ]);

    $this->service->evaluate($this->user, 'expense_created', ['fiscal_year' => 2025]);

    expect($this->user->hasBadge('rigoureux', 2025))->toBeFalse();
});

it('awards categorise badge when no other category expenses exist', function () {
    $property = createTestProperty($this->user);

    Expense::forceCreate([
        'property_id' => $property->id,
        'expense_date' => '2025-06-01',
        'amount' => 5000,
        'category' => 'insurance',
        'description' => 'Test',
        'is_dedicated' => true,
    ]);

    $this->service->evaluate($this->user, 'expense_created', ['fiscal_year' => 2025]);

    expect($this->user->hasBadge('categorise', 2025))->toBeTrue();
});

it('does not award categorise badge when other category exists', function () {
    $property = createTestProperty($this->user);

    Expense::forceCreate([
        'property_id' => $property->id,
        'expense_date' => '2025-06-01',
        'amount' => 5000,
        'category' => 'other',
        'description' => 'Test',
        'is_dedicated' => true,
    ]);

    $this->service->evaluate($this->user, 'expense_created', ['fiscal_year' => 2025]);

    expect($this->user->hasBadge('categorise', 2025))->toBeFalse();
});

// === Does not duplicate badges ===

it('does not award the same badge twice', function () {
    createTestProperty($this->user);

    $this->service->evaluate($this->user, 'property_created');
    $this->service->evaluate($this->user, 'property_created');

    expect($this->user->userBadges()->count())->toBeGreaterThanOrEqual(1);
    $premierBienCount = $this->user->userBadges()
        ->whereHas('definition', fn ($q) => $q->where('code', 'premier_bien'))
        ->count();
    expect($premierBienCount)->toBe(1);
});

// === Yearly badge for different years ===

it('awards same yearly badge for different years', function () {
    $property = createTestProperty($this->user);

    // 2024
    Income::forceCreate([
        'property_id' => $property->id,
        'income_date' => '2024-03-15',
        'amount' => 100000,
        'platform_fee' => 0,
        'tourist_tax' => 0,
        'source' => 'airbnb',
    ]);
    Expense::forceCreate([
        'property_id' => $property->id,
        'expense_date' => '2024-03-20',
        'amount' => 5000,
        'category' => 'cleaning',
        'description' => 'Test',
        'is_dedicated' => true,
    ]);

    // 2025
    Income::forceCreate([
        'property_id' => $property->id,
        'income_date' => '2025-03-15',
        'amount' => 100000,
        'platform_fee' => 0,
        'tourist_tax' => 0,
        'source' => 'airbnb',
    ]);
    Expense::forceCreate([
        'property_id' => $property->id,
        'expense_date' => '2025-03-20',
        'amount' => 5000,
        'category' => 'cleaning',
        'description' => 'Test',
        'is_dedicated' => true,
    ]);

    $this->service->evaluate($this->user, 'income_created', ['fiscal_year' => 2024]);
    $this->service->evaluate($this->user, 'income_created', ['fiscal_year' => 2025]);

    expect($this->user->hasBadge('mois_complet', 2024))->toBeTrue();
    expect($this->user->hasBadge('mois_complet', 2025))->toBeTrue();
});

// === evaluateAll (backdate) ===

it('backdates all earned badges', function () {
    $property = createTestProperty($this->user);

    Income::forceCreate([
        'property_id' => $property->id,
        'income_date' => '2025-03-15',
        'amount' => 100000,
        'platform_fee' => 0,
        'tourist_tax' => 0,
        'source' => 'airbnb',
    ]);

    $expense = Expense::forceCreate([
        'property_id' => $property->id,
        'expense_date' => '2025-03-20',
        'amount' => 5000,
        'category' => 'insurance',
        'description' => 'Test',
        'is_dedicated' => true,
    ]);
    Document::forceCreate([
        'documentable_type' => Expense::class,
        'documentable_id' => $expense->id,
        'label' => 'Justificatif',
        'file_path' => 'test.pdf',
    ]);

    $awarded = $this->service->evaluateAll($this->user, silent: true);

    expect($awarded)->toBeGreaterThan(0);
    expect($this->user->hasBadge('premier_bien'))->toBeTrue();
    expect($this->user->hasBadge('mois_complet', 2025))->toBeTrue();
});

// === Completeness score ===

it('calculates completeness score', function () {
    $property = createTestProperty($this->user);

    Income::forceCreate([
        'property_id' => $property->id,
        'income_date' => '2025-01-15',
        'amount' => 100000,
        'platform_fee' => 0,
        'tourist_tax' => 0,
        'source' => 'airbnb',
    ]);

    $score = $this->service->getCompletenessScore($this->user, 2025);

    expect($score['incomes'])->toBe(25);
    expect($score['expenses'])->toBe(0);
    expect($score['total'])->toBe(25);
});

// === Heatmap ===

it('generates monthly heatmap', function () {
    $property = createTestProperty($this->user);

    Income::forceCreate([
        'property_id' => $property->id,
        'income_date' => '2025-03-15',
        'amount' => 100000,
        'platform_fee' => 0,
        'tourist_tax' => 0,
        'source' => 'airbnb',
    ]);

    Expense::forceCreate([
        'property_id' => $property->id,
        'expense_date' => '2025-03-20',
        'amount' => 5000,
        'category' => 'cleaning',
        'description' => 'Test',
        'is_dedicated' => true,
    ]);

    $heatmap = $this->service->getMonthlyHeatmap($this->user, 2025);

    expect($heatmap[3])->toBe('complete');
    expect($heatmap[1])->toBe('empty');
});
