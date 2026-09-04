<?php

use App\Filament\Pages\FiscalYearWizard;
use App\Filament\Pages\RepriseDossier;
use App\Models\FiscalYear;
use App\Models\Property;
use App\Models\PropertyComponent;
use App\Models\User;
use App\Services\DepreciationService;
use App\Services\ReprisesCheckService;
use Livewire\Livewire;

/**
 * Assistant « Reprendre un dossier existant » (`/reprise`).
 *
 * Cas joué, celui de la maquette : un appartement acheté 200 000 € en 2019, loué depuis le
 * 1er juin 2019, tenu au réel par un cabinet jusqu'en 2025. L'utilisateur reprend la main
 * sur 2026 et n'a que sa liasse 2025 sous les yeux — 12 480 € d'amortissements différés,
 * 1 250 € de déficit né en 2023, 47 394 € de cumul.
 */
function repriseProperty(User $user, array $overrides = []): Property
{
    return Property::forceCreate(array_merge([
        'user_id' => $user->id,
        'name' => 'Appartement repris',
        'address' => '12 rue de la Liasse',
        'city' => 'Bordeaux',
        'postal_code' => '33000',
        'type' => 'apartment',
        'total_area' => 45,
        'rented_area' => 45,
        'acquisition_date' => '2019-06-01',
        'acquisition_price' => 20_000_000,
        'notary_fees' => 1_600_000,
        'agency_fees' => 0,
        'land_percentage' => 15,
        'rental_start_date' => '2019-06-01',
        'rental_type' => 'seasonal',
        'is_primary_residence' => false,
    ], $overrides));
}

/** Amène l'assistant jusqu'à l'étape 4, bien et plan d'amortissement enregistrés. */
function repriseAtStepFour(User $user, Property $property): \Livewire\Features\SupportTesting\Testable
{
    app(DepreciationService::class)->generateDefaultComponents($property);

    return Livewire::actingAs($user)
        ->test(RepriseDossier::class)
        ->set('rentalStartDate', '2019-06-01')
        ->set('firstYear', 2026)
        ->set('regime', RepriseDossier::REGIME_SINCE_START)
        ->call('nextStep')
        ->call('nextStep')
        ->call('chooseMethod', RepriseDossier::METHOD_COPY)
        ->call('nextStep');
}

/** Ce qui a déjà été amorti des frais d'acquisition à la clôture 2025. */
function notaryCumulOf(Property $property): int
{
    return (int) collect(app(DepreciationService::class)->depreciationDetailForYear($property->fresh(), 2025))
        ->where('type', 'notary')
        ->sum(fn ($line) => (int) $line['cumul']);
}

beforeEach(function () {
    $this->user = User::factory()->create();
});

// ─────────────────────────────────────────────────────────────────────
// Le parcours, étape par étape
// ─────────────────────────────────────────────────────────────────────

it('serves the reprise wizard on its own slug', function () {
    $this->actingAs($this->user)
        ->get('/reprise')
        ->assertOk()
        ->assertSee('Reprendre un dossier existant')
        ->assertSee('Votre situation')
        ->assertSee('Vos reports');
});

it('refuses to leave step 1 without a rental start date', function () {
    Livewire::actingAs($this->user)
        ->test(RepriseDossier::class)
        ->set('rentalStartDate', null)
        ->call('nextStep')
        ->assertSet('step', 1)
        ->assertSee('Indiquez la date de mise en location');
});

it('refuses a first year that precedes the rental start', function () {
    Livewire::actingAs($this->user)
        ->test(RepriseDossier::class)
        ->set('rentalStartDate', '2019-06-01')
        ->set('firstYear', 2015)
        ->call('nextStep')
        ->assertSet('step', 1)
        ->assertSee('Cette année précède la mise en location');
});

it('never lets the browser jump forward to a step that was not validated', function () {
    Livewire::actingAs($this->user)
        ->test(RepriseDossier::class)
        ->call('goToStep', 5)
        ->assertSet('step', 1)
        ->assertSet('report', null);
});

