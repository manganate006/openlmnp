<?php

use App\Filament\Pages\DepreciationEditor;
use App\Models\Property;
use App\Models\PropertyComponent;
use App\Models\User;
use App\Services\DepreciationService;
use Livewire\Livewire;

/**
 * Reprise d'un plan de cabinet depuis l'éditeur d'amortissements (lot 4).
 *
 * La grille était bâtie sur `DepreciationService::FULL_CATALOG` : un composant hors
 * catalogue s'AFFICHAIT (s'il existait déjà en base) mais ne se CRÉAIT pas. Un plan
 * comportant « Ascenseur » ou « Menuiseries extérieures » était donc irreproductible,
 * et rien à l'écran ne le disait.
 */
function repriseEditorProperty(User $user): Property
{
    return Property::forceCreate([
        'user_id' => $user->id,
        'name' => 'Bien reprise éditeur',
        'address' => '1 rue Test',
        'city' => 'Paris',
        'postal_code' => '75001',
        'type' => 'apartment',
        'total_area' => 100,
        'rented_area' => 100,
        'acquisition_date' => '2020-01-01',
        'acquisition_price' => 25000000,
        'notary_fees' => 0,
        'land_percentage' => 20, // base amortissable = 200 000 €
        'rental_start_date' => '2023-01-01',
        'rental_type' => 'seasonal',
        'is_primary_residence' => false,
    ]);
}

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->property = repriseEditorProperty($this->user);
    app(DepreciationService::class)->generateDefaultComponents($this->property);
});

it('creates a free-name component that is absent from the catalogue', function () {
    Livewire::actingAs($this->user)
        ->test(DepreciationEditor::class, ['propertyId' => $this->property->id])
        ->call('saveComponents', [[
            'id' => null,
            'name' => 'Ascenseur',
            'percentage' => 0,
            'baseAmount' => 1500000,
            'baseSource' => PropertyComponent::BASE_SOURCE_MANUAL,
            'annualDepreciation' => 75000,
            'duration' => 20,
            'sortOrder' => 20,
            'enabled' => true,
            'cerfaCategory' => PropertyComponent::CERFA_CATEGORY_INSTALLATIONS,
            'startDate' => '2024-03-15',
            'openingCumul' => 300000,
        ]]);

    $component = PropertyComponent::withoutGlobalScopes()
        ->where('property_id', $this->property->id)
        ->where('name', 'Ascenseur')
        ->first();

    expect($component)->not->toBeNull()
        ->and((int) $component->base_amount)->toBe(1500000)
        ->and((int) $component->annual_depreciation)->toBe(75000)
        ->and($component->cerfa_category)->toBe(PropertyComponent::CERFA_CATEGORY_INSTALLATIONS)
        ->and($component->depreciation_start_date->format('Y-m-d'))->toBe('2024-03-15')
        ->and((int) $component->opening_accumulated_depreciation)->toBe(300000);
});

it('refuses to create a component without a name', function () {
    Livewire::actingAs($this->user)
        ->test(DepreciationEditor::class, ['propertyId' => $this->property->id])
        ->call('saveComponents', [[
            'id' => null,
            'name' => '   ',
            'percentage' => 0,
            'baseAmount' => 500000,
            'baseSource' => PropertyComponent::BASE_SOURCE_MANUAL,
            'duration' => 10,
            'sortOrder' => 30,
            'enabled' => true,
        ]]);

    // Rien d'anonyme n'entre en base — ni sous un nom vide, ni sous un nom d'emprunt.
    expect(PropertyComponent::withoutGlobalScopes()->where('property_id', $this->property->id)->count())
        ->toBe(0);
});

it('exposes the Cerfa categories and the rental start date to the editor', function () {
    $data = Livewire::actingAs($this->user)
        ->test(DepreciationEditor::class, ['propertyId' => $this->property->id])
        ->instance()
        ->editorData();

    expect($data['cerfaCategories'])->toHaveKeys([
        PropertyComponent::CERFA_CATEGORY_CONSTRUCTIONS,
        PropertyComponent::CERFA_CATEGORY_INSTALLATIONS,
        PropertyComponent::CERFA_CATEGORY_FITTINGS,
        PropertyComponent::CERFA_CATEGORY_OTHER,
    ])->and($data['rentalStartDate'])->toBe('2023-01-01');

    $roof = collect($data['components'])->firstWhere('name', 'Toiture');
    expect($roof['cerfaCategory'])->toBe(PropertyComponent::CERFA_CATEGORY_CONSTRUCTIONS)
        ->and($roof['startDate'])->toBeNull()
        ->and($roof['openingCumul'])->toBe(0);
});

