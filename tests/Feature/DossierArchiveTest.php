<?php

use App\Models\Expense;
use App\Models\Furniture;
use App\Models\Income;
use App\Models\Property;
use App\Models\PropertyComponent;
use App\Models\PropertyWork;
use App\Models\User;
use App\Services\Csv\DossierArchive;
use App\Services\DepreciationService;

/**
 * Export / import du dossier complet (lot 5).
 *
 * L'exigence qui compte tient en une phrase : **export → import → export doit rendre le
 * même document**. Sans elle, « vous pouvez partir avec vos données » est une phrase de
 * page d'accueil, pas une fonctionnalité — et personne ne s'apercevrait qu'une colonne
 * se perd en route avant d'en avoir besoin.
 */
function archiveProperty(User $user, string $name = 'Bien archive'): Property
{
    return Property::forceCreate([
        'user_id' => $user->id,
        'name' => $name,
        'address' => '1 rue Test',
        'city' => 'Paris',
        'postal_code' => '75001',
        'type' => 'apartment',
        'total_area' => 100,
        'rented_area' => 80,
        'acquisition_date' => '2020-01-01',
        'acquisition_price' => 20000000,
        'notary_fees' => 1500000,
        'agency_fees' => 0,
        'acquisition_fees_treatment' => Property::ACQUISITION_FEES_AMORTIZED,
        'acquisition_fees_duration' => 20,
        'land_percentage' => 20,
        'rental_start_date' => '2021-01-01',
        'rental_type' => 'seasonal',
        'tva_regime' => Property::TVA_EXEMPT,
        'is_primary_residence' => false,
        'notes' => 'Bien de démonstration, accents : é à ç œ',
    ]);
}

/** Un dossier garni : composants (dont colonnes de reprise), travaux, mobilier, flux. */
function fillDossier(Property $property): void
{
    app(DepreciationService::class)->generateDefaultComponents($property);

    PropertyComponent::withoutGlobalScopes()
        ->where('property_id', $property->id)
        ->where('name', 'Toiture')
        ->update([
            'depreciation_start_date' => '2022-07-01',
            'cerfa_category' => PropertyComponent::CERFA_CATEGORY_FITTINGS,
            'opening_accumulated_depreciation' => 123400,
        ]);

    PropertyWork::create([
        'property_id' => $property->id, 'description' => 'Réfection toiture',
        'amount' => 800000, 'tva_rate' => 0, 'work_date' => '2022-06-01',
        'duration_years' => 15, 'is_dedicated' => true,
        'depreciation_source' => PropertyWork::DEPRECIATION_SOURCE_MANUAL,
        'annual_depreciation' => 53211,
        'opening_accumulated_depreciation' => 106422,
    ]);

    Furniture::create([
        'property_id' => $property->id, 'description' => 'Canapé convertible',
        'amount' => 120000, 'tva_rate' => 0, 'purchase_date' => '2021-03-01',
        'duration_years' => 5, 'is_dedicated' => true, 'is_second_hand' => false,
    ]);

    Income::create([
        'property_id' => $property->id, 'income_date' => '2024-07-15',
        'amount' => 52000, 'tva_rate' => 0, 'platform_fee' => 1900,
        'tourist_tax' => 300, 'source' => Income::SOURCE_AIRBNB,
        'reservation_ref' => 'HMABCD1234', 'guest_name' => 'Dupont',
    ]);

    Expense::create([
        'property_id' => $property->id, 'expense_date' => '2024-03-15',
        'amount' => 123456, 'tva_rate' => 0, 'category' => Expense::CATEGORY_PROPERTY_TAX,
        'description' => 'Taxe foncière 2024', 'is_dedicated' => true,
    ]);
}

beforeEach(function () {
    $this->archive = app(DossierArchive::class);
    $this->user = User::factory()->create(['email' => 'source@example.test']);
    $this->property = archiveProperty($this->user);
    fillDossier($this->property);
});

it('produces an identical document on export → import → export', function () {
    $first = $this->archive->export($this->user);

    $target = User::factory()->create(['email' => 'cible@example.test']);
    $this->archive->import($target, $first, allowForeignOwner: true);

    $second = $this->archive->export($target);

    // L'horodatage et le propriétaire changent par construction : tout le reste, non.
    unset($first['exported_at'], $first['owner'], $second['exported_at'], $second['owner']);

    expect($second)->toEqual($first);
});

it('carries the reprise columns through the round trip', function () {
    $target = User::factory()->create(['email' => 'cible@example.test']);
    $this->archive->import($target, $this->archive->export($this->user), allowForeignOwner: true);

    $roof = PropertyComponent::withoutGlobalScopes()
        ->whereIn('property_id', $target->properties()->withoutGlobalScopes()->pluck('id'))
        ->where('name', 'Toiture')->first();

    $work = PropertyWork::withoutGlobalScopes()
        ->whereIn('property_id', $target->properties()->withoutGlobalScopes()->pluck('id'))
        ->first();

    expect($roof->depreciation_start_date->format('Y-m-d'))->toBe('2022-07-01')
        ->and($roof->cerfa_category)->toBe(PropertyComponent::CERFA_CATEGORY_FITTINGS)
        ->and((int) $roof->opening_accumulated_depreciation)->toBe(123400)
        // La dotation manuelle survit : la relecture ne doit pas la recalculer.
        ->and($work->depreciation_source)->toBe(PropertyWork::DEPRECIATION_SOURCE_MANUAL)
        ->and((int) $work->annual_depreciation)->toBe(53211)
        ->and((int) $work->opening_accumulated_depreciation)->toBe(106422);
});