it('creates the property at step 2 and converts euros to cents exactly once', function () {
    Livewire::actingAs($this->user)
        ->test(RepriseDossier::class)
        ->set('rentalStartDate', '2019-06-01')
        ->set('firstYear', 2026)
        ->call('nextStep')
        ->set('propertyName', 'Appartement repris')
        ->set('propertyAddress', '12 rue de la Liasse')
        ->set('propertyCity', 'Bordeaux')
        ->set('propertyPostalCode', '33000')
        ->set('propertyArea', '45')
        ->set('acquisitionPrice', '200 000')
        ->set('notaryFees', '16000')
        ->set('landPercentage', '15')
        ->call('nextStep')
        ->assertSet('step', 3);

    $property = Property::withoutGlobalScopes()->where('user_id', $this->user->id)->firstOrFail();

    // 200 000 € = 20 000 000 centimes. Ni 200 000, ni 2 000 000 000 : le wizard
    // d'onboarding a déjà multiplié deux fois par 100 en septembre 2026.
    expect((int) $property->acquisition_price)->toBe(20_000_000)
        ->and((int) $property->notary_fees)->toBe(1_600_000)
        ->and((int) $property->land_percentage)->toBe(15)
        ->and($property->rental_start_date->format('Y-m-d'))->toBe('2019-06-01')
        // Date d'acquisition non saisie : la mise en location fait foi.
        ->and($property->acquisition_date->format('Y-m-d'))->toBe('2019-06-01');
});

it('reads a French date the French way', function () {
    Livewire::actingAs($this->user)
        ->test(RepriseDossier::class)
        // « 01/06/2019 » sur une liasse, c'est le 1er juin. `Carbon::parse()` y lirait le
        // 6 janvier — strtotime lit les barres obliques à l'américaine — et tout le prorata
        // de première année serait décalé sans que rien ne le signale.
        ->set('rentalStartDate', '01/06/2019')
        ->set('firstYear', 2026)
        ->call('nextStep')
        ->set('propertyName', 'Appartement repris')
        ->set('propertyAddress', '12 rue de la Liasse')
        ->set('propertyCity', 'Bordeaux')
        ->set('propertyPostalCode', '33000')
        ->set('propertyArea', '45')
        ->set('acquisitionPrice', '200000')
        ->call('nextStep')
        ->assertSet('step', 3);

    $property = Property::withoutGlobalScopes()->where('user_id', $this->user->id)->firstOrFail();

    expect($property->rental_start_date->format('Y-m-d'))->toBe('2019-06-01');
});

it('refuses a date it cannot read rather than guessing', function () {
    Livewire::actingAs($this->user)
        ->test(RepriseDossier::class)
        ->set('rentalStartDate', 'juin 2019')
        ->call('nextStep')
        ->assertSet('step', 1)
        ->assertSee('Indiquez la date de mise en location');
});

it('reads a decimal amount written with a comma', function () {
    expect(RepriseDossier::centsFromEuros('1 250,50'))->toBe(125_050)
        ->and(RepriseDossier::centsFromEuros('12480'))->toBe(1_248_000)
        ->and(RepriseDossier::centsFromEuros('47 394 €'))->toBe(4_739_400)
        // Une case vide de la liasse ne vaut pas zéro : elle ne se compare pas.
        ->and(RepriseDossier::centsFromEuros(''))->toBeNull()
        ->and(RepriseDossier::centsFromEuros('n/a'))->toBeNull();
});

it('nets the land share out of the depreciable base shown at step 2', function () {
    $component = Livewire::actingAs($this->user)
        ->test(RepriseDossier::class)
        ->set('acquisitionPrice', '200000')
        ->set('landPercentage', '15');

    expect($component->instance()->depreciableBaseCents())->toBe(17_000_000);
});

it('updates an existing property instead of creating a second one', function () {
    $property = repriseProperty($this->user);

    Livewire::actingAs($this->user)
        ->test(RepriseDossier::class)
        ->assertSet('propertyId', $property->id)
        ->set('rentalStartDate', '2019-06-01')
        ->set('firstYear', 2026)
        ->call('nextStep')
        ->set('landPercentage', '20')
        ->set('acquisitionFeesTreatment', Property::ACQUISITION_FEES_EXPENSED)
        ->call('nextStep')
        ->assertSet('step', 3);

    expect(Property::withoutGlobalScopes()->where('user_id', $this->user->id)->count())->toBe(1);

    $property->refresh();

    expect((int) $property->land_percentage)->toBe(20)
        ->and($property->acquisition_fees_treatment)->toBe(Property::ACQUISITION_FEES_EXPENSED);
});

