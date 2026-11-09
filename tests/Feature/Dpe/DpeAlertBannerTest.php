<?php

use App\Filament\Widgets\AlertBanner;
use App\Models\Property;
use App\Models\User;
use Livewire\Livewire;

/**
 * Alerte d'expiration du DPE sur le tableau de bord — branchée sur `AlertBanner`, le
 * mécanisme d'alertes déjà existant plutôt qu'un nouveau (voir TODO-OPENDATA-DPE.md).
 *
 * ⚠️ Les widgets Filament sont chargés paresseusement (`wire:init`) : un simple
 * `get('/')->assertSee(...)` ne voit jamais leur contenu, seule une coquille vide. Il faut
 * monter le widget lui-même, comme `FilamentPagesTest` le fait déjà pour les autres widgets.
 */
function proprietaireAvecBien(array $overrides = []): array
{
    $user = User::factory()->create(['onboarding_dismissed_at' => now()]);

    $property = Property::create(array_merge([
        'user_id' => $user->id,
        'name' => 'Villa Alertée',
        'address' => '1 rue de la République',
        'city' => 'Lyon',
        'postal_code' => '69003',
        'type' => 'apartment',
        'total_area' => 50,
        'rented_area' => 50,
        'acquisition_date' => '2022-01-01',
        'acquisition_price' => 20_000_000,
        'land_percentage' => 15,
        'rental_start_date' => '2022-03-01',
        'rental_type' => 'seasonal',
        'is_primary_residence' => false,
    ], $overrides));

    return [$user, $property];
}

it('warns on the dashboard about an expired DPE', function () {
    [$user, $property] = proprietaireAvecBien(['dpe_valid_until' => now()->subYear()->toDateString()]);

    Livewire::actingAs($user)
        ->test(AlertBanner::class)
        ->assertSee('DPE expiré — Villa Alertée');
});

it('warns on the dashboard about a DPE expiring within six months', function () {
    [$user, $property] = proprietaireAvecBien(['dpe_valid_until' => now()->addMonths(2)->toDateString()]);

    Livewire::actingAs($user)
        ->test(AlertBanner::class)
        ->assertSee('DPE bientôt expiré — Villa Alertée');
});

it('says nothing when the DPE is valid for a long time', function () {
    [$user, $property] = proprietaireAvecBien(['dpe_valid_until' => now()->addYears(5)->toDateString()]);

    Livewire::actingAs($user)
        ->test(AlertBanner::class)
        ->assertDontSee('DPE expiré')
        ->assertDontSee('DPE bientôt expiré');
});

it('says nothing without a DPE', function () {
    [$user, $property] = proprietaireAvecBien();

    Livewire::actingAs($user)
        ->test(AlertBanner::class)
        ->assertDontSee('DPE expiré')
        ->assertDontSee('DPE bientôt expiré');
});