it('stamps a schema version on every archive', function () {
    expect($this->archive->export($this->user)['schema_version'])
        ->toBe(DossierArchive::SCHEMA_VERSION);
});

it('refuses an archive produced by a newer version instead of reading it partially', function () {
    $payload = $this->archive->export($this->user);
    $payload['schema_version'] = DossierArchive::SCHEMA_VERSION + 1;

    expect(fn () => $this->archive->import($this->user, $payload))
        ->toThrow(RuntimeException::class, 'Mettez OpenLMNP à jour');

    expect(Property::withoutGlobalScopes()->count())->toBe(1);
});

it('refuses an archive without a version number', function () {
    expect(fn () => $this->archive->import($this->user, ['properties' => []]))
        ->toThrow(RuntimeException::class, 'aucun numéro de version');
});

it('refuses to import someone else archive unless it is explicit', function () {
    $payload = $this->archive->export($this->user);
    $other = User::factory()->create(['email' => 'autre@example.test']);

    expect(fn () => $this->archive->import($other, $payload))
        ->toThrow(RuntimeException::class, 'appartient à source@example.test');

    // Rien n'a été écrit sur le compte de destination.
    expect($other->properties()->withoutGlobalScopes()->count())->toBe(0);

    // Avec --force, l'import passe.
    $this->archive->import($other, $payload, allowForeignOwner: true);
    expect($other->properties()->withoutGlobalScopes()->count())->toBe(1);
});

it('reads an archive written by an older version that lacked a column', function () {
    $payload = $this->archive->export($this->user);
    unset($payload['properties'][0]['acquisition_fees_treatment']);

    $target = User::factory()->create(['email' => 'cible@example.test']);
    $this->archive->import($target, $payload, allowForeignOwner: true);

    $imported = Property::withoutGlobalScopes()->where('user_id', $target->id)->first();

    // La colonne retombe sur le défaut de la base, et l'import ne casse pas.
    expect($imported->amortizesAcquisitionFees())->toBeTrue();
});

it('never mixes two users data in one archive', function () {
    $other = User::factory()->create(['email' => 'voisin@example.test']);
    fillDossier(archiveProperty($other, 'Bien du voisin'));

    $payload = $this->archive->export($this->user);

    expect($payload['properties'])->toHaveCount(1)
        ->and($payload['properties'][0]['name'])->toBe('Bien archive');
});

it('leaves nothing behind when the import fails midway', function () {
    $payload = $this->archive->export($this->user);
    // Une seconde propriété dont une valeur fera échouer l'écriture (colonne NOT NULL).
    $payload['properties'][] = ['name' => null] + $payload['properties'][0];
    $payload['properties'][1]['name'] = null;

    $target = User::factory()->create(['email' => 'cible@example.test']);

    try {
        $this->archive->import($target, $payload, allowForeignOwner: true);
    } catch (\Throwable) {
        // attendu
    }

    // Le premier bien ne doit pas subsister seul : la transaction a tout annulé.
    expect($target->properties()->withoutGlobalScopes()->count())->toBe(0);
});

// -----------------------------------------------------------------------------
// Les deux commandes
// -----------------------------------------------------------------------------

it('exports and re-imports a dossier through the artisan commands', function () {
    $path = sys_get_temp_dir() . '/olmnp-dossier-' . uniqid() . '.json';

    $this->artisan("openlmnp:export-dossier source@example.test --output={$path}")
        ->assertExitCode(0);

    expect(file_exists($path))->toBeTrue();

    $payload = json_decode((string) file_get_contents($path), true);
    expect($payload['schema_version'])->toBe(DossierArchive::SCHEMA_VERSION);

    User::factory()->create(['email' => 'cible@example.test']);

    $this->artisan("openlmnp:import-dossier cible@example.test {$path} --force")
        ->assertExitCode(0);

    expect(Property::withoutGlobalScopes()->where('name', 'Bien archive')->count())->toBe(2);
});

it('checks an archive without writing anything in dry-run', function () {
    $path = sys_get_temp_dir() . '/olmnp-dossier-' . uniqid() . '.json';
    $this->artisan("openlmnp:export-dossier source@example.test --output={$path}")->assertExitCode(0);

    User::factory()->create(['email' => 'cible@example.test']);

    $this->artisan("openlmnp:import-dossier cible@example.test {$path} --dry-run")
        ->expectsOutputToContain('Archive lisible')
        ->assertExitCode(0);

    expect(Property::withoutGlobalScopes()->count())->toBe(1);
});

it('fails cleanly on an unknown account or a missing file', function () {
    $this->artisan('openlmnp:export-dossier inconnu@example.test')->assertExitCode(1);
    $this->artisan('openlmnp:import-dossier source@example.test /tmp/nexiste-pas.json')->assertExitCode(1);
});