// ─────────────────────────────────────────────────────────────────────
// Étape 3 — le choix de méthode, et l'éditeur existant
// ─────────────────────────────────────────────────────────────────────

it('spells out the pros and cons of both methods, as arbitrated in the mock-up', function () {
    $property = repriseProperty($this->user);

    Livewire::actingAs($this->user)
        ->test(RepriseDossier::class)
        ->set('rentalStartDate', '2019-06-01')
        ->set('firstYear', 2026)
        ->call('nextStep')
        ->call('nextStep')
        ->assertSee('Recopier les lignes de ma liasse')
        ->assertSee('Répartir automatiquement ma base')
        ->assertSee('≈ 10 minutes, liasse sous les yeux')
        ->assertSee('Un clic, rien à saisir')
        ->assertSee('Ne reproduit pas le plan de votre comptable')
        ->assertSee('Si vous avez un plan, recopiez-le.');
});

it('spreads the base over the standard components when that method is chosen', function () {
    $property = repriseProperty($this->user);

    Livewire::actingAs($this->user)
        ->test(RepriseDossier::class)
        ->set('rentalStartDate', '2019-06-01')
        ->set('firstYear', 2026)
        ->call('nextStep')
        ->call('nextStep')
        ->call('chooseMethod', RepriseDossier::METHOD_SPREAD)
        ->assertSet('method', RepriseDossier::METHOD_SPREAD);

    $components = PropertyComponent::withoutGlobalScopes()
        ->where('property_id', $property->id)
        ->pluck('base_source', 'name');

    expect($components)->toHaveCount(count(DepreciationService::DEFAULT_COMPONENTS))
        ->and($components['Gros œuvre'])->toBe(PropertyComponent::BASE_SOURCE_PERCENTAGE);
});

it('opens the shared editor on the amounts tab when the liasse is copied', function () {
    $property = repriseProperty($this->user);
    app(DepreciationService::class)->generateDefaultComponents($property);

    $component = Livewire::actingAs($this->user)
        ->test(RepriseDossier::class)
        ->set('rentalStartDate', '2019-06-01')
        ->set('firstYear', 2026)
        ->call('nextStep')
        ->call('nextStep')
        ->call('chooseMethod', RepriseDossier::METHOD_COPY);

    expect($component->instance()->editorInitialMode())->toBe('amounts');

    // L'étape 3 rend l'éditeur EXISTANT — ses deux onglets, sa colonne de cumul repris —
    // et non un troisième éditeur qui divergerait au premier correctif.
    $component->assertSee('Ventilation')
        ->assertSee('Montants')
        ->assertSee('Cumul au 31/12');
});

it('keys the embedded editor on the chosen method, so changing method removes it', function () {
    $property = repriseProperty($this->user);
    app(DepreciationService::class)->generateDefaultComponents($property);

    $component = Livewire::actingAs($this->user)
        ->test(RepriseDossier::class)
        ->set('rentalStartDate', '2019-06-01')
        ->set('firstYear', 2026)
        ->call('nextStep')
        ->call('nextStep')
        ->call('chooseMethod', RepriseDossier::METHOD_COPY);

    // L'éditeur porte un `wire:ignore` : Livewire CONSERVE le nœud lors d'un morphing au
    // lieu de le retirer. Seule une clé qui change le fait disparaître — sans elle,
    // « Changer de méthode » laissait les cartes de choix et l'éditeur à l'écran ensemble.
    // Un test Pest ne voit pas le morphing du navigateur : il verrouille la clé.
    $component->assertSee('wire:key="reprise-editor-' . RepriseDossier::METHOD_COPY . '-' . $property->id . '"', false)
        ->set('method', null)
        ->assertDontSee('wire:key="reprise-editor-' . RepriseDossier::METHOD_COPY . '-' . $property->id . '"', false)
        ->assertSee('Recopier les lignes de ma liasse');
});

