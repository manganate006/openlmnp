<?php

use App\Models\FiscalYear;
use App\Models\Property;
use App\Models\User;

/**
 * La correction du 2033-D change des liasses DÉJÀ générées : elle ne peut pas être silencieuse.
 * L'encart n'apparaît que pour ceux qui ont déjà téléchargé ou transmis une liasse.
 */
beforeEach(function () {
    $this->user = User::factory()->create();

    Property::forceCreate([
        'user_id' => $this->user->id,
        'name' => 'Bien',
        'address' => '1 rue Test',
        'city' => 'Paris',
        'postal_code' => '75001',
        'type' => 'apartment',
        'total_area' => 40,
        'rented_area' => 40,
        'acquisition_date' => '2020-01-01',
        'acquisition_price' => 10000000,
        'notary_fees' => 0,
        'agency_fees' => 0,
        'market_value' => null,
        'land_percentage' => 0,
        'rental_start_date' => '2020-06-01',
        'rental_type' => 'seasonal',
        'is_primary_residence' => false,
    ]);
});

it('warns about the 2033-D correction when a tax return was already generated', function () {
    FiscalYear::forceCreate([
        'user_id' => $this->user->id,
        'year' => 2025,
        'status' => FiscalYear::STATUS_CLOSED,
        'pdf_path' => 'tax-returns/2025/liasse_fiscale_2025.pdf',
    ]);

    $this->actingAs($this->user)
        ->get('/teledeclaration')
        ->assertOk()
        ->assertSee('Le tableau 2033-D a changé de règle')
        ->assertSee('openlmnp:repair-deficits');
});

it('stays quiet for a user who never generated a tax return', function () {
    $this->actingAs($this->user)
        ->get('/teledeclaration')
        ->assertOk()
        ->assertDontSee('Le tableau 2033-D a changé de règle');
});
