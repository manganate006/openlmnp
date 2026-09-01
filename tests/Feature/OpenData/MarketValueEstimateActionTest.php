<?php

use App\Filament\Resources\Properties\Pages\CreateProperty;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;

/**
 * L'action « Estimer (DVF) » du formulaire d'un bien.
 *
 * Les composants Filament ne se rendent pas sur un simple `get('/properties/create')` : sans
 * un test Livewire qui monte réellement l'action, une modale cassée passerait inaperçue.
 */
beforeEach(function () {
    $this->user = User::factory()->create();
    Cache::flush();
    RateLimiter::clear('dvf:'.$this->user->id);
    File::deleteDirectory(storage_path('app/private/dvf'));
});

function fakeDvfForForm(): void
{
    $header = 'id_mutation,date_mutation,nature_mutation,valeur_fonciere,code_postal,code_commune,nom_commune,code_departement,id_parcelle,nombre_lots,code_type_local,type_local,surface_reelle_bati,nombre_pieces_principales,surface_terrain';
    $lines = [$header];

    foreach ([200000, 220000, 250000, 260000, 300000] as $i => $price) {
        $lines[] = "A{$i},2025-03-14,Vente,{$price}.00,33000,33063,Bordeaux,33,33063000A000{$i},1,2,Appartement,50,3,";
    }

    Http::fake([
        'geo.api.gouv.fr/*' => Http::response([[
            'nom' => 'Bordeaux',
            'code' => '33063',
            'codesPostaux' => ['33000'],
            'departement' => ['nom' => 'Gironde'],
        ]]),
        'files.data.gouv.fr/*' => Http::response(implode("\n", $lines)."\n"),
    ]);
}

it('fills the market value from the DVF estimate', function () {
    fakeDvfForForm();
    $this->actingAs($this->user);

    Livewire::test(CreateProperty::class)
        ->fillForm([
            'name' => 'Appartement Bordeaux',
            'city' => 'Bordeaux',
            'postal_code' => '33000',
            'type' => 'apartment',
            'total_area' => 40,
        ])
        ->mountAction(TestAction::make('estimateMarketValue')->schemaComponent('market_value'))
        ->setActionData(['insee' => '33063', 'year' => 2025, 'area' => 40, 'property_type' => 'apartment'])
        ->callMountedAction()
        ->assertHasNoErrors()
        // Médiane 5 000 €/m² × 40 m² = 200 000 €, en EUROS dans le formulaire.
        ->assertFormSet(['market_value' => '200000', 'insee_code' => '33063']);
});

it('leaves the value untouched when the sample is too thin', function () {
    $header = 'id_mutation,date_mutation,nature_mutation,valeur_fonciere,code_postal,code_commune,nom_commune,code_departement,id_parcelle,nombre_lots,code_type_local,type_local,surface_reelle_bati,nombre_pieces_principales,surface_terrain';
    Http::fake([
        'geo.api.gouv.fr/*' => Http::response([[
            'nom' => 'Bordeaux', 'code' => '33063', 'codesPostaux' => ['33000'], 'departement' => ['nom' => 'Gironde'],
        ]]),
        'files.data.gouv.fr/*' => Http::response($header."\nA1,2025-03-14,Vente,200000.00,33000,33063,Bordeaux,33,33063000A0001,1,2,Appartement,50,3,\n"),
    ]);
    $this->actingAs($this->user);

    Livewire::test(CreateProperty::class)
        ->fillForm(['city' => 'Bordeaux', 'postal_code' => '33000', 'type' => 'apartment', 'total_area' => 40])
        ->mountAction(TestAction::make('estimateMarketValue')->schemaComponent('market_value'))
        ->setActionData(['insee' => '33063', 'year' => 2025, 'area' => 40, 'property_type' => 'apartment'])
        ->callMountedAction()
        ->assertFormSet(fn (array $state) => blank($state['market_value'] ?? null));
});

it('makes no outbound call when the property form is merely opened', function () {
    // La commune du bien ne doit partir chez data.gouv.fr que sur une action explicite.
    Http::fake();
    $this->actingAs($this->user);

    Livewire::test(CreateProperty::class)->assertOk();

    Http::assertNothingSent();
});

it('removes the action entirely when DVF is disabled', function () {
    // `visible(false)` ne masque pas l'action : Filament la retire du schéma. C'est bien ce
    // qu'on veut ici — pas de bouton, donc aucun appel sortant possible.
    config(['opendata.dvf.enabled' => false]);
    $this->actingAs($this->user);

    Livewire::test(CreateProperty::class)
        ->assertActionDoesNotExist(TestAction::make('estimateMarketValue')->schemaComponent('market_value'));
});