it('refuses a method that the browser invented', function () {
    repriseProperty($this->user);

    Livewire::actingAs($this->user)
        ->test(RepriseDossier::class)
        ->call('chooseMethod', 'ia')
        ->assertSet('method', null);
});

it('writes the plan through the shared editor, from the reprise wizard', function () {
    $property = repriseProperty($this->user);
    app(DepreciationService::class)->generateDefaultComponents($property);

    Livewire::actingAs($this->user)
        ->test(RepriseDossier::class)
        ->set('rentalStartDate', '2019-06-01')
        ->set('firstYear', 2026)
        ->call('nextStep')
        ->call('nextStep')
        ->call('chooseMethod', RepriseDossier::METHOD_COPY)
        ->call('saveComponents', [[
            'id' => null,
            'name' => 'Menuiseries extérieures',
            'percentage' => 0,
            'baseAmount' => 400_000,
            'baseSource' => PropertyComponent::BASE_SOURCE_MANUAL,
            'annualDepreciation' => 20_000,
            'duration' => 20,
            'sortOrder' => 20,
            'enabled' => true,
            'cerfaCategory' => PropertyComponent::CERFA_CATEGORY_CONSTRUCTIONS,
            'startDate' => '2019-06-01',
            'openingCumul' => 131_700,
        ]]);

    $line = PropertyComponent::withoutGlobalScopes()
        ->where('property_id', $property->id)
        ->where('name', 'Menuiseries extérieures')
        ->first();

    expect($line)->not->toBeNull()
        ->and((int) $line->base_amount)->toBe(400_000)
        ->and((int) $line->opening_accumulated_depreciation)->toBe(131_700);
});

// ─────────────────────────────────────────────────────────────────────
// Étape 4 — les reports, et les cases Cerfa où les lire
// ─────────────────────────────────────────────────────────────────────

it('names the Cerfa box next to every amount it asks for', function () {
    $property = repriseProperty($this->user);

    repriseAtStepFour($this->user, $property)
        ->assertSee('2033-D, case 870')
        ->assertSee('2033-B, case 318')
        ->assertSee('2033-A, case 030')
        ->assertSee('2033-A, case 028')
        ->assertSee('2033-D, cases 980 à 984')
        ->assertSee('Différé n\'est pas déficit.', false);
});

it('adds and removes a deficit vintage, and dates its expiry ten years out', function () {
    $property = repriseProperty($this->user);

    $component = repriseAtStepFour($this->user, $property)
        ->call('addDeficit')
        ->set('deficits.0.origin_year', 2023)
        ->set('deficits.0.amount', '1 250');

    // Art. 156, I, 1° ter du CGI : dix ans en location meublée non professionnelle.
    expect($component->instance()->deficitExpiryYear(2023))->toBe(2033);

    $component->assertSee('2033')
        ->call('removeDeficit', 0)
        ->assertSet('deficits', []);
});

it('refuses an unreadable amount rather than storing a silent zero', function () {
    $property = repriseProperty($this->user);

    repriseAtStepFour($this->user, $property)
        ->set('openingDeferred', 'douze mille')
        ->call('nextStep')
        ->assertSet('step', 4)
        ->assertSee('Montant illisible');
});

// ─────────────────────────────────────────────────────────────────────
// Étape 5 — le contrôle, qui est la fonctionnalité
// ─────────────────────────────────────────────────────────────────────

