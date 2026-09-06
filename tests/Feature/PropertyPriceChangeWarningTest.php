<?php

use App\Filament\Resources\Properties\Pages\EditProperty;
use App\Models\Property;
use App\Models\PropertyComponent;
use App\Models\User;
use App\Services\DepreciationService;
use Filament\Notifications\Livewire\Notifications;
use Filament\Notifications\Notification;
use Livewire\Livewire;

/**
 * L'alerte se pose AU MOMENT où le prix change (issue #11 de cocool97).
 *
 * Un montant d'amortissement saisi en euros ne suit pas la valeur du bien — c'est sa raison
 * d'être. Mais alors la somme peut dépasser la base, et jusqu'ici l'utilisateur ne le
 * découvrait qu'à la génération de sa liasse, des semaines après le geste qui l'avait créé.
 *
 * ⚠️ Un bien entièrement ventilé en pourcentage ne doit produire AUCUN message : ses montants
 * ont suivi, il n'y a rien à signaler. Avertir à chaque changement de prix apprendrait à
 * ignorer l'avertissement, ce qui reviendrait à ne pas l'avoir.
 */
beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

function bienAvecComposants(array $lignes, int $prix = 10_350_000): Property
{
    $property = Property::create([
        'user_id' => auth()->id(), 'name' => 'Villa', 'address' => '1 rue du Test',
        'city' => 'Lyon', 'postal_code' => '69003', 'type' => 'apartment',
        'total_area' => 45, 'rented_area' => 45, 'acquisition_date' => '2022-01-01',
        'acquisition_price' => $prix, 'land_percentage' => 15,
        'rental_start_date' => '2022-03-01', 'rental_type' => 'seasonal',
        'is_primary_residence' => false,
    ]);

    app(DepreciationService::class)->syncComponents($property, $lignes);

    return $property->fresh();
}

/** ⚠️ `mount()` CONSOMME les notifications : ne l'appeler qu'une fois par test. */
function derniereNotification(): ?Notification
{
    $c = new Notifications;
    $c->mount();

    return $c->notifications->last();
}

it('avertit quand un montant saisi dépasse la nouvelle base', function () {
    $property = bienAvecComposants([
        ['name' => 'Recopié du cabinet', 'duration_years' => 40, 'sort_order' => 0,
            'base_source' => PropertyComponent::BASE_SOURCE_MANUAL, 'base_amount' => 8_000_000],
    ]);

    Livewire::test(EditProperty::class, ['record' => $property->getRouteKey()])
        ->fillForm(['acquisition_price' => 50_000])
        ->call('save')
        ->assertHasNoFormErrors();

    $notification = derniereNotification();

    expect($notification?->getTitle())->toContain('dépassent la nouvelle base')
        ->and(array_map(
            fn ($a) => (string) $a->getLabel(),
            $notification?->getActions() ?? [],
        ))->toContain('Ajuster les amortissements');
});

it('ne dit rien quand tout est ventilé en pourcentage', function () {
    // Les montants ont suivi la baisse : il n'y a rien à signaler.
    $property = bienAvecComposants([
        ['name' => 'Gros œuvre', 'duration_years' => 40, 'sort_order' => 0, 'percentage' => 100],
    ]);

    Livewire::test(EditProperty::class, ['record' => $property->getRouteKey()])
        ->fillForm(['acquisition_price' => 50_000])
        ->call('save')
        ->assertHasNoFormErrors();

    expect((string) derniereNotification()?->getTitle())
        ->not->toContain('dépassent la nouvelle base');
});

it('ne dit rien quand le prix MONTE', function () {
    $property = bienAvecComposants([
        ['name' => 'Recopié du cabinet', 'duration_years' => 40, 'sort_order' => 0,
            'base_source' => PropertyComponent::BASE_SOURCE_MANUAL, 'base_amount' => 8_000_000],
    ]);

    Livewire::test(EditProperty::class, ['record' => $property->getRouteKey()])
        ->fillForm(['acquisition_price' => 20_000_000])
        ->call('save')
        ->assertHasNoFormErrors();

    expect((string) derniereNotification()?->getTitle())
        ->not->toContain('dépassent la nouvelle base');
});
