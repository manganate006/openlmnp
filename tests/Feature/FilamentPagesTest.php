<?php

use App\Models\Expense;
use App\Models\FiscalYear;
use App\Models\Furniture;
use App\Models\Income;
use App\Models\Loan;
use App\Models\Property;
use App\Models\PropertyComponent;
use App\Models\PropertyWork;
use App\Models\User;
use App\Services\DepreciationService;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create();
});

// === AUTH ===

it('redirects unauthenticated users to login', function () {
    $this->get('/properties')->assertRedirect('/login');
});

it('shows login page', function () {
    $this->get('/login')->assertOk()->assertSee('OpenLMNP');
});

it('allows registration', function () {
    $this->get('/register')->assertOk()->assertSee('Register');
});

it('authenticates a user', function () {
    $this->actingAs($this->user)
        ->get('/')
        ->assertOk();
});

// === DASHBOARD ===

it('shows dashboard with fiscal overview', function () {
    $this->actingAs($this->user)
        ->get('/')
        ->assertOk()
        ->assertSee('Tableau de bord');
});

// === CRUD LIST PAGES ===

it('shows properties list page', function () {
    $this->actingAs($this->user)
        ->get('/properties')
        ->assertOk()
        ->assertSee('Biens Immobiliers');
});

it('shows property creation form', function () {
    $this->actingAs($this->user)
        ->get('/properties/create')
        ->assertOk()
        ->assertSee('Nom du bien');
});

it('shows incomes list page', function () {
    $this->actingAs($this->user)
        ->get('/incomes')
        ->assertOk()
        ->assertSee('Recettes');
});

it('shows income creation form', function () {
    $this->actingAs($this->user)
        ->get('/incomes/create')
        ->assertOk()
        ->assertSee('Montant loyer');
});

it('shows expenses list page', function () {
    $this->actingAs($this->user)
        ->get('/expenses')
        ->assertOk()
        ->assertSee('Charges');
});

it('shows expense creation form with categories', function () {
    $this->actingAs($this->user)
        ->get('/expenses/create')
        ->assertOk()
        ->assertSee('Catégorie');
});

it('shows loans list page', function () {
    $this->actingAs($this->user)
        ->get('/loans')
        ->assertOk()
        ->assertSee('Emprunts');
});

it('shows loan creation form', function () {
    $this->actingAs($this->user)
        ->get('/loans/create')
        ->assertOk();
});

it('shows property works list page', function () {
    $this->actingAs($this->user)
        ->get('/property-works')
        ->assertOk();
});

it('shows property work creation form', function () {
    $this->actingAs($this->user)
        ->get('/property-works/create')
        ->assertOk();
});