it('branches the check service and declares a faithful reprise', function () {
    $property = repriseProperty($this->user);

    $component = repriseAtStepFour($this->user, $property)
        // 2033-A case 028 : la valeur brute du bien, que l'application reconstitue à
        // l'identique. Le cumul (030) est laissé vide : une case non renseignée ne se
        // compare pas, elle ne vaut surtout pas zéro.
        ->set('declaredGrossAssets', '200000')
        ->set('openingDeferred', '12480')
        ->call('addDeficit')
        ->set('deficits.0.origin_year', 2023)
        ->set('deficits.0.amount', '1250')
        ->call('nextStep')
        ->assertSet('step', 5);

    $report = $component->instance()->report;

    expect($report['year'])->toBe(2025)
        ->and($report['verdict'])->toBe(ReprisesCheckService::VERDICT_MATCH);

    $lines = collect($report['lines'])->keyBy('key');

    expect($lines[ReprisesCheckService::LINE_GROSS_ASSETS]['declared'])->toBe(20_000_000)
        ->and($lines[ReprisesCheckService::LINE_GROSS_ASSETS]['computed'])->toBe(20_000_000)
        ->and($lines[ReprisesCheckService::LINE_DEFERRED_DEPRECIATION]['computed'])->toBe(1_248_000)
        ->and($lines[ReprisesCheckService::LINE_DEFICIT_CARRYFORWARD]['computed'])->toBe(125_000)
        ->and($lines[ReprisesCheckService::LINE_ACCUMULATED_DEPRECIATION]['verdict'])
            ->toBe(ReprisesCheckService::VERDICT_UNCHECKED);

    $component->assertSee('Votre reprise est fidèle à votre liasse.')
        ->assertSee('2033-A case 028')
        ->assertSee('2033-D case 870');
});

it('shows the diagnostics in order when the cumulated depreciation does not tally', function () {
    $property = repriseProperty($this->user);

    $component = repriseAtStepFour($this->user, $property)
        ->set('openingDeferred', '12480')
        ->call('nextStep');

    $computed = collect($component->instance()->report['lines'])
        ->firstWhere('key', ReprisesCheckService::LINE_ACCUMULATED_DEPRECIATION)['computed'];

    // Le cas de la maquette : le cabinet a passé les frais de notaire en charges en 2019.
    // Sur la case 030, l'écart ne vaut PAS les 16 000 € de frais mais seulement ce qui en
    // a été amorti depuis la mise en location.
    $component->call('goToStep', 4)
        ->set('openingAccumulated', RepriseDossier::eurosFromCents($computed - notaryCumulOf($property)))
        ->call('nextStep')
        ->assertSet('step', 5);

    $line = collect($component->instance()->report['lines'])
        ->firstWhere('key', ReprisesCheckService::LINE_ACCUMULATED_DEPRECIATION);

    expect($line['verdict'])->toBe(ReprisesCheckService::VERDICT_MISMATCH)
        ->and(array_column($line['diagnostics'], 'code'))->toBe([
            'rental_start_date', 'land_share', 'acquisition_fees', 'missing_component', 'market_value',
        ]);

    // La piste chiffrée est corroborée : c'est elle qui porte le bouton de correction.
    expect($component->instance()->corroboratedAcquisitionFees())->toBeTrue();

    $component->assertSee('Passer mes frais d\'acquisition en charges', false)
        ->assertSee('Terminer quand même');
});

it('switches the acquisition fees to charges and replays the check', function () {
    $property = repriseProperty($this->user);

    $component = repriseAtStepFour($this->user, $property)
        ->set('openingDeferred', '12480')
        ->call('nextStep');

    $computed = collect($component->instance()->report['lines'])
        ->firstWhere('key', ReprisesCheckService::LINE_ACCUMULATED_DEPRECIATION)['computed'];

    $component->call('goToStep', 4)
        ->set('openingAccumulated', RepriseDossier::eurosFromCents($computed - notaryCumulOf($property)))
        ->call('nextStep')
        ->call('expenseAcquisitionFees');

    $property->refresh();

    expect($property->acquisition_fees_treatment)->toBe(Property::ACQUISITION_FEES_EXPENSED);

    $line = collect($component->instance()->report['lines'])
        ->firstWhere('key', ReprisesCheckService::LINE_ACCUMULATED_DEPRECIATION);

    // Le contrôle a été rejoué : l'écart a disparu, il n'a pas fallu recommencer le parcours.
    expect($line['verdict'])->toBe(ReprisesCheckService::VERDICT_MATCH);
});

// ─────────────────────────────────────────────────────────────────────
// Enregistrement des soldes d'ouverture
// ─────────────────────────────────────────────────────────────────────

