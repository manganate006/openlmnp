<?php

use App\Filament\Resources\Expenses\ExpenseResource;
use App\Filament\Resources\FiscalYears\FiscalYearResource;
use App\Filament\Resources\Furniture\FurnitureResource;
use App\Filament\Resources\Incomes\IncomeResource;
use App\Filament\Resources\Loans\LoanResource;
use App\Filament\Resources\Properties\PropertyResource;
use App\Filament\Resources\PropertyComponents\PropertyComponentResource;
use App\Filament\Resources\PropertyWorks\PropertyWorkResource;
use App\Models\Expense;
use App\Models\FiscalYear;
use App\Models\Furniture;
use App\Models\Income;
use App\Models\Loan;
use App\Models\Property;
use App\Models\PropertyComponent;
use App\Models\PropertyWork;
use App\Models\User;

/**
 * Crée un jeu de données complet (1 ligne par table) pour un utilisateur.
 */
function seedFullDatasetFor(User $user, string $suffix): void
{
    $property = Property::forceCreate([
        'user_id' => $user->id,
        'name' => 'Bien ' . $suffix,
        'address' => '1 rue Test',
        'city' => 'Paris',
        'postal_code' => '75001',
        'type' => 'apartment',
        'total_area' => 100,
        'rented_area' => 100,
        'acquisition_date' => '2020-01-01',
        'acquisition_price' => 30000000,
        'notary_fees' => 0,
        'land_percentage' => 15,
        'rental_start_date' => '2023-01-01',
        'rental_type' => 'seasonal',
        'is_primary_residence' => false,
    ]);

    PropertyComponent::create([
        'property_id' => $property->id,
        'name' => 'Gros œuvre ' . $suffix,
        'percentage' => 50,
        'duration_years' => 50,
        'base_amount' => 12750000,
        'annual_depreciation' => 255000,
        'sort_order' => 1,
    ]);

    PropertyWork::create([
        'property_id' => $property->id,
        'description' => 'Travaux ' . $suffix,
        'amount' => 1000000,
        'work_date' => '2023-03-01',
        'duration_years' => 10,
        'is_dedicated' => true,
        'annual_depreciation' => 100000,
    ]);

    Furniture::create([
        'property_id' => $property->id,
        'description' => 'Mobilier ' . $suffix,
        'amount' => 100000,
        'purchase_date' => '2023-01-01',
        'duration_years' => 5,
        'is_dedicated' => true,
        'is_second_hand' => false,
        'annual_depreciation' => 20000,
    ]);

    Income::create([
        'property_id' => $property->id,
        'income_date' => '2024-06-15',
        'amount' => 100000,
        'platform_fee' => 3000,
        'tourist_tax' => 0,
        'source' => 'airbnb',
        'guest_name' => 'Voyageur ' . $suffix,
    ]);

    Expense::create([
        'property_id' => $property->id,
        'expense_date' => '2024-01-01',
        'amount' => 50000,
        'category' => Expense::CATEGORY_ENERGY,
        'description' => 'Charge ' . $suffix,
        'is_dedicated' => false,
        'recurring_type' => 'yearly',
    ]);

    Loan::create([
        'property_id' => $property->id,
        'bank_name' => 'Banque ' . $suffix,
        'amount' => 20000000,
        'annual_rate' => 1.500,
        'duration_months' => 240,
        'start_date' => '2021-01-01',
        'monthly_payment' => 0,
        'insurance_monthly' => 3000,
        'insurance_type' => 'fixed',
        'insurance_rate' => 0,
    ]);

    FiscalYear::forceCreate([
        'user_id' => $user->id,
        'year' => 2024,
        'status' => FiscalYear::STATUS_DRAFT,
    ]);
}

beforeEach(function () {
    $this->user = User::factory()->create();
    seedFullDatasetFor($this->user, 'A');
});

it('scopes all navigation badges to the authenticated user', function () {
    $this->actingAs($this->user);

    expect(PropertyResource::getNavigationBadge())->toBe('1')
        ->and(IncomeResource::getNavigationBadge())->toBe('1')
        ->and(ExpenseResource::getNavigationBadge())->toBe('1')
        ->and(LoanResource::getNavigationBadge())->toBe('1')
        ->and(FurnitureResource::getNavigationBadge())->toBe('1')
        ->and(PropertyWorkResource::getNavigationBadge())->toBe('1')
        ->and(PropertyComponentResource::getNavigationBadge())->toBe('1')
        ->and(FiscalYearResource::getNavigationBadge())->toBe('1');
});

it('shows no badge for a user without data even when other users have data', function () {
    $emptyUser = User::factory()->create();
    $this->actingAs($emptyUser);

    expect(PropertyResource::getNavigationBadge())->toBeNull()
        ->and(IncomeResource::getNavigationBadge())->toBeNull()
        ->and(ExpenseResource::getNavigationBadge())->toBeNull()
        ->and(LoanResource::getNavigationBadge())->toBeNull()
        ->and(FurnitureResource::getNavigationBadge())->toBeNull()
        ->and(PropertyWorkResource::getNavigationBadge())->toBeNull()
        ->and(PropertyComponentResource::getNavigationBadge())->toBeNull()
        ->and(FiscalYearResource::getNavigationBadge())->toBeNull();
});

it('does not count other users rows in navigation badges', function () {
    $otherUser = User::factory()->create();
    seedFullDatasetFor($otherUser, 'B');

    $this->actingAs($this->user);

    expect(IncomeResource::getNavigationBadge())->toBe('1')
        ->and(ExpenseResource::getNavigationBadge())->toBe('1')
        ->and(LoanResource::getNavigationBadge())->toBe('1');
});

it('isolates incomes and expenses list pages between users', function () {
    $otherUser = User::factory()->create();
    seedFullDatasetFor($otherUser, 'B');

    $this->actingAs($this->user)
        ->get('/incomes')
        ->assertOk()
        ->assertSee('Voyageur A')
        ->assertDontSee('Voyageur B');

    $this->actingAs($this->user)
        ->get('/expenses')
        ->assertOk()
        ->assertSee('Charge A')
        ->assertDontSee('Charge B');
});

it('isolates furniture and works list pages between users', function () {
    $otherUser = User::factory()->create();
    seedFullDatasetFor($otherUser, 'B');

    $propertyA = Property::withoutGlobalScopes()->where('user_id', $this->user->id)->first();

    $this->actingAs($this->user)
        ->get('/furniture/' . $propertyA->id)
        ->assertOk()
        ->assertSee('Mobilier A')
        ->assertDontSee('Mobilier B');

    $this->actingAs($this->user)
        ->get('/property-works/' . $propertyA->id)
        ->assertOk()
        ->assertSee('Travaux A')
        ->assertDontSee('Travaux B');
});

it('leaks nothing when opening the furniture url of another user property', function () {
    $otherUser = User::factory()->create();
    seedFullDatasetFor($otherUser, 'B');

    $propertyB = Property::withoutGlobalScopes()->where('user_id', $otherUser->id)->first();

    $this->actingAs($this->user)
        ->get('/furniture/' . $propertyB->id)
        ->assertOk()
        ->assertDontSee('Mobilier B')
        ->assertDontSee('Bien B');
});
