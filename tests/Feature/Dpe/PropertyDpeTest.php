<?php

use App\Models\Property;
use App\Models\User;
use App\Services\DepreciationService;
use Illuminate\Support\Carbon;

/** Bien minimal pour les tests DPE, colonnes réellement présentes en base. */
function bienPourDpe(array $overrides = []): Property
{
    return Property::create(array_merge([
        'user_id' => auth()->id(),
        'name' => 'Bien DPE',
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
}

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

afterEach(function () {
    Carbon::setTestNow();
});

// === Verdicts selon la configuration de location ===

it('computes the tourism verdict for a seasonal rental', function () {
    Carbon::setTestNow('2026-10-13');
    $property = bienPourDpe(['rental_type' => 'seasonal', 'dpe_class' => 'D']);

    expect($property->dpeVerdicts())->toHaveKey('tourisme')
        ->not->toHaveKey('bail_meuble');
});

it('computes the furnished lease verdict for a long-term rental', function () {
    Carbon::setTestNow('2026-10-13');
    $property = bienPourDpe(['rental_type' => 'long_term', 'dpe_class' => 'F']);

    expect($property->dpeVerdicts())->toHaveKey('bail_meuble')
        ->not->toHaveKey('tourisme');
});

it('has no verdict without a DPE class', function () {
    $property = bienPourDpe();

    expect($property->dpeVerdicts())->toBe([]);
});

it('detects overseas from its own postal code', function () {
    $property = bienPourDpe(['postal_code' => '97400']);

    expect($property->dpeOverseas())->toBeTrue();
});

// === Expiration ===

it('flags an expired DPE', function () {
    Carbon::setTestNow('2026-10-13');
    $property = bienPourDpe(['dpe_valid_until' => '2020-01-01']);

    expect($property->dpeExpired())->toBeTrue()
        ->and($property->dpeExpiringSoon())->toBeFalse();
});

it('flags a DPE expiring within six months', function () {
    Carbon::setTestNow('2026-10-13');
    $property = bienPourDpe(['dpe_valid_until' => '2027-01-01']);

    expect($property->dpeExpiringSoon())->toBeTrue()
        ->and($property->dpeExpired())->toBeFalse();
});

it('flags neither when the DPE is valid for a long time', function () {
    Carbon::setTestNow('2026-10-13');
    $property = bienPourDpe(['dpe_valid_until' => '2035-01-01']);

    expect($property->dpeExpired())->toBeFalse()
        ->and($property->dpeExpiringSoon())->toBeFalse();
});

// === L'observer ne recale PAS les composants sur un changement de DPE ===
//
// ⚠️ Garde-fou contre une régression future : si quelqu'un ajoute un jour les colonnes DPE à
// `PropertyObserver::CHAMPS_DE_LA_BASE`, ce test doit rougir — la base amortissable ne dépend
// en rien du DPE.

it('never resyncs depreciation components when only the DPE changes', function () {
    $property = bienPourDpe(['acquisition_price' => 10_350_000]);

    app(DepreciationService::class)->syncComponents($property, [
        ['name' => 'Gros œuvre', 'duration_years' => 40, 'sort_order' => 0, 'percentage' => 100],
    ]);

    $avant = $property->fresh()->components->first()->updated_at;

    $property->update([
        'dpe_number' => '2521E1234567A',
        'dpe_class' => 'D',
        'dpe_established_at' => '2025-01-15',
        'dpe_valid_until' => '2035-01-15',
    ]);

    expect($property->fresh()->components->first()->updated_at->eq($avant))->toBeTrue();
});

// === Isolation multi-utilisateurs ===

it('never exposes the DPE of another user\'s property', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();

    $property = Property::withoutGlobalScopes()->forceCreate([
        'user_id' => $owner->id,
        'name' => 'Bien du propriétaire',
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
        'dpe_number' => '2521E1234567A',
        'dpe_class' => 'D',
        'dpe_established_at' => '2025-01-15',
        'dpe_valid_until' => '2035-01-15',
    ]);

    $this->actingAs($other);

    expect(Property::count())->toBe(0)
        ->and(Property::find($property->id))->toBeNull();

    $this->actingAs($owner);

    expect(Property::find($property->id)?->dpe_class)->toBe('D');
});
