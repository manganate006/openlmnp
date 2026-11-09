<?php

use App\Filament\Pages\RepriseDossier;
use App\Filament\Resources\Properties\Pages\EditProperty;
use App\Models\Property;
use App\Models\User;
use App\Services\Csv\DossierArchive;
use Livewire\Livewire;

/**
 * Saisie et transport de la première mise en location (`activity_start_date`).
 *
 * La règle de calcul est verrouillée par `tests/Unit/DeemedDepreciationTest.php`. Ici :
 * la date arrive bien en base depuis l'assistant de reprise (qui la demandait sans
 * jamais l'enregistrer), voyage dans l'archive, et ne peut pas suivre le début du réel.
 * Montants synthétiques.
 */
function entryProperty(User $user, array $overrides = []): Property
{
    return Property::forceCreate(array_merge([
        'user_id' => $user->id,
        'name' => 'Studio test',
        'address' => '3 rue Test',
        'city' => 'Nantes',
        'postal_code' => '44000',
        'type' => 'studio',
        'total_area' => 25,
        'rented_area' => 25,
        'acquisition_date' => '2020-01-01',
        'acquisition_price' => 10000000,
        'notary_fees' => 0,
        'agency_fees' => 0,
        'land_percentage' => 15,
        'rental_start_date' => '2024-01-01',
        'activity_start_date' => '2021-04-01',
        'rental_type' => 'seasonal',
        'is_primary_residence' => false,
    ], $overrides));
}

/** Étapes 1 et 2 de l'assistant de reprise, pour un bien neuf. */
function repriseUpToProperty(User $user, string $regime, ?int $sinceYear = null): \Livewire\Features\SupportTesting\Testable
{
    $test = Livewire::actingAs($user)
        ->test(RepriseDossier::class)
        ->set('rentalStartDate', '2021-04-01')
        ->set('firstYear', 2025)
        ->set('regime', $regime);

    if ($sinceYear !== null) {
        $test->set('regimeSinceYear', $sinceYear);
    }

    return $test->call('nextStep')
        ->set('propertyName', 'Studio repris')
        ->set('propertyAddress', '3 rue Test')
        ->set('propertyCity', 'Nantes')
        ->set('propertyPostalCode', '44000')
        ->set('propertyArea', '25')
        ->set('acquisitionPrice', '100000')
        ->set('notaryFees', '0')
        ->set('landPercentage', '15')
        ->call('nextStep');
}

beforeEach(function () {
    $this->user = User::factory()->create();
});

// -----------------------------------------------------------------------------
// (f) Reprise : « J'étais au micro-BIC avant » est enfin enregistré
// -----------------------------------------------------------------------------

it('writes the first rental and the real start when the user was at micro-BIC before', function () {
    repriseUpToProperty($this->user, RepriseDossier::REGIME_SINCE_YEAR, 2023)->assertSet('step', 3);

    $property = Property::withoutGlobalScopes()->where('user_id', $this->user->id)->firstOrFail();

    expect($property->activity_start_date->format('Y-m-d'))->toBe('2021-04-01')
        ->and($property->rental_start_date->format('Y-m-d'))->toBe('2023-01-01');
});

it('keeps a single date when the user has been at the real regime since the start', function () {
    repriseUpToProperty($this->user, RepriseDossier::REGIME_SINCE_START)->assertSet('step', 3);

    $property = Property::withoutGlobalScopes()->where('user_id', $this->user->id)->firstOrFail();

    expect($property->activity_start_date)->toBeNull()
        ->and($property->rental_start_date->format('Y-m-d'))->toBe('2021-04-01');
});

it('opens the real regime on the first year kept here for a user leaving micro-BIC now', function () {
    repriseUpToProperty($this->user, RepriseDossier::REGIME_NEW)->assertSet('step', 3);

    $property = Property::withoutGlobalScopes()->where('user_id', $this->user->id)->firstOrFail();

    expect($property->activity_start_date->format('Y-m-d'))->toBe('2021-04-01')
        ->and($property->rental_start_date->format('Y-m-d'))->toBe('2025-01-01');
});

it('refuses a switch to the real regime before the first rental', function () {
    Livewire::actingAs($this->user)
        ->test(RepriseDossier::class)
        ->set('rentalStartDate', '2021-04-01')
        ->set('firstYear', 2025)
        ->set('regime', RepriseDossier::REGIME_SINCE_YEAR)
        ->set('regimeSinceYear', 2019)
        ->call('nextStep')
        ->assertSet('step', 1)
        ->assertSet('stepErrors.regimeSinceYear', fn ($message) => str_contains($message, 'précéder'));
});

it('reopens an existing property with its first rental and its switch year', function () {
    $property = entryProperty($this->user);

    // L'assistant reprend le premier bien du compte.
    Livewire::actingAs($this->user)
        ->test(RepriseDossier::class)
        ->assertSet('propertyId', $property->id)
        ->assertSet('rentalStartDate', '2021-04-01')
        ->assertSet('regime', RepriseDossier::REGIME_SINCE_YEAR)
        ->assertSet('regimeSinceYear', 2024);
});

// -----------------------------------------------------------------------------
// (e) Archive : la date voyage
// -----------------------------------------------------------------------------

it('carries the first rental date through an archive round trip', function () {
    entryProperty($this->user);
    $archive = app(DossierArchive::class);

    $payload = $archive->export($this->user);
    expect($payload['schema_version'])->toBe(3)
        ->and($payload['properties'][0]['activity_start_date'])->toBe('2021-04-01');

    $target = User::factory()->create();
    $archive->import($target, $payload, allowForeignOwner: true);

    $imported = Property::withoutGlobalScopes()->where('user_id', $target->id)->firstOrFail();
    expect($imported->activity_start_date->format('Y-m-d'))->toBe('2021-04-01')
        ->and($imported->rental_start_date->format('Y-m-d'))->toBe('2024-01-01');
});

it('reads an older archive without the column as a property always at the real regime', function () {
    entryProperty($this->user);
    $archive = app(DossierArchive::class);

    $payload = $archive->export($this->user);
    $payload['schema_version'] = 2;
    unset($payload['properties'][0]['activity_start_date']);

    $target = User::factory()->create();
    $archive->import($target, $payload, allowForeignOwner: true);

    expect(Property::withoutGlobalScopes()->where('user_id', $target->id)->firstOrFail()->activity_start_date)->toBeNull();
});

// -----------------------------------------------------------------------------
// Fiche du bien : la première location ne suit pas le début du réel
// -----------------------------------------------------------------------------

it('refuses a first rental later than the real start on the property form', function () {
    $property = entryProperty($this->user);

    Livewire::actingAs($this->user)
        ->test(EditProperty::class, ['record' => $property->getRouteKey()])
        ->fillForm(['activity_start_date' => '2025-06-01'])
        ->call('save')
        ->assertHasFormErrors(['activity_start_date' => 'before_or_equal']);
});

it('saves a first rental date from the property form', function () {
    $property = entryProperty($this->user, ['activity_start_date' => null]);

    Livewire::actingAs($this->user)
        ->test(EditProperty::class, ['record' => $property->getRouteKey()])
        ->fillForm(['activity_start_date' => '2022-05-01'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($property->fresh()->activity_start_date->format('Y-m-d'))->toBe('2022-05-01');
});