it('shows property work edit page', function () {
    $property = Property::forceCreate([
        'user_id' => $this->user->id,
        'name' => 'Bien test travaux',
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
    $work = PropertyWork::forceCreate([
        'property_id' => $property->id,
        'description' => 'Réfection toiture',
        'amount' => 500000,
        'work_date' => '2024-06-01',
        'duration_years' => 10,
        'is_dedicated' => true,
        'annual_depreciation' => 50000,
    ]);

    $this->actingAs($this->user)
        ->get("/property-works/{$work->id}/edit")
        ->assertOk();
});

it('shows furniture list page', function () {
    $this->actingAs($this->user)
        ->get('/furniture')
        ->assertOk();
});

it('shows furniture creation form', function () {
    $this->actingAs($this->user)
        ->get('/furniture/create')
        ->assertOk();
});

it('shows furniture edit page', function () {
    $property = Property::forceCreate([
        'user_id' => $this->user->id,
        'name' => 'Bien test mobilier',
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
    $furniture = Furniture::forceCreate([
        'property_id' => $property->id,
        'description' => 'Canapé',
        'amount' => 80000,
        'purchase_date' => '2024-01-01',
        'duration_years' => 5,
        'is_dedicated' => true,
        'is_second_hand' => false,
        'annual_depreciation' => 16000,
    ]);

    $this->actingAs($this->user)
        ->get("/furniture/{$furniture->id}/edit")
        ->assertOk();
});

it('shows property components list page', function () {
    $this->actingAs($this->user)
        ->get('/property-components')
        ->assertOk();
});

it('shows property component creation form', function () {
    $this->actingAs($this->user)
        ->get('/property-components/create')
        ->assertOk();
});

it('shows property component edit page', function () {
    $property = Property::forceCreate([
        'user_id' => $this->user->id,
        'name' => 'Bien test composant',
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
    $component = PropertyComponent::forceCreate([
        'property_id' => $property->id,
        'name' => 'Gros œuvre',
        'percentage' => 50,
        'duration_years' => 40,
        'base_amount' => 1000000,
        'annual_depreciation' => 25000,
        'sort_order' => 0,
    ]);

    $this->actingAs($this->user)
        ->get("/property-components/{$component->id}/edit")
        ->assertOk();
});

it('shows fiscal years page', function () {
    $this->actingAs($this->user)
        ->get('/fiscal-years')
        ->assertOk()
        ->assertSee('Exercices Fiscaux');
});

// === CUSTOM PAGES ===

it('shows simulator page', function () {
    $this->actingAs($this->user)
        ->get('/simulator')
        ->assertOk()
        ->assertSee('Simulateur');
});

it('shows projection page', function () {
    $this->actingAs($this->user)
        ->get('/projection')
        ->assertOk()
        ->assertSee('Projection');
});

it('shows import airbnb page', function () {
    $this->actingAs($this->user)
        ->get('/import-airbnb')
        ->assertOk()
        ->assertSee('Import');
});

it('shows system status page', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    $this->actingAs($admin)
        ->get('/system-status')
        ->assertOk()
        ->assertSee('système');
});

it('shows help page', function () {
    $this->actingAs($this->user)
        ->get('/help-page')
        ->assertOk()
        ->assertSee('Guide');
});

it('shows tva declaration page', function () {
    $this->actingAs($this->user)
        ->get('/tva-declaration')
        ->assertOk();
});

it('shows depreciation editor page', function () {
    $this->actingAs($this->user)
        ->get('/depreciation-editor')
        ->assertOk();
});

it('shows mcp tokens page when mcp is enabled for the user', function () {
    config(['mcp.enabled' => true]);
    $this->user->update(['mcp_enabled' => true]);

    $this->actingAs($this->user)
        ->get('/mcp-tokens')
        ->assertOk();
});

it('shows admin update page for an admin', function () {
    Http::fake(['api.github.com/*' => Http::response([], 200)]);

    $admin = User::factory()->create(['is_admin' => true]);
    $this->actingAs($admin)
        ->get('/admin-update')
        ->assertOk();
});

it('shows admin mcp page for an admin', function () {
    config(['mcp.enabled' => true]);

    $admin = User::factory()->create(['is_admin' => true]);
    $this->actingAs($admin)
        ->get('/admin-mcp')
        ->assertOk();
});

// === REGRESSION : ROUTE WILDCARD {propertyId} NON NUMÉRIQUE (issue #1) ===

it('returns 404 for a non-numeric property-works property segment', function () {
    $this->actingAs($this->user)
        ->get('/property-works/abc')
        ->assertNotFound();
});

it('returns 404 for a non-numeric furniture property segment', function () {
    $this->actingAs($this->user)
        ->get('/furniture/abc')
        ->assertNotFound();
});

it('returns 404 for a non-numeric depreciation-editor property segment', function () {
    $this->actingAs($this->user)
        ->get('/depreciation-editor/abc')
        ->assertNotFound();
});

// === DATA ISOLATION ===

it('isolates data between users', function () {
    $otherUser = User::factory()->create();

    $property = Property::forceCreate([
        'user_id' => $otherUser->id,
        'name' => 'Bien autre utilisateur',
        'address' => '1 rue Autre',
        'city' => 'Lyon',
        'postal_code' => '69001',
        'type' => 'apartment',
        'total_area' => 80,
        'rented_area' => 80,
        'acquisition_date' => '2020-01-01',
        'acquisition_price' => 20000000,
        'notary_fees' => 0,
        'land_percentage' => 15,
        'rental_start_date' => '2023-01-01',
        'rental_type' => 'long_term',
        'is_primary_residence' => false,
    ]);

    $this->actingAs($this->user)
        ->get('/properties')
        ->assertOk()
        ->assertDontSee('Bien autre utilisateur');
});

// === EDIT PAGES WITH WIZARD ===

it('shows property edit page with wizard steps', function () {
    $property = Property::forceCreate([
        'user_id' => $this->user->id,
        'name' => 'Test Wizard',
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
        ->get("/properties/{$property->id}/edit")
        ->assertOk()
        ->assertSee('Test Wizard')
        ->assertSee('Surfaces');
});

// === SIMULATOR WITH DATA ===

it('simulator shows results with property data', function () {
    $property = Property::forceCreate([
        'user_id' => $this->user->id,
        'name' => 'Test Sim',
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

    app(DepreciationService::class)->generateDefaultComponents($property);

    Income::create([
        'property_id' => $property->id,
        'income_date' => now()->format('Y') . '-06-15',
        'amount' => 200000,
        'platform_fee' => 6000,
        'tourist_tax' => 0,
        'source' => 'airbnb',
    ]);

    $this->actingAs($this->user)
        ->get('/simulator')
        ->assertOk()
        ->assertSee('CA brut')
        ->assertSee('Résultat');
});

it('simulator shows a closed fiscal year without error', function () {
    $property = Property::forceCreate([
        'user_id' => $this->user->id,
        'name' => 'Test Sim Clos',
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

    app(DepreciationService::class)->generateDefaultComponents($property);

    Income::create([
        'property_id' => $property->id,
        'income_date' => '2024-06-15',
        'amount' => 200000,
        'platform_fee' => 6000,
        'tourist_tax' => 0,
        'source' => 'airbnb',
    ]);

    FiscalYear::forceCreate([
        'user_id' => $this->user->id,
        'year' => 2024,
        'status' => FiscalYear::STATUS_CLOSED,
        'total_income' => 194000,
        'fiscal_result' => 50000,
    ]);

    $this->actingAs($this->user)
        ->get('/simulator?year=2024')
        ->assertOk()
        ->assertSee('CA brut 2024');
});

it('simulator switches to a closed year via livewire without error', function () {
    $property = Property::forceCreate([
        'user_id' => $this->user->id,
        'name' => 'Test Sim Livewire',
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

    app(DepreciationService::class)->generateDefaultComponents($property);

    Income::create([
        'property_id' => $property->id,
        'income_date' => '2024-06-15',
        'amount' => 200000,
        'platform_fee' => 6000,
        'tourist_tax' => 0,
        'source' => 'airbnb',
    ]);

    FiscalYear::forceCreate([
        'user_id' => $this->user->id,
        'year' => 2024,
        'status' => FiscalYear::STATUS_CLOSED,
        'total_income' => 194000,
        'fiscal_result' => 50000,
    ]);

    $this->actingAs($this->user);

    Livewire::test(\App\Filament\Pages\Simulator::class)
        ->set('year', 2024)
        ->assertSet('year', 2024);
});

// === TELEDECLARATION WITH CLOSED YEAR ===

it('teledeclaration shows a closed fiscal year with frozen totals', function () {
    $property = Property::forceCreate([
        'user_id' => $this->user->id,
        'name' => 'Test Teledec',
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

    app(DepreciationService::class)->generateDefaultComponents($property);

    // Totaux figés volontairement distincts des données réelles :
    // la page doit afficher les montants figés, pas un recalcul.
    FiscalYear::forceCreate([
        'user_id' => $this->user->id,
        'year' => 2024,
        'status' => FiscalYear::STATUS_CLOSED,
        'total_income' => 999999,
        'fiscal_result' => 123456,
    ]);

    $this->actingAs($this->user)
        ->get('/teledeclaration?year=2024')
        ->assertOk()
        ->assertSee('2031')
        ->assertSee('9 999,99');
});

// === PROJECTION WITH DATA ===

it('projection shows table with property data', function () {
    $property = Property::forceCreate([
        'user_id' => $this->user->id,
        'name' => 'Test Proj',
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

    app(DepreciationService::class)->generateDefaultComponents($property);

    $this->actingAs($this->user)
        ->get('/projection')
        ->assertOk()
        ->assertSee('Projection')
        ->assertSee('Immeuble');
});
