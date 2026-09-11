<?php

use App\Filament\Resources\FiscalYears\Pages\ListFiscalYears;
use App\Filament\Pages\Teledeclaration;
use App\Models\FiscalYear;
use App\Models\Property;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;

/**
 * Enregistrer le dépôt d'une liasse : date et numéro d'accusé.
 *
 * `transmitted_at` et `ack_number` existaient en base et étaient rendues par deux outils MCP
 * alors qu'AUCUN écran ne les écrivait : elles étaient nulles sur tous les exercices, en
 * production comme ailleurs. Ce fichier verrouille le chemin qui les remplit, et la règle qui
 * les lie : un numéro d'accusé sans date de dépôt ne prouve rien.
 */
beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

function fiscalYearFor(User $user, int $year = 2025, array $attributes = []): FiscalYear
{
    return FiscalYear::create(array_merge([
        'user_id' => $user->id,
        'year'    => $year,
        'status'  => FiscalYear::STATUS_DRAFT,
    ], $attributes));
}

// --- La saisie, depuis la liste des exercices -------------------------------------------

it('records the filing date and the acknowledgement number together', function () {
    $fy = fiscalYearFor($this->user);

    Livewire::test(ListFiscalYears::class)
        ->mountAction(TestAction::make('record_transmission')->table($fy))
        ->setActionData(['transmitted_at' => '2026-05-12', 'ack_number' => 'EDI-2026-987654'])
        ->callMountedAction()
        ->assertHasNoActionErrors();

    $fy->refresh();
    expect($fy->transmitted_at?->toDateString())->toBe('2026-05-12');
    expect($fy->ack_number)->toBe('EDI-2026-987654');
});

it('accepts a filing date alone, the acknowledgement number being optional', function () {
    $fy = fiscalYearFor($this->user);

    Livewire::test(ListFiscalYears::class)
        ->mountAction(TestAction::make('record_transmission')->table($fy))
        ->setActionData(['transmitted_at' => '2026-05-12', 'ack_number' => null])
        ->callMountedAction()
        ->assertHasNoActionErrors();

    $fy->refresh();
    expect($fy->transmitted_at?->toDateString())->toBe('2026-05-12');
    expect($fy->ack_number)->toBeNull();
});

/**
 * L'ancre de ce fichier : le numéro ne survit JAMAIS à sa date.
 *
 * Sans cette règle, effacer une date de dépôt notée par erreur laisserait un numéro orphelin
 * derrière elle — que les outils MCP rendraient comme la trace d'un dépôt qui n'a pas eu lieu.
 */
it('clears the acknowledgement number when the filing date is emptied', function () {
    $fy = fiscalYearFor($this->user, attributes: [
        'transmitted_at' => '2026-05-12 00:00:00',
        'ack_number'     => 'EDI-2026-987654',
    ]);

    Livewire::test(ListFiscalYears::class)
        ->mountAction(TestAction::make('record_transmission')->table($fy))
        // La date est vidée, le numéro est laissé tel quel : c'est le geste exact d'un
        // utilisateur qui annule un dépôt noté à tort.
        ->setActionData(['transmitted_at' => null, 'ack_number' => 'EDI-2026-987654'])
        ->callMountedAction()
        ->assertHasNoActionErrors();

    $fy->refresh();
    expect($fy->transmitted_at)->toBeNull();
    expect($fy->ack_number)->toBeNull();
});

it('reopens the form on the values already recorded', function () {
    $fy = fiscalYearFor($this->user, attributes: [
        'transmitted_at' => '2026-05-12 00:00:00',
        'ack_number'     => 'EDI-2026-987654',
    ]);

    Livewire::test(ListFiscalYears::class)
        ->mountAction(TestAction::make('record_transmission')->table($fy))
        ->assertActionDataSet([
            'transmitted_at' => '2026-05-12',
            'ack_number'     => 'EDI-2026-987654',
        ]);
});

// --- Les deux bornes de la date ---------------------------------------------------------

it('refuses a filing date in the future', function () {
    $fy = fiscalYearFor($this->user);

    Livewire::test(ListFiscalYears::class)
        ->mountAction(TestAction::make('record_transmission')->table($fy))
        ->setActionData(['transmitted_at' => now()->addDay()->toDateString()])
        ->callMountedAction()
        ->assertHasActionErrors(['transmitted_at']);

    expect($fy->refresh()->transmitted_at)->toBeNull();
});

it('refuses a filing date earlier than the fiscal year it declares', function () {
    $fy = fiscalYearFor($this->user, 2025);

    Livewire::test(ListFiscalYears::class)
        ->mountAction(TestAction::make('record_transmission')->table($fy))
        // Une liasse 2025 ne peut pas avoir été déposée en 2024 : c'est la faute de frappe
        // qu'on recopie d'un accusé, pas une situation réelle.
        ->setActionData(['transmitted_at' => '2024-12-31'])
        ->callMountedAction()
        ->assertHasActionErrors(['transmitted_at']);

    expect($fy->refresh()->transmitted_at)->toBeNull();
});

/**
 * L'état que le navigateur envoie vraiment.
 *
 * Un `DatePicker` non natif renvoie la date choisie AVEC l'heure du clic. Un test qui pose
 * « 2026-05-12 » pose une valeur propre que personne n'envoie jamais : la borne haute pourrait
 * refuser une date du jour à l'écran pendant que la suite reste au vert.
 */