it('clears a component start date when the field is emptied', function () {
    $roof = $this->property->components()->where('name', 'Toiture')->firstOrFail();
    $roof->forceFill(['depreciation_start_date' => '2024-01-01'])->save();

    Livewire::actingAs($this->user)
        ->test(DepreciationEditor::class, ['propertyId' => $this->property->id])
        ->call('saveComponents', [[
            'id' => $roof->id,
            'name' => 'Toiture',
            'percentage' => (float) $roof->percentage,
            'baseAmount' => (int) $roof->base_amount,
            'baseSource' => $roof->base_source,
            'duration' => $roof->duration_years,
            'sortOrder' => $roof->sort_order,
            'enabled' => true,
            'cerfaCategory' => $roof->cerfa_category,
            'startDate' => '',
            'openingCumul' => 0,
        ]]);

    // Vider le champ remet le composant sur la date du bien : c'est le seul moyen de
    // revenir en arrière une fois qu'une date propre a été saisie.
    expect($roof->fresh()->depreciation_start_date)->toBeNull();
});

it('leaves the reprise columns untouched when the sliders save a ventilation', function () {
    $roof = $this->property->components()->where('name', 'Toiture')->firstOrFail();
    $roof->forceFill([
        'depreciation_start_date' => '2024-01-01',
        'cerfa_category' => PropertyComponent::CERFA_CATEGORY_FITTINGS,
        'opening_accumulated_depreciation' => 123400,
    ])->save();

    // Charge utile de l'onglet Ventilation : elle ne porte AUCUNE des trois colonnes.
    Livewire::actingAs($this->user)
        ->test(DepreciationEditor::class, ['propertyId' => $this->property->id])
        ->call('saveComponents', [[
            'id' => $roof->id,
            'name' => 'Toiture',
            'percentage' => 12.0,
            'baseAmount' => 0,
            'baseSource' => PropertyComponent::BASE_SOURCE_PERCENTAGE,
            'duration' => 25,
            'sortOrder' => 2,
            'enabled' => true,
        ]]);

    $fresh = $roof->fresh();

    expect($fresh->depreciation_start_date->format('Y-m-d'))->toBe('2024-01-01')
        ->and($fresh->cerfa_category)->toBe(PropertyComponent::CERFA_CATEGORY_FITTINGS)
        ->and((int) $fresh->opening_accumulated_depreciation)->toBe(123400)
        // … et la ventilation, elle, a bien été appliquée.
        ->and((int) $fresh->base_amount)->toBe(2400000);
});

// ─────────────────────────────────────────────────────────────────────
// Le remplacement est VOULU, son silence ne l'était pas (lot ergonomie, 2026-09-24)
// ─────────────────────────────────────────────────────────────────────

/**
 * La dernière notification réellement envoyée.
 *
 * ⚠️ `assertNotified()` ne compare qu'un titre ou une notification entière : ici le titre
 * ne bouge pas, c'est le CORPS qui doit nommer les suppressions. On relit donc la même
 * source que Filament, le composant `Notifications` alimenté par la session.
 *
 * ⚠️ Son `mount()` fait un `session()->pull()` : il CONSOMME les notifications. À n'appeler
 * qu'une fois par test, et à garder dans une variable.
 */
function derniereNotificationEditeur(): ?\Filament\Notifications\Notification
{
    $component = new \Filament\Notifications\Livewire\Notifications;
    $component->mount();

    return $component->notifications->last();
}

/**
 * `syncComponents()` est une RÉCONCILIATION : tout composant absent du lot soumis est
 * supprimé. Le remplacement est voulu — c'est ce qui permet de recopier le plan d'un
 * cabinet par-dessus la ventilation par défaut — mais il se faisait sans un mot, alors même
 * que le service rend un compteur `deleted` que personne ne lisait.
 *
 * Arrivé en vrai le 2026-09-24 : 6 composants ramenés à 1, sans aucun avertissement.
 */
it('nomme et compte les composants que l\'enregistrement supprime', function () {
    $gros = PropertyComponent::withoutGlobalScopes()
        ->where('property_id', $this->property->id)
        ->where('name', 'Gros œuvre')
        ->firstOrFail();

    expect(PropertyComponent::withoutGlobalScopes()->where('property_id', $this->property->id)->count())->toBe(6);

    Livewire::actingAs($this->user)
        ->test(DepreciationEditor::class, ['propertyId' => $this->property->id])
        // Un seul composant soumis : les cinq autres disparaissent.
        ->call('saveComponents', [[
            'id' => $gros->id,
            'name' => 'Gros œuvre',
            'percentage' => 50,
            'baseAmount' => 0,
            'baseSource' => PropertyComponent::BASE_SOURCE_PERCENTAGE,
            'duration' => 50,
            'sortOrder' => 0,
            'enabled' => true,
            'cerfaCategory' => PropertyComponent::CERFA_CATEGORY_CONSTRUCTIONS,
        ]]);

    $restants = PropertyComponent::withoutGlobalScopes()
        ->where('property_id', $this->property->id)
        ->pluck('name');

    expect($restants)->toHaveCount(1);

    $notification = derniereNotificationEditeur();
    $corps = (string) $notification?->getBody();

    // Comptés…
    expect($corps)->toContain('5 composants supprimés');

    // …et NOMMÉS : un compteur seul laisserait deviner lesquels.
    foreach (['Toiture', 'Installations électriques', 'Étanchéité'] as $disparu) {
        expect($corps)->toContain($disparu);
    }
});

