<?php

use App\Models\FiscalYear;
use App\Models\Property;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create();
});

// === Wizard 2: Fiscal Year Closure ===

it('shows fiscal year wizard page', function () {
    $this->actingAs($this->user)
        ->get('/fiscal-year-wizard')
        ->assertOk()
        ->assertSee('Assistant de cl');
});

it('fiscal year wizard shows year selection', function () {
    $this->actingAs($this->user)
        ->get('/fiscal-year-wizard')
        ->assertOk()
        ->assertSee('fiscale');
});

it('fiscal year wizard refuses to recreate a closed year', function () {
    // Totaux volontairement faux : s'ils changent, un recalcul a eu lieu
    FiscalYear::forceCreate([
        'user_id' => $this->user->id,
        'year' => 2024,
        'status' => FiscalYear::STATUS_CLOSED,
        'total_income' => 999999,
        'fiscal_result' => 123456,
    ]);

    $this->actingAs($this->user);

    Livewire::test(\App\Filament\Pages\FiscalYearWizard::class)
        ->set('data.year', 2024)
        ->set('data.status', FiscalYear::STATUS_DRAFT)
        ->call('create')
        ->assertNotified('Exercice déjà clôturé');

    $fiscalYear = FiscalYear::withoutGlobalScopes()
        ->where('user_id', $this->user->id)
        ->where('year', 2024)
        ->first();

    // Pas de réouverture silencieuse ni de recalcul
    expect($fiscalYear->status)->toBe(FiscalYear::STATUS_CLOSED);
    expect($fiscalYear->total_income)->toBe(999999);
});

// === Wizard 3: Onboarding ===

it('shows onboarding wizard when no properties exist', function () {
    $this->actingAs($this->user)
        ->get('/onboarding-wizard')
        ->assertOk()
        ->assertSee('Bienvenue');
});

it('redirects onboarding wizard when properties exist', function () {
    Property::forceCreate([
        'user_id' => $this->user->id,
        'name' => 'Test',
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

    $this->actingAs($this->user)
        ->get('/onboarding-wizard')
        ->assertRedirect('/');
});

// === Wizard 4: Loan (integrated in resource) ===

it('shows loan creation wizard with steps', function () {
    $this->actingAs($this->user)
        ->get('/loans/create')
        ->assertOk()
        ->assertSee('Banque');
});

// === Wizard 5: Annual Import ===

it('shows annual import wizard page', function () {
    $this->actingAs($this->user)
        ->get('/annual-import-wizard')
        ->assertOk()
        ->assertSee('import annuel');
});

it('annual import wizard shows year and property selection', function () {
    $this->actingAs($this->user)
        ->get('/annual-import-wizard')
        ->assertOk()
        ->assertSee('Bien immobilier');
});

// === Régression : double conversion des montants du wizard d'onboarding ===
// `$this->form->getState()` déshydrate déjà (euros -> centimes) ; re-multiplier
// par 100 dans create() stockait les montants du bien 10 000 fois trop grands.

it('onboarding wizard stores property amounts in centimes without double conversion', function () {
    $this->actingAs($this->user);

    Livewire::test(\App\Filament\Pages\OnboardingWizard::class)
        ->set('data.name', 'Studio Test')
        ->set('data.type', 'apartment')
        ->set('data.rental_type', 'seasonal')
        ->set('data.address', '1 rue Test')
        ->set('data.city', 'Paris')
        ->set('data.postal_code', '75001')
        ->set('data.total_area', 40)
        ->set('data.rented_area', 40)
        ->set('data.acquisition_price', '250000')
        ->set('data.notary_fees', '20000')
        ->set('data.agency_fees', '8000')
        ->set('data.acquisition_date', '2023-01-15')
        ->set('data.market_value', '260000')
        ->set('data.land_percentage', 15)
        ->set('data.rental_start_date', '2023-03-01')
        ->call('create');

    $property = Property::withoutGlobalScopes()->where('user_id', $this->user->id)->firstOrFail();

    expect($property->acquisition_price)->toBe(25_000_000)
        ->and($property->notary_fees)->toBe(2_000_000)
        ->and($property->agency_fees)->toBe(800_000)
        ->and($property->market_value)->toBe(26_000_000);
});

it('onboarding wizard keeps loan amounts correct (no dehydration on those fields)', function () {
    $this->actingAs($this->user);

    Livewire::test(\App\Filament\Pages\OnboardingWizard::class)
        ->set('data.name', 'Studio Emprunt')
        ->set('data.type', 'apartment')
        ->set('data.rental_type', 'seasonal')
        ->set('data.address', '2 rue Test')
        ->set('data.city', 'Lyon')
        ->set('data.postal_code', '69001')
        ->set('data.total_area', 50)
        ->set('data.rented_area', 50)
        ->set('data.acquisition_price', '300000')
        ->set('data.acquisition_date', '2023-01-15')
        ->set('data.land_percentage', 15)
        ->set('data.rental_start_date', '2023-03-01')
        ->set('data.has_loan', true)
        ->set('data.loan_bank_name', 'Banque Test')
        ->set('data.loan_amount', '200000')
        ->set('data.loan_annual_rate', 3.5)
        ->set('data.loan_duration_months', 240)
        ->set('data.loan_start_date', '2023-01-15')
        ->call('create');

    $property = Property::withoutGlobalScopes()->where('user_id', $this->user->id)->firstOrFail();
    $loan = $property->loans()->withoutGlobalScopes()->firstOrFail();

    expect($property->acquisition_price)->toBe(30_000_000)
        ->and($loan->amount)->toBe(20_000_000);
});

it('onboarding wizard generates components consistent with the property price', function () {
    $this->actingAs($this->user);

    Livewire::test(\App\Filament\Pages\OnboardingWizard::class)
        ->set('data.name', 'Studio Composants')
        ->set('data.type', 'apartment')
        ->set('data.rental_type', 'seasonal')
        ->set('data.address', '3 rue Test')
        ->set('data.city', 'Nantes')
        ->set('data.postal_code', '44000')
        ->set('data.total_area', 40)
        ->set('data.rented_area', 40)
        ->set('data.acquisition_price', '200000')
        ->set('data.acquisition_date', '2023-01-15')
        ->set('data.land_percentage', 15)
        ->set('data.rental_start_date', '2023-03-01')
        ->call('create');

    $property = Property::withoutGlobalScopes()->where('user_id', $this->user->id)->firstOrFail();
    $totalBase = $property->components()->withoutGlobalScopes()->sum('base_amount');

    // Base amortissable = 200 000 € - 15 % de terrain = 170 000 €
    expect((int) $totalBase)->toBe(17_000_000);
});
