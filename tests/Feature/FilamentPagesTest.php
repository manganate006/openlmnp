<?php

use App\Models\Expense;
use App\Models\FiscalYear;
use App\Models\Income;
use App\Models\Loan;
use App\Models\Property;
use App\Models\User;
use App\Services\DepreciationService;

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
    $this->actingAs($this->user)
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

// === EDIT PAGES WITH RELATION MANAGERS ===

it('shows property edit page with relation manager tabs', function () {
    $property = Property::forceCreate([
        'user_id' => $this->user->id,
        'name' => 'Test RM',
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
        ->assertSee('Test RM')
        ->assertSee('Composants')
        ->assertSee('Travaux')
        ->assertSee('Mobilier');
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
