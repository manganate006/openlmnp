<?php

use App\Models\Property;
use App\Models\User;

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