it('writes nothing into fiscal_years before the reprise is finished', function () {
    $property = repriseProperty($this->user);

    repriseAtStepFour($this->user, $property)
        ->set('openingDeferred', '12480')
        ->set('openingAccumulated', '47394')
        ->call('nextStep')
        ->assertSet('step', 5);

    // L'étape 5 fait tourner le contrôle sur un exercice NON persisté : un parcours
    // abandonné ne laisse pas derrière lui un exercice dont toute la chaîne lirait
    // les reports.
    expect(FiscalYear::withoutGlobalScopes()->where('user_id', $this->user->id)->count())->toBe(0);
});

it('stores the opening balances on the reprise fiscal year when the reprise is finished', function () {
    $property = repriseProperty($this->user);

    repriseAtStepFour($this->user, $property)
        ->set('openingDeferred', '12480')
        ->set('openingAccumulated', '47394')
        ->set('declaredGrossAssets', '200000')
        ->call('addDeficit')
        ->set('deficits.0.origin_year', 2023)
        ->set('deficits.0.amount', '1250')
        ->call('nextStep')
        ->call('finish')
        ->assertSet('finished', true)
        ->assertSee('Votre dossier est repris');

    $fiscalYear = FiscalYear::withoutGlobalScopes()
        ->where('user_id', $this->user->id)
        ->where('year', 2026)
        ->firstOrFail();

    expect((int) $fiscalYear->opening_deferred_depreciation)->toBe(1_248_000)
        ->and((int) $fiscalYear->opening_accumulated_depreciation)->toBe(4_739_400)
        ->and($fiscalYear->opening_deficits)->toBe([['origin_year' => 2023, 'amount' => 125_000]])
        ->and($fiscalYear->opening_source)->toBe(FiscalYear::OPENING_SOURCE_MANUAL)
        ->and($fiscalYear->status)->toBe(FiscalYear::STATUS_DRAFT)
        ->and($fiscalYear->hasOpeningBalances())->toBeTrue()
        // Le report d'ouverture est entré dans la chaîne : l'exercice a été calculé.
        ->and((int) $fiscalYear->previous_deferred)->toBe(1_248_000);
});

it('refuses to overwrite the opening balances of a closed fiscal year', function () {
    $property = repriseProperty($this->user);

    FiscalYear::forceCreate([
        'user_id' => $this->user->id,
        'year' => 2026,
        'status' => FiscalYear::STATUS_CLOSED,
        'fiscal_result' => 654321,
    ]);

    repriseAtStepFour($this->user, $property)
        ->set('openingDeferred', '12480')
        ->call('nextStep')
        ->call('finish')
        ->assertSet('finished', false)
        ->assertNotified('Exercice déjà clôturé');

    $fiscalYear = FiscalYear::withoutGlobalScopes()
        ->where('user_id', $this->user->id)
        ->where('year', 2026)
        ->firstOrFail();

    expect((int) $fiscalYear->opening_deferred_depreciation)->toBe(0)
        ->and((int) $fiscalYear->fiscal_result)->toBe(654321);
});

it('stops the fiscal year wizard from demanding a predecessor once the reprise carries opening balances', function () {
    $property = repriseProperty($this->user);
    app(DepreciationService::class)->generateDefaultComponents($property);

    // Sans soldes d'ouverture, la chaîne réclame l'exercice 2025 — et c'est justifié.
    Livewire::actingAs($this->user)
        ->test(FiscalYearWizard::class)
        ->set('data.year', 2026)
        ->assertSee('L\'exercice 2025 n\'existe pas', false);

    FiscalYear::forceCreate([
        'user_id' => $this->user->id,
        'year' => 2026,
        'status' => FiscalYear::STATUS_DRAFT,
        'opening_deferred_depreciation' => 1_248_000,
        'opening_source' => FiscalYear::OPENING_SOURCE_MANUAL,
    ]);

    // Avec, l'exigence tombe : quelqu'un qui arrive d'un cabinet n'a évidemment aucun
    // exercice 2025 ici, son report vient de sa liasse. C'est le blocage que la reprise
    // doit lever, et il se lisait noir sur blanc dans l'assistant de clôture.
    Livewire::actingAs($this->user)
        ->test(FiscalYearWizard::class)
        ->set('data.year', 2026)
        ->assertDontSee('L\'exercice 2025 n\'existe pas', false)
        ->call('create')
        ->assertNotified('Exercice fiscal créé');

    $fiscalYear = FiscalYear::withoutGlobalScopes()
        ->where('user_id', $this->user->id)->where('year', 2026)->firstOrFail();

    // Le calcul de l'exercice n'efface pas les soldes repris.
    expect((int) $fiscalYear->opening_deferred_depreciation)->toBe(1_248_000)
        ->and((int) $fiscalYear->previous_deferred)->toBe(1_248_000);
});

