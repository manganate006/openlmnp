<?php

use App\Models\FiscalYear;
use App\Models\Income;
use App\Models\Property;
use App\Models\User;

const SIGNALS_TOKEN = 'test-provision-token-0123456789abcdef';

function signalsHeaders(): array
{
    return ['Authorization' => 'Bearer '.SIGNALS_TOKEN];
}

function signalsProperty(User $user): Property
{
    return Property::create([
        'user_id' => $user->id,
        'name' => 'Studio Test',
        'address' => '1 rue du Test',
        'city' => 'Lyon',
        'postal_code' => '69003',
        'type' => 'apartment',
        'total_area' => 45,
        'rented_area' => 45,
        'acquisition_date' => '2022-01-01',
        'acquisition_price' => 20000000,
        'land_percentage' => 15,
        'rental_start_date' => '2022-03-01',
        'rental_type' => 'seasonal',
        'is_primary_residence' => false,
    ]);
}

beforeEach(function () {
    config(['services.provisioning.token' => SIGNALS_TOKEN]);
});

// === La garantie qui compte : invisible en auto-hébergement ===

it('does not exist at all when no provisioning token is configured', function () {
    // C'est LA garantie du chantier : une instance auto-hébergée ne doit voir
    // aucune trace de la mécanique commerciale du cloud.
    config(['services.provisioning.token' => null]);

    $this->getJson('/api/admin/lifecycle-signals?emails[]=a@b.com')
        ->assertNotFound();
});

it('refuses an invalid token', function () {
    $this->getJson('/api/admin/lifecycle-signals?emails[]=a@b.com', [
        'Authorization' => 'Bearer wrong-token',
    ])->assertUnauthorized();
});

it('refuses a request without any token', function () {
    $this->getJson('/api/admin/lifecycle-signals?emails[]=a@b.com')
        ->assertUnauthorized();
});

// === Contenu des signaux ===

it('reports a brand new account as being at step zero', function () {
    $user = User::factory()->create(['email' => 'nouveau@example.com']);

    $response = $this->getJson('/api/admin/lifecycle-signals?emails[]=nouveau@example.com', signalsHeaders())
        ->assertOk();

    $signal = $response->json('signals.0');
    expect($signal['email'])->toBe('nouveau@example.com')
        ->and($signal['onboarding_step'])->toBe(0)
        ->and($signal['properties_count'])->toBe(0)
        ->and($signal['closed_fiscal_years'])->toBe(0)
        ->and($signal['has_tax_return'])->toBeFalse()
        ->and($signal['last_entry_at'])->toBeNull()
        ->and($signal['suspended'])->toBeFalse();
});

it('counts the first step once a property exists', function () {
    $user = User::factory()->create(['email' => 'demarre@example.com']);
    signalsProperty($user);

    $signal = $this->getJson('/api/admin/lifecycle-signals?emails[]=demarre@example.com', signalsHeaders())
        ->assertOk()->json('signals.0');

    expect($signal['onboarding_step'])->toBe(1)
        ->and($signal['properties_count'])->toBe(1)
        ->and($signal['rental_types'])->toBe(['seasonal']);
});

it('detects a CSV import through the reservation reference', function () {
    $user = User::factory()->create(['email' => 'importe@example.com']);
    $property = signalsProperty($user);

    Income::create([
        'property_id' => $property->id,
        'income_date' => '2025-06-15',
        'amount' => 50000,
        'source' => 'airbnb',
        'reservation_ref' => 'HMABC123',
        'description' => 'Réservation',
    ]);

    $signal = $this->getJson('/api/admin/lifecycle-signals?emails[]=importe@example.com&year=2025', signalsHeaders())
        ->assertOk()->json('signals.0');

    expect($signal['import_used'])->toBeTrue()
        ->and($signal['platforms'])->toContain('airbnb')
        // Le signal d'activité d'un logiciel de compta, c'est la saisie.
        ->and($signal['last_entry_at'])->not->toBeNull();
});

it('reports a closed fiscal year without its tax return', function () {
    $user = User::factory()->create(['email' => 'cloture@example.com']);

    FiscalYear::create([
        'user_id' => $user->id,
        'year' => 2025,
        'status' => FiscalYear::STATUS_CLOSED,
    ]);

    $signal = $this->getJson('/api/admin/lifecycle-signals?emails[]=cloture@example.com&year=2025', signalsHeaders())
        ->assertOk()->json('signals.0');

    // C'est exactement le déclencheur du scénario A5.
    expect($signal['closed_fiscal_years'])->toBe(1)
        ->and($signal['has_tax_return'])->toBeFalse()
        ->and($signal['has_fec'])->toBeFalse();
});

