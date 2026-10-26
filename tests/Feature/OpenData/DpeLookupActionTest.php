<?php

use App\Filament\Resources\Properties\Pages\CreateProperty;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;

/**
 * L'action « Retrouver le DPE » du formulaire d'un bien.
 *
 * Même patron que `MarketValueEstimateActionTest` : un composant Filament ne se rend pas
 * sur un simple `get('/properties/create')`, un test Livewire est nécessaire.
 */
beforeEach(function () {
    $this->user = User::factory()->create();
    RateLimiter::clear('dpe:'.$this->user->id);
});

function fakeDpeForForm(): void
{
    Http::fake([
        'data.geopf.fr/geocodage/*' => Http::response([
            'features' => [['properties' => ['id' => '33063_0221_00021']]],
        ]),
        'data.ademe.fr/*' => Http::response(['results' => [[
            'numero_dpe' => '2521E1234567A',
            'etiquette_dpe' => 'd',
            'date_etablissement_dpe' => '2025-01-15',
            'date_fin_validite_dpe' => '2035-01-15',
            'numero_dpe_remplace' => null,
            'type_batiment' => 'appartement',
            'numero_etage_appartement' => 2,
            'complement_adresse_logement' => null,
            'surface_habitable_logement' => 45,
        ]]]),
    ]);
}

it('fills the DPE fields from the chosen result', function () {
    fakeDpeForForm();
    $this->actingAs($this->user);

    Livewire::test(CreateProperty::class)
        ->fillForm([
            'name' => 'Appartement Bordeaux',
            'address' => '1 rue de la République',
            'city' => 'Bordeaux',
            'postal_code' => '33000',
        ])
        ->mountAction(TestAction::make('lookupDpe')->schemaComponent('dpe_number'))
        ->setActionData([
            'address' => '1 rue de la République',
            'postal_code' => '33000',
            'city' => 'Bordeaux',
            'insee_code' => '',
            'number' => '',
            'choice' => '2521E1234567A',
        ])
        ->callMountedAction()
        ->assertHasNoErrors()
        ->assertFormSet([
            'dpe_number' => '2521E1234567A',
            'dpe_class' => 'D',
            'dpe_established_at' => '2025-01-15',
            'dpe_valid_until' => '2035-01-15',
        ]);
});

it('finds a DPE by number without geocoding the address', function () {
    Http::fake([
        'data.ademe.fr/*' => Http::response(['results' => [[
            'numero_dpe' => '2521E9999999Z',
            'etiquette_dpe' => 'B',
            'date_etablissement_dpe' => '2024-05-01',
            'date_fin_validite_dpe' => '2034-05-01',
            'numero_dpe_remplace' => null,
            'type_batiment' => 'maison',
            'numero_etage_appartement' => 0,
            'complement_adresse_logement' => null,
            'surface_habitable_logement' => 90,
        ]]]),
    ]);
    $this->actingAs($this->user);

    Livewire::test(CreateProperty::class)
        ->fillForm(['name' => 'Maison', 'address' => 'adresse introuvable', 'city' => '', 'postal_code' => ''])
        ->mountAction(TestAction::make('lookupDpe')->schemaComponent('dpe_number'))
        ->setActionData([
            'address' => 'adresse introuvable', 'postal_code' => '', 'city' => '', 'insee_code' => '',
            'number' => '2521E9999999Z', 'choice' => '2521E9999999Z',
        ])
        ->callMountedAction()
        ->assertHasNoErrors()
        ->assertFormSet(['dpe_class' => 'B']);

    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'geocodage'));
});

it('writes nothing and warns when no line was chosen', function () {
    fakeDpeForForm();
    $this->actingAs($this->user);

    Livewire::test(CreateProperty::class)
        ->fillForm(['name' => 'Appartement', 'address' => '1 rue de la République', 'city' => 'Bordeaux', 'postal_code' => '33000'])
        ->mountAction(TestAction::make('lookupDpe')->schemaComponent('dpe_number'))
        ->setActionData([
            'address' => '1 rue de la République', 'postal_code' => '33000', 'city' => 'Bordeaux',
            'insee_code' => '', 'number' => '', 'choice' => null,
        ])
        ->callMountedAction()
        ->assertNotified('Aucun DPE choisi')
        ->assertFormSet(fn (array $state) => blank($state['dpe_class'] ?? null));
});

it('makes no outbound call when the property form is merely opened', function () {
    Http::fake();
    $this->actingAs($this->user);

    Livewire::test(CreateProperty::class)->assertOk();

    Http::assertNothingSent();
});

it('removes the action entirely when DPE is disabled', function () {
    config(['opendata.dpe.enabled' => false]);
    $this->actingAs($this->user);

    Livewire::test(CreateProperty::class)
        ->assertActionDoesNotExist(TestAction::make('lookupDpe')->schemaComponent('dpe_number'));
});