// ─────────────────────────────────────────────────────────────────────
// Isolation entre utilisateurs
// ─────────────────────────────────────────────────────────────────────

it('never lets one user reprise another user\'s property', function () {
    $intruder = User::factory()->create();
    $property = repriseProperty($this->user);
    app(DepreciationService::class)->generateDefaultComponents($property);

    $before = PropertyComponent::withoutGlobalScopes()
        ->where('property_id', $property->id)->orderBy('id')->pluck('id')->all();

    // `propertyId` est une propriété Livewire publique : le navigateur peut la poser et
    // sauter tout le parcours. Le choix de méthode reste alors sans effet…
    Livewire::actingAs($intruder)
        ->test(RepriseDossier::class)
        ->set('propertyId', $property->id)
        ->call('chooseMethod', RepriseDossier::METHOD_SPREAD);

    $after = PropertyComponent::withoutGlobalScopes()
        ->where('property_id', $property->id)->orderBy('id')->pluck('id')->all();

    // … et pas seulement « même nombre de lignes » : ce sont les MÊMES lignes. Un
    // delete + regenerate aurait rendu six composants aux identifiants neufs.
    expect($after)->toBe($before);

    // … et l'écriture, elle, est refusée franchement : le bien n'existe pas pour ce compte.
    expect(fn () => Livewire::actingAs($intruder)
        ->test(RepriseDossier::class)
        ->set('propertyId', $property->id)
        ->call('saveComponents', [[
            'id' => null,
            'name' => 'Intrusion',
            'percentage' => 0,
            'baseAmount' => 100_000,
            'baseSource' => PropertyComponent::BASE_SOURCE_MANUAL,
            'duration' => 10,
            'sortOrder' => 1,
            'enabled' => true,
        ]]))->toThrow(\Illuminate\Database\Eloquent\ModelNotFoundException::class);

    expect(PropertyComponent::withoutGlobalScopes()->where('name', 'Intrusion')->count())->toBe(0);
});

it('keeps each user on their own opening balances', function () {
    $neighbour = User::factory()->create();
    repriseProperty($neighbour, ['name' => 'Bien du voisin']);
    $property = repriseProperty($this->user);

    FiscalYear::forceCreate([
        'user_id' => $neighbour->id,
        'year' => 2026,
        'status' => FiscalYear::STATUS_DRAFT,
        'opening_deferred_depreciation' => 9_999_900,
    ]);

    repriseAtStepFour($this->user, $property)
        ->set('openingDeferred', '12480')
        ->call('nextStep')
        ->call('finish');

    $mine = FiscalYear::withoutGlobalScopes()
        ->where('user_id', $this->user->id)->where('year', 2026)->firstOrFail();
    $theirs = FiscalYear::withoutGlobalScopes()
        ->where('user_id', $neighbour->id)->where('year', 2026)->firstOrFail();

    expect((int) $mine->opening_deferred_depreciation)->toBe(1_248_000)
        ->and((int) $theirs->opening_deferred_depreciation)->toBe(9_999_900);
});

// ─────────────────────────────────────────────────────────────────────
// Les deux portes d'entrée
// ─────────────────────────────────────────────────────────────────────

it('offers the reprise from the first-launch screen', function () {
    $this->actingAs($this->user)
        ->get('/onboarding-wizard')
        ->assertOk()
        ->assertSee('J\'ai déjà une comptabilité LMNP', false)
        ->assertSee('Je démarre une nouvelle location')
        ->assertSee(RepriseDossier::getUrl(), false);
});

it('offers the reprise from the fiscal years list', function () {
    repriseProperty($this->user);

    $this->actingAs($this->user)
        ->get('/fiscal-years')
        ->assertOk()
        ->assertSee('Reprendre un dossier')
        ->assertSee(RepriseDossier::getUrl(), false);
});