it('ne parle de suppression que lorsqu\'il y en a une', function () {
    $lignes = PropertyComponent::withoutGlobalScopes()
        ->where('property_id', $this->property->id)
        ->get()
        ->map(fn (PropertyComponent $c) => [
            'id' => $c->id,
            'name' => $c->name,
            'percentage' => (float) $c->percentage,
            'baseAmount' => (int) $c->base_amount,
            'baseSource' => $c->base_source,
            'duration' => $c->duration_years,
            'sortOrder' => $c->sort_order,
            'enabled' => true,
            'cerfaCategory' => $c->cerfaCategory(),
        ])
        ->all();

    Livewire::actingAs($this->user)
        ->test(DepreciationEditor::class, ['propertyId' => $this->property->id])
        ->call('saveComponents', $lignes);

    $corps = (string) derniereNotificationEditeur()?->getBody();

    expect($corps)->not->toContain('supprimé');

    expect(PropertyComponent::withoutGlobalScopes()->where('property_id', $this->property->id)->count())->toBe(6);
});

/**
 * Le bouton flottait seul dans une carte à lui. Il ferme désormais la carte du mode
 * courant — celle du tableau en « Montants », celle du bas de colonne en « Ventilation » —
 * et il y côtoie l'avertissement de ce qu'il va supprimer.
 */
it('rattache le bouton d\'enregistrement au bloc du mode courant, avec ce qu\'il va supprimer', function () {
    $html = Livewire::actingAs($this->user)
        ->test(DepreciationEditor::class, ['propertyId' => $this->property->id])
        ->html();

    // Plus de carte dédiée au seul bouton.
    expect($html)->not->toContain('de-card de-actions');

    // Le pied est présent dans les DEUX modes : un bloc unique ne pourrait fermer
    // qu'une seule des deux cartes.
    expect(substr_count($html, 'class="de-editor-foot"'))->toBe(2);

    // Et il annonce la suppression avant le clic, nommée et comptée.
    expect($html)
        ->toContain('de-doomed')
        ->toContain('doomedHeadline()')
        ->toContain('pendingDeletions().join')
        ->toContain('<strong>remplace</strong> votre plan d\'amortissement');
});

/**
 * Les lignes du catalogue s'affichaient à « 0,00 » exactement comme un composant réel :
 * rien ne disait qu'elles n'existent pas encore, et c'est ce qui rendait le remplacement
 * invisible.
 */
it('distingue les lignes de gabarit des composants réellement enregistrés', function () {
    $html = Livewire::actingAs($this->user)
        ->test(DepreciationEditor::class, ['propertyId' => $this->property->id])
        ->html();

    // ⚠️ Assertions ancrées sur le MARKUP, jamais sur `.de-template-mark` ni
    // `isTemplateRow(comp)` nus : la feuille de style et le composant Alpine les
    // contiennent déjà, et le test passerait sur un tableau qui ne marque rien.
    expect($html)
        ->toContain('x-show="isTemplateRow(comp)"')
        ->toContain('class="de-template-mark"')
        // WCAG 1.4.1 : la couleur ne porte pas l'information toute seule.
        ->toContain('Ligne de gabarit, pas encore enregistrée')
        ->toContain('lignes de gabarit');
});

it('reads a start date typed as jj/mm/aaaa the French way, not the American one', function (string $typed, ?string $stored) {
    $roof = $this->property->components()->where('name', 'Toiture')->firstOrFail();
    $roof->forceFill(['depreciation_start_date' => '2024-01-01'])->save();

    Livewire::actingAs($this->user)
        ->test(DepreciationEditor::class, ['propertyId' => $this->property->id])
        ->call('saveComponents', [[
            'id' => $roof->id,
            'name' => 'Toiture',
            'percentage' => (float) $roof->percentage,
            'baseAmount' => (int) $roof->base_amount,
            'baseSource' => $roof->base_source,
            'duration' => $roof->duration_years,
            'sortOrder' => $roof->sort_order,
            'enabled' => true,
            'cerfaCategory' => $roof->cerfa_category,
            'startDate' => $typed,
            'openingCumul' => 0,
        ]]);

    // `Carbon::parse('01/06/2019')` rend le 6 JANVIER : strtotime lit les barres
    // obliques à l'américaine. Une saisie illisible ne touche pas à la date en base.
    expect($roof->fresh()->depreciation_start_date?->format('Y-m-d'))->toBe($stored);
})->with([
    'jj/mm/aaaa'          => ['01/06/2019', '2019-06-01'],
    'ISO du navigateur'   => ['2019-06-01', '2019-06-01'],
    'jour impossible'     => ['31/02/2019', '2024-01-01'],
    'texte'               => ['bientôt', '2024-01-01'],
]);
