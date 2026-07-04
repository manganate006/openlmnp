<?php

use App\Models\AccountingEntry;
use App\Models\BadgeDefinition;
use App\Models\BadgeProgress;
use App\Models\FiscalYear;
use App\Models\Loan;
use App\Models\LoanPayment;
use App\Models\Property;
use App\Models\User;
use App\Models\UserBadge;
use App\Services\LoanService;

/**
 * Isolation multi-utilisateurs des modèles « enfants » de second niveau :
 * LoanPayment (via loan → property), AccountingEntry (via fiscal_year),
 * BadgeProgress et UserBadge (user_id direct).
 */
function isolationProperty(User $user): Property
{
    return Property::withoutGlobalScopes()->forceCreate([
        'user_id' => $user->id,
        'name' => 'Bien de '.$user->name,
        'address' => '1 rue Test',
        'city' => 'Paris',
        'postal_code' => '75001',
        'type' => 'apartment',
        'total_area' => 100,
        'rented_area' => 50,
        'acquisition_date' => '2020-01-01',
        'acquisition_price' => 30000000,
        'notary_fees' => 1500000,
        'market_value' => null,
        'land_percentage' => 20,
        'rental_start_date' => '2023-01-01',
        'rental_type' => 'seasonal',
        'is_primary_residence' => true,
    ]);
}

beforeEach(function () {
    $this->userA = User::factory()->create();
    $this->userB = User::factory()->create();
});

it('isolates loan payments between users', function () {
    $property = isolationProperty($this->userA);

    $loan = Loan::withoutGlobalScopes()->forceCreate([
        'property_id' => $property->id,
        'bank_name' => 'Banque Test',
        'amount' => 20000000,
        'annual_rate' => 1.5,
        'duration_months' => 240,
        'start_date' => '2021-01-01',
        'monthly_payment' => 0,
        'insurance_monthly' => 0,
        'insurance_type' => 'fixed',
        'insurance_rate' => 0,
    ]);

    app(LoanService::class)->generateSchedule($loan);

    $this->actingAs($this->userA);
    expect(LoanPayment::count())->toBe(240);

    $this->actingAs($this->userB);
    expect(LoanPayment::count())->toBe(0);
});

it('isolates accounting entries between users', function () {
    $fiscalYear = FiscalYear::withoutGlobalScopes()->forceCreate([
        'user_id' => $this->userA->id,
        'year' => 2025,
        'status' => 'draft',
    ]);

    AccountingEntry::forceCreate([
        'fiscal_year_id' => $fiscalYear->id,
        'property_id' => null,
        'entry_date' => '2025-12-31',
        'account_code' => '706000',
        'label' => 'Loyers 2025',
        'debit' => 0,
        'credit' => 1200000,
        'journal' => 'VE',
    ]);

    $this->actingAs($this->userA);
    expect(AccountingEntry::count())->toBe(1);

    $this->actingAs($this->userB);
    expect(AccountingEntry::count())->toBe(0);
});

it('isolates badge progress and unlocked badges between users', function () {
    $definition = BadgeDefinition::first() ?? BadgeDefinition::forceCreate([
        'code' => 'test-badge',
        'category' => 'test',
        'name' => 'Badge de test',
        'description' => 'Badge utilisé par les tests',
        'icon' => 'heroicon-o-star',
        'color' => 'emerald',
        'is_yearly' => false,
        'sort_order' => 1,
        'is_active' => true,
        'unlock_conditions' => '[]',
    ]);

    BadgeProgress::forceCreate([
        'user_id' => $this->userA->id,
        'badge_definition_id' => $definition->id,
        'current_value' => 3,
        'target_value' => 10,
    ]);

    UserBadge::forceCreate([
        'user_id' => $this->userA->id,
        'badge_definition_id' => $definition->id,
        'unlocked_at' => now(),
        'is_notified' => false,
    ]);

    $this->actingAs($this->userA);
    expect(BadgeProgress::count())->toBe(1)
        ->and(UserBadge::count())->toBe(1);

    $this->actingAs($this->userB);
    expect(BadgeProgress::count())->toBe(0)
        ->and(UserBadge::count())->toBe(0);
});

it('purges only expired demo accounts by default, all with --all, never real users', function () {
    $expiredDemo = User::factory()->create(['is_demo' => true, 'demo_expires_at' => now()->subHour()]);
    $activeDemo = User::factory()->create(['is_demo' => true, 'demo_expires_at' => now()->addHours(5)]);
    $realUser = User::factory()->create(['is_demo' => false]);

    $this->artisan('openlmnp:demo-cleanup')->assertSuccessful();

    expect(User::find($expiredDemo->id))->toBeNull()
        ->and(User::find($activeDemo->id))->not->toBeNull()
        ->and(User::find($realUser->id))->not->toBeNull();

    $this->artisan('openlmnp:demo-cleanup', ['--all' => true])->assertSuccessful();

    expect(User::find($activeDemo->id))->toBeNull()
        ->and(User::find($realUser->id))->not->toBeNull();
});