// === Exclusions ===

it('never reports demo accounts', function () {
    User::factory()->create(['email' => 'demo-xyz@demo.local', 'is_demo' => true]);

    $this->getJson('/api/admin/lifecycle-signals?emails[]=demo-xyz@demo.local', signalsHeaders())
        ->assertOk()
        ->assertJsonCount(0, 'signals');
});

it('never reports the fixed demo account', function () {
    config(['demo.email' => 'demo@openlmnp.fr']);
    // Ce compte-là porte is_demo = false, ses identifiants étant publiés :
    // l'exclure par le seul drapeau ne suffirait pas.
    User::factory()->create(['email' => 'demo@openlmnp.fr', 'is_demo' => false]);

    $this->getJson('/api/admin/lifecycle-signals?emails[]=demo@openlmnp.fr', signalsHeaders())
        ->assertOk()
        ->assertJsonCount(0, 'signals');
});

it('does not leak users that were not asked for', function () {
    User::factory()->create(['email' => 'demande@example.com']);
    User::factory()->create(['email' => 'pas-demande@example.com']);

    $response = $this->getJson('/api/admin/lifecycle-signals?emails[]=demande@example.com', signalsHeaders())
        ->assertOk();

    expect($response->json('signals'))->toHaveCount(1)
        ->and($response->json('signals.0.email'))->toBe('demande@example.com');
});

it('never exposes an amount or a fiscal figure', function () {
    $user = User::factory()->create(['email' => 'confidentiel@example.com']);
    $property = signalsProperty($user);
    Income::create([
        'property_id' => $property->id,
        'income_date' => '2025-06-15',
        'amount' => 123456,
        'source' => 'direct',
        'description' => 'Loyer',
    ]);
    FiscalYear::create([
        'user_id' => $user->id, 'year' => 2025,
        'status' => FiscalYear::STATUS_CLOSED, 'fiscal_result' => 987654,
    ]);

    $body = $this->getJson('/api/admin/lifecycle-signals?emails[]=confidentiel@example.com&year=2025', signalsHeaders())
        ->assertOk()->getContent();

    // Ce qui part vers un prestataire d'emailing ne doit rien dire du patrimoine.
    expect($body)->not->toContain('123456')
        ->and($body)->not->toContain('987654')
        ->and($body)->not->toContain('fiscal_result')
        ->and($body)->not->toContain('acquisition_price');
});

it('rejects a request without any email', function () {
    $this->getJson('/api/admin/lifecycle-signals', signalsHeaders())
        ->assertUnprocessable();
});

it('expose les trois signaux ajoutes pour le sequenceur', function () {
    // Ils debloquent des scenarios qui attendaient faute de donnee : relance
    // interets d'emprunt, anteriorite manquante, charges incompletes.
    $user = User::factory()->create(['email' => 'signaux@example.com']);
    signalsProperty($user)->update(['rental_start_date' => '2024-07-01']);

    $signal = $this->getJson('/api/admin/lifecycle-signals?emails[]=signaux@example.com&year=2025', signalsHeaders())
        ->assertOk()->json('signals.0');

    expect($signal)->toHaveKeys(['has_loan', 'first_rental_start', 'expense_categories_missing'])
        ->and($signal['has_loan'])->toBeFalse()
        ->and($signal['first_rental_start'])->toContain('2024-07-01')
        // Aucune charge saisie : toutes les categories manquent.
        ->and($signal['expense_categories_missing'])->not->toBeEmpty();
});

it('ne renvoie jamais un montant dans les categories manquantes', function () {
    // Garde-fou sur la limite que s'impose l'endpoint : des libelles, jamais
    // un chiffre du dossier.
    $user = User::factory()->create(['email' => 'limites@example.com']);
    signalsProperty($user);

    $signal = $this->getJson('/api/admin/lifecycle-signals?emails[]=limites@example.com&year=2025', signalsHeaders())
        ->assertOk()->json('signals.0');

    foreach ($signal['expense_categories_missing'] as $categorie) {
        expect($categorie)->toBeString()
            ->and($categorie)->not->toMatch('/[0-9]/');
    }
});