it('accepts the timestamped state a date picker really sends, and drops the time', function () {
    $fy = fiscalYearFor($this->user, (int) now()->year);

    Livewire::test(ListFiscalYears::class)
        ->mountAction(TestAction::make('record_transmission')->table($fy))
        ->setActionData(['transmitted_at' => now()->format('Y-m-d H:i:s')])
        ->callMountedAction()
        ->assertHasNoActionErrors();

    expect($fy->refresh()->transmitted_at?->toDateTimeString())
        ->toBe(now()->startOfDay()->toDateTimeString());
});

// --- Ce que la liste montre --------------------------------------------------------------

it('badges only the years whose filing was recorded', function () {
    $filed = fiscalYearFor($this->user, 2024, ['transmitted_at' => '2025-05-12 00:00:00']);
    $draft = fiscalYearFor($this->user, 2025);

    Livewire::test(ListFiscalYears::class)
        ->assertTableColumnStateSet('transmitted_at', 'Déposée', $filed)
        // Générer un PDF ou clôturer ne pose PAS le badge : seule la saisie le pose.
        ->assertTableColumnStateSet('transmitted_at', null, $draft);
});

it('summarises the filing for the tooltip, acknowledgement number or not', function () {
    $withAck = fiscalYearFor($this->user, 2024, [
        'transmitted_at' => '2025-05-12 00:00:00',
        'ack_number'     => 'EDI-2025-987654',
    ]);
    $withoutAck = fiscalYearFor($this->user, 2025, ['transmitted_at' => '2026-05-12 00:00:00']);

    expect($withAck->transmissionSummary())
        ->toBe('Liasse déposée le 12/05/2025 — accusé n° EDI-2025-987654.');
    expect($withoutAck->transmissionSummary())
        ->toBe('Liasse déposée le 12/05/2026 — aucun numéro d\'accusé enregistré.');
    expect(fiscalYearFor($this->user, 2023)->transmissionSummary())->toBeNull();
});

// --- L'écran d'aide à la télédéclaration -------------------------------------------------

it('records the filing from the teledeclaration screen and says so', function () {
    Property::forceCreate([
        'user_id'              => $this->user->id,
        'name'                 => 'Studio',
        'address'              => '1 rue Test',
        'city'                 => 'Paris',
        'postal_code'          => '75001',
        'type'                 => 'apartment',
        'total_area'           => 40,
        'rented_area'          => 40,
        'acquisition_date'     => '2020-01-01',
        'acquisition_price'    => 10000000,
        'notary_fees'          => 0,
        'market_value'         => null,
        'land_percentage'      => 15,
        'rental_start_date'    => '2023-01-01',
        'rental_type'          => 'seasonal',
        'is_primary_residence' => false,
    ]);
    $fy = fiscalYearFor($this->user, 2025);

    Livewire::test(Teledeclaration::class)
        ->set('year', 2025)
        ->mountAction(TestAction::make('record_transmission'))
        ->setActionData(['transmitted_at' => '2026-05-12', 'ack_number' => 'EDI-2026-987654'])
        ->callMountedAction()
        ->assertHasNoActionErrors()
        // Le bandeau est la contrepartie visible de la saisie : sans lui, l'utilisateur
        // n'a aucun moyen de savoir que l'exercice affiché porte un dépôt.
        ->assertSee('Liasse déposée le 12/05/2026');

    expect($fy->refresh()->ack_number)->toBe('EDI-2026-987654');
});

// --- Ce que le MCP en dit ----------------------------------------------------------------

/**
 * Le bout de la chaîne : `get_fiscal_year` annonçait ces deux champs depuis toujours, et ne
 * pouvait rendre que `null` puisque rien ne les écrivait.
 */
it('serves the recorded filing to the MCP tool that announces it', function () {
    config(['mcp.enabled' => true]);
    // Le MÊME utilisateur des deux côtés : la saisie passe par la session Filament, la
    // lecture par un jeton Sanctum. Deux comptes distincts feraient échouer la lecture sur
    // l'isolation, et non sur ce qu'on veut mesurer.
    $this->user->forceFill(['mcp_enabled' => true])->save();
    $token = $this->user->createToken('test-token');
    $fy = fiscalYearFor($this->user, 2025);

    Livewire::test(ListFiscalYears::class)
        ->mountAction(TestAction::make('record_transmission')->table($fy))
        ->setActionData(['transmitted_at' => '2026-05-12', 'ack_number' => 'EDI-2026-987654'])
        ->callMountedAction()
        ->assertHasNoActionErrors();

    // ⚠️ La session Filament doit être OUBLIÉE avant l'appel MCP. Sans cela, le garde
    // `auth:sanctum` retrouve l'utilisateur connecté par `actingAs()` et lui attache un
    // `TransientToken`, qui n'a pas de `name` : l'audit du McpGuard part en erreur 500, et
    // le test échoue sur l'authentification au lieu de mesurer ce qu'il annonce.
    $this->app['auth']->forgetGuards();

    $response = $this->withToken($token->plainTextToken)->postJson('/mcp', [
        'jsonrpc' => '2.0',
        'id'      => 1,
        'method'  => 'tools/call',
        'params'  => ['name' => 'get_fiscal_year', 'arguments' => ['year' => 2025]],
    ])->assertOk();

    $payload = json_decode($response->json('result.content.0.text', '{}'), true);

    expect($payload['transmitted_at'])->toBe('2026-05-12 00:00:00');
    expect($payload['ack_number'])->toBe('EDI-2026-987654');
});
