<?php

// Carte « Nouveauté » de la page Quoi de neuf (maquette écran 8, variante B) : fermable,
// mémorisée PAR VERSION (`users.whats_new_dismissed_version`) — sans quoi, une fois fermée,
// elle resterait masquée pour toujours, y compris à la prochaine version qui aurait quelque
// chose à annoncer. Même patron que `onboarding_dismissed_at`.

use App\Filament\Pages\WhatsNew;
use App\Models\User;
use App\Services\UpdateService;
use Livewire\Livewire;

function userForNoveltyCard(): User
{
    $user = User::factory()->create();
    $user->update(['is_admin' => false]);

    return $user->refresh();
}

it('shows the novelty card to a user who has never dismissed it', function () {
    $this->actingAs(userForNoveltyCard());

    Livewire::test(WhatsNew::class)
        ->assertOk()
        ->assertSee('Indivision : une liasse, une quote-part par indivisaire')
        ->assertSee('Nouveau en '.(new UpdateService())->getCurrentVersion());
});

it('lets the user dismiss the novelty card, and remembers it for the current version', function () {
    $user = userForNoveltyCard();
    $this->actingAs($user);

    Livewire::test(WhatsNew::class)
        ->assertSee('Indivision : une liasse, une quote-part par indivisaire')
        ->call('dismissNoveltyCard')
        ->assertDontSee('Indivision : une liasse, une quote-part par indivisaire');

    expect($user->fresh()->whats_new_dismissed_version)->toBe((new UpdateService())->getCurrentVersion());

    // Une relecture du même écran ne la ramène pas : le choix a été mémorisé en base, pas
    // seulement dans l'état Livewire de cette instance de composant.
    Livewire::test(WhatsNew::class)->assertDontSee('Indivision : une liasse, une quote-part par indivisaire');
});

it('brings the novelty card back once a newer version has not been dismissed yet', function () {
    $user = userForNoveltyCard();
    $user->update(['whats_new_dismissed_version' => 'v0.0.1-jamais-publiee']);
    $this->actingAs($user);

    Livewire::test(WhatsNew::class)
        ->assertSee('Indivision : une liasse, une quote-part par indivisaire');
});

it('shows the linked-dossiers bullet and its call to action when the feature is enabled', function () {
    config(['dossiers.enabled' => true]);
    $this->actingAs(userForNoveltyCard());

    Livewire::test(WhatsNew::class)
        ->assertSee('Dossiers liés.', false)
        ->assertSee('Créer un dossier lié')
        ->assertDontSee('LINKED_DOSSIERS_ENABLED');
});

it('replaces the linked-dossiers bullet with a profile call to action when the feature is disabled', function () {
    config(['dossiers.enabled' => false]);
    $this->actingAs(userForNoveltyCard());

    Livewire::test(WhatsNew::class)
        ->assertDontSee('Dossiers liés.', false)
        ->assertDontSee('Créer un dossier lié')
        ->assertSee('Ouvrir la rubrique Indivision')
        ->assertSee('LINKED_DOSSIERS_ENABLED');
});
