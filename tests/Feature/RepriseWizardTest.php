<?php

use App\Filament\Pages\FiscalYearWizard;
use App\Filament\Pages\RepriseDossier;
use App\Models\FiscalYear;
use App\Models\Property;
use App\Models\PropertyComponent;
use App\Models\User;
use App\Services\DepreciationService;
use App\Services\ReprisesCheckService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
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

it('describes the acquisition price positively, without citing box 028 at all', function () {
    // ⚠️ Recopier la case 028 (qui agrège le bien, les travaux, le mobilier et les frais)
    // dans ce champ gonflait la base amortissable. L'aide disait « ce n'est pas la case
    // 028 » : elle dit désormais ce qu'il faut saisir, et où le lire sur la balance.
    $component = Livewire::actingAs($this->user)
        ->test(RepriseDossier::class)
        ->set('rentalStartDate', '2019-06-01')
        ->set('firstYear', 2026)
        ->call('nextStep');

    $html = $component->html();
    $start = strpos($html, 'for="rp-price"');
    $field = substr($html, $start, strpos($html, 'for="rp-notary"', $start) - $start);

    expect($field)->toContain('Prix d\'acquisition')
        ->toContain('terrain compris')
        ->toContain('compte 211')
        ->toContain('213')
        ->not->toContain('028')
        ->not->toContain('Ce n\'est pas');

    $help = file_get_contents(resource_path('views/help/reprise-dossier.blade.php'));
    $item = substr($help, strpos($help, '<strong>Prix d\'acquisition</strong>'));
    $item = substr($item, 0, strpos($item, '</li>'));

    expect($item)->not->toContain('028')->and($item)->toContain('211');
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
        // `false` : le texte est écrit en HTML brut dans la vue, son apostrophe n'est donc
        // pas échappée — `assertSee()` échappe par défaut et ne la trouverait pas.
        ->assertSee("Ne reproduit pas le plan d'amortissement de votre comptable", false)
        ->assertSee("Si vous avez un plan d'amortissement, recopiez-le.", false);
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

it('links to the works and furniture screens, so a cabinet\'s plan is not left incomplete', function () {
    // ⚠️ L'éditeur de l'étape 3 ne porte QUE les composants du bien (`property_components`) :
    // des travaux ou du mobilier déjà amortis par le cabinet n'ont aucune autre porte
    // d'entrée dans l'assistant. Sans ce renvoi, l'étape 5 affichait un écart sur « Total
    // des immobilisations brutes » que rien n'expliquait (+7 610 € dans le dossier de test).
    $property = repriseProperty($this->user);
    app(DepreciationService::class)->generateDefaultComponents($property);

    $component = Livewire::actingAs($this->user)
        ->test(RepriseDossier::class)
        ->set('rentalStartDate', '2019-06-01')
        ->set('firstYear', 2026)
        ->call('nextStep')
        ->call('nextStep')
        ->call('chooseMethod', RepriseDossier::METHOD_COPY);

    $component->assertSee('Des travaux ou du mobilier déjà amortis', false)
        ->assertSee(
            \App\Filament\Resources\PropertyWorks\PropertyWorkResource::getUrl('property', ['propertyId' => $property->id]),
            false,
        )
        ->assertSee(
            \App\Filament\Resources\Furniture\FurnitureResource::getUrl('property', ['propertyId' => $property->id]),
            false,
        );
});

it('bounds the opening cumul of works and furniture too, not only components', function () {
    // Contrepartie du renvoi ci-dessus : un cumul saisi sur ces écrans externes doit
    // recevoir la même borne que les composants, sous peine du même double comptage
    // (9 496 € affichés pour 4 736 € déclarés, signalé le 2026-09-09 pour les composants).
    $property = repriseProperty($this->user);
    app(DepreciationService::class)->generateDefaultComponents($property);

    $work = $property->works()->create([
        'description' => 'Toiture refaite',
        'work_date' => '2020-01-01',
        'amount' => 500_000,
        'duration_years' => 15,
        'is_dedicated' => true,
        'opening_accumulated_depreciation' => 50_000,
    ]);

    $furniture = $property->furniture()->create([
        'description' => 'Canapé-lit',
        'purchase_date' => '2020-01-01',
        'amount' => 80_000,
        'duration_years' => 5,
        'is_dedicated' => true,
        'opening_accumulated_depreciation' => 20_000,
    ]);

    Livewire::actingAs($this->user)
        ->test(RepriseDossier::class)
        ->set('rentalStartDate', '2019-06-01')
        ->set('firstYear', 2026)
        ->call('nextStep')
        ->call('nextStep')
        ->call('chooseMethod', RepriseDossier::METHOD_COPY)
        ->call('nextStep');

    expect($work->fresh()->opening_accumulated_year)->toBe(2025)
        ->and($furniture->fresh()->opening_accumulated_year)->toBe(2025);
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
        ->assertSee('2033-A, case 044')
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
        // 2033-A case 044 : le TOTAL des immobilisations brutes, que l'application
        // reconstitue à l'identique — 200 000 € de bien plus 16 000 € de frais de notaire,
        // que le cabinet a capitalisés comme nous. Le cumul (030) est laissé vide : une case
        // non renseignée ne se compare pas, elle ne vaut surtout pas zéro.
        ->set('declaredGrossAssets', '216000')
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

    expect($lines[ReprisesCheckService::LINE_GROSS_ASSETS]['declared'])->toBe(21_600_000)
        ->and($lines[ReprisesCheckService::LINE_GROSS_ASSETS]['computed'])->toBe(21_600_000)
        ->and($lines[ReprisesCheckService::LINE_DEFERRED_DEPRECIATION]['computed'])->toBe(1_248_000)
        ->and($lines[ReprisesCheckService::LINE_DEFICIT_CARRYFORWARD]['computed'])->toBe(125_000)
        ->and($lines[ReprisesCheckService::LINE_ACCUMULATED_DEPRECIATION]['verdict'])
            ->toBe(ReprisesCheckService::VERDICT_UNCHECKED);

    $component->assertSee('Votre reprise est fidèle à votre liasse.')
        ->assertSee('2033-A case 044')
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

    // La piste chiffrée passe en tête, devant celles qu'aucun montant n'appuie.
    expect($line['verdict'])->toBe(ReprisesCheckService::VERDICT_MISMATCH)
        ->and(array_column($line['diagnostics'], 'code'))->toBe([
            'acquisition_fees', 'rental_start_date', 'land_share', 'missing_component', 'market_value',
        ]);

    // ⚠️ Mais sans la case 044, rien ne distingue des frais passés en charges de frais
    // amortis en 016 par le cabinet : le bouton qui les passe en charges ne s'affiche pas.
    expect($component->instance()->acquisitionFeesExpensedByFirm())->toBeFalse();

    $component->assertDontSee('Passer mes frais en charges', false)
        ->assertSee('Terminer quand même');
});

it('shows the precise 030/016 explanation for a close gap, not a vague convention note', function () {
    // ⚠️ Même mécanique que le test précédent, mais avec des frais d'acquisition trop
    // petits pour franchir le seuil de 1 % : l'écart reste « proche », et doit porter la
    // cause précise plutôt que le texte générique de convention de prorata — régression du
    // 2026-10-15, un dossier de test où un écart de quelques centaines d'euros a été pris à
    // tort pour de l'arrondi.
    $property = repriseProperty($this->user, ['notary_fees' => 60_000, 'agency_fees' => 0]);

    $component = repriseAtStepFour($this->user, $property)
        ->set('openingDeferred', '0')
        ->call('nextStep');

    $computed = collect($component->instance()->report['lines'])
        ->firstWhere('key', ReprisesCheckService::LINE_ACCUMULATED_DEPRECIATION)['computed'];

    $component->call('goToStep', 4)
        ->set('openingAccumulated', RepriseDossier::eurosFromCents($computed - notaryCumulOf($property)))
        ->call('nextStep')
        ->assertSet('step', 5);

    $line = collect($component->instance()->report['lines'])
        ->firstWhere('key', ReprisesCheckService::LINE_ACCUMULATED_DEPRECIATION);

    expect($line['verdict'])->toBe(ReprisesCheckService::VERDICT_CLOSE);

    $component->assertSee('amortis en 030', false)
        ->assertSee('016', false)
        ->assertDontSee('il s\'explique le plus souvent par une convention', false);
});

it('switches the acquisition fees to charges and stops amortising them', function () {
    // ⚠️ Ce test vérifiait auparavant que le remède faisait TOMBER l'écart de la case 030,
    // parce que celle-ci mélangeait l'amortissement des frais à celui du corporel. Depuis le
    // 2026-09-05 les frais sont portés en 014/016, là où un cabinet les met : la case 030 ne
    // bouge donc plus quand on les passe en charges — c'est le signe que le mélange a cessé.
    // Ce qui reste vrai, et ce que ce test vérifie, c'est que le remède agit réellement.
    //
    // Depuis le 2026-10-15, le bouton n'est offert QUE si la case 044 de la liasse montre
    // que le cabinet ne porte pas les frais parmi ses immobilisations : 200 000 € de bien,
    // sans les 16 000 € de frais.
    $property = repriseProperty($this->user);

    $component = repriseAtStepFour($this->user, $property)
        ->set('openingDeferred', '12480')
        ->set('declaredGrossAssets', '200000')
        ->call('nextStep');

    $line = fn () => collect($component->instance()->report['lines'])
        ->firstWhere('key', ReprisesCheckService::LINE_ACCUMULATED_DEPRECIATION)['computed'];

    $gross = collect($component->instance()->report['lines'])
        ->firstWhere('key', ReprisesCheckService::LINE_GROSS_ASSETS);

    expect($gross['diagnostics'][0]['code'])->toBe('acquisition_fees_expensed')
        ->and($component->instance()->acquisitionFeesExpensedByFirm())->toBeTrue();

    $component->assertSee('Passer mes frais en charges, comme mon cabinet', false);

    $before = $line();
    $cumulAvant = notaryCumulOf($property);

    expect($cumulAvant)->toBeGreaterThan(0);

    $component->call('expenseAcquisitionFees');

    $property->refresh();

    expect($property->acquisition_fees_treatment)->toBe(Property::ACQUISITION_FEES_EXPENSED)
        // Les frais ne sont plus amortis du tout : c'est bien ce que demande l'utilisateur
        // dont le comptable les avait passés en charges l'année de l'acquisition.
        ->and(notaryCumulOf($property))->toBe(0)
        // ⚠️ Et la case 030 BAISSE d'exactement leur cumul. Ce test affirmait l'inverse
        // jusqu'au 2026-09-08 (« elle ne portait déjà plus les frais ») : c'était vrai tant
        // qu'ils étaient incorporels. Depuis qu'ils sont corporels, l'action a un effet
        // visible sur la ligne même que le contrôle compare — et c'est ce qui la rend utile.
        ->and($line())->toBe($before - $cumulAvant);
});

// ─────────────────────────────────────────────────────────────────────
// Le cumul repris ne doit pas se compter deux fois
// ─────────────────────────────────────────────────────────────────────

it('bounds an opening cumul on the last exercise the accountant kept', function () {
    // ⚠️ Le défaut signalé le 2026-09-09 : le cumul repris s'ajoutait à un rejeu portant sur
    // LES MÊMES exercices, et la case 030 valait le double (9 496 € pour 4 736 € déclarés).
    // L'assistant est le seul écran à connaître la réponse sans la demander : l'utilisateur
    // reprend l'exercice `firstYear`, donc son stock s'arrête à la clôture précédente.
    $property = repriseProperty($this->user);

    app(DepreciationService::class)->generateDefaultComponents($property);
    $property->components()->update(['opening_accumulated_depreciation' => 500000]);

    Livewire::actingAs($this->user)
        ->test(RepriseDossier::class)
        ->set('rentalStartDate', '2019-06-01')
        ->set('firstYear', 2026)
        ->set('regime', RepriseDossier::REGIME_SINCE_START)
        ->call('nextStep')
        ->call('nextStep')
        ->call('chooseMethod', RepriseDossier::METHOD_COPY)
        ->call('nextStep')
        ->assertSet('step', 4);

    // firstYear = 2026, donc le stock couvre les exercices jusqu'à 2025 inclus.
    expect($property->components()->pluck('opening_accumulated_year')->unique()->all())
        ->toBe([2025]);
});

it('never overwrites a bound the user set by hand', function () {
    // Une borne saisie appartient à l'utilisateur : son cabinet a pu s'arrêter avant.
    // ⚠️ Les composants se créent ICI et pas via `repriseAtStepFour()`, qui les régénère
    // et produirait un second jeu — l'assertion porterait alors sur deux populations.
    $property = repriseProperty($this->user);

    app(DepreciationService::class)->generateDefaultComponents($property);
    $property->components()->update([
        'opening_accumulated_depreciation' => 500000,
        'opening_accumulated_year' => 2022,
    ]);

    Livewire::actingAs($this->user)
        ->test(RepriseDossier::class)
        ->set('rentalStartDate', '2019-06-01')
        ->set('firstYear', 2026)
        ->set('regime', RepriseDossier::REGIME_SINCE_START)
        ->call('nextStep')
        ->call('nextStep')
        ->call('chooseMethod', RepriseDossier::METHOD_COPY)
        ->call('nextStep');

    expect($property->components()->pluck('opening_accumulated_year')->unique()->all())
        ->toBe([2022]);
});

it('leaves components without an opening cumul unbounded', function () {
    // Sans stock repris, il n'y a rien à borner : le rejeu doit courir depuis l'origine.
    $property = repriseProperty($this->user);

    repriseAtStepFour($this->user, $property);

    expect($property->components()->whereNotNull('opening_accumulated_year')->count())->toBe(0);
});

// ─────────────────────────────────────────────────────────────────────
// La part du terrain, et ses décimales
// ─────────────────────────────────────────────────────────────────────

it('keeps the decimals of the land share instead of truncating them', function () {
    // ⚠️ TROIS défauts au même endroit, et le premier m'a fait chercher au mauvais endroit.
    // La fiche du bien, elle, acceptait déjà les décimales (Filament rend `step="any"`).
    // C'est l'assistant qui bloquait : son `<input type="number">` n'avait PAS de `step`,
    // donc `step="1"` par défaut et un navigateur qui refuse 17,5 ; puis deux `(int)` qui
    // tronquaient à 17 ce qui aurait pu passer.
    //
    // 17,5 % sur 200 000 € : base 165 000 €. Tronqué à 17 % : 166 000 €, soit 1 000 € de
    // trop — plus que la tolérance de l'écran de contrôle.
    $property = repriseProperty($this->user);

    Livewire::actingAs($this->user)
        ->test(RepriseDossier::class)
        ->set('rentalStartDate', '2019-06-01')
        ->set('firstYear', 2026)
        ->set('regime', RepriseDossier::REGIME_SINCE_START)
        ->call('nextStep')
        ->set('acquisitionPrice', '200000')
        ->set('landPercentage', '17.5')
        ->call('nextStep')
        ->assertSet('step', 3);

    $property->refresh();

    expect((float) $property->land_percentage)->toBe(17.5)
        ->and((int) $property->depreciable_base)->toBe(16_500_000)
        ->and((int) $property->depreciable_base)->not->toBe(16_600_000);
});

it('renders a decimal step on the land share cell', function () {
    // Le blocage tel que l'utilisateur l'a rencontré : sans `step`, le navigateur refuse
    // la saisie avant qu'aucune ligne de PHP ne tourne. Aucune assertion sur la base de
    // données ne peut le mesurer.
    // ⚠️ Le champ vit à l'ÉTAPE 2 : un GET sur la page rend l'étape 1 et ne le contient
    // pas. Une assertion sur le premier rendu serait restée verte quoi qu'il arrive.
    repriseProperty($this->user);

    $html = Livewire::actingAs($this->user)
        ->test(RepriseDossier::class)
        ->set('rentalStartDate', '2019-06-01')
        ->set('firstYear', 2026)
        ->set('regime', RepriseDossier::REGIME_SINCE_START)
        ->call('nextStep')
        ->assertSet('step', 2)
        ->html();

    preg_match('/<input[^>]*id="rp-land"[^>]*>/', $html, $input);

    expect($input)->not->toBeEmpty()
        ->and($input[0])->toContain('step="0.01"');
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

it('survives a page reload after finishing : method and reports are not lost', function () {
    // ⚠️ Régression du 2026-10-15 : rien ne réveillait l'écran final à un nouveau montage
    // du composant (rechargement de la page, nouvel onglet) — un simple `Livewire::test()`
    // frais retombait sur une page 1 vierge, méthode et reports compris. « Revoir ma
    // reprise », cliqué APRÈS un tel rechargement, affichait donc un formulaire vide qui
    // ne reflétait plus ce qui avait été repris.
    $property = repriseProperty($this->user);

    repriseAtStepFour($this->user, $property)
        ->set('openingDeferred', '12480')
        ->set('openingAccumulated', '47394')
        ->call('addDeficit')
        ->set('deficits.0.origin_year', 2023)
        ->set('deficits.0.amount', '1250')
        ->call('nextStep')
        ->call('finish')
        ->assertSet('finished', true);

    // Nouveau montage du composant, comme un rechargement de page ou un nouvel onglet.
    $fresh = Livewire::actingAs($this->user)->test(RepriseDossier::class);

    $fresh->assertSet('finished', true)
        ->assertSet('method', RepriseDossier::METHOD_COPY)
        ->assertSet('openingDeferred', '12480')
        ->assertSet('openingAccumulated', '47394')
        ->assertSet('deficits', [['origin_year' => 2023, 'amount' => '1250']])
        ->assertSee('Votre dossier est repris');

    // « Revoir ma reprise » doit alors retomber sur le contrôle, avec les BONS montants —
    // pas un formulaire d'étape 4 vierge qui rejouerait la reprise depuis zéro.
    $fresh->call('reviewReprise')
        ->assertSet('finished', false)
        ->assertSet('step', 5);

    $line = collect($fresh->instance()->report['lines'])
        ->firstWhere('key', ReprisesCheckService::LINE_DEFERRED_DEPRECIATION);

    expect($line['declared'])->toBe(1_248_000);
});

it('still starts a blank wizard when no reprise was ever finished for this property', function () {
    // Contre-épreuve : le réveil ne doit se déclencher QUE si un exercice de reprise a
    // réellement été clôturé — un dossier neuf, ou en cours de route, repart bien à vide.
    repriseProperty($this->user);

    Livewire::actingAs($this->user)
        ->test(RepriseDossier::class)
        ->assertSet('finished', false)
        ->assertSet('step', 1)
        ->assertSet('method', null);
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

// ─────────────────────────────────────────────────────────────────────
// Étape 3 — lecture IA de la liasse (lot 6)
// ─────────────────────────────────────────────────────────────────────

/** Configuration d'instance qui rend la lecture IA disponible (fournisseur simulé). */
function repriseAiConfig(): void
{
    config([
        'ai.enabled' => true,
        'ai.api_key' => 'sk-or-v1-factice-pour-les-tests',
        // Épinglé : l'hôte de test n'est pas openrouter.ai, la détection
        // automatique le classerait donc en serveur local.
        'ai.provider' => 'openrouter',
        'ai.base_url' => 'https://openrouter.test/api/v1',
        'ai.models.documents' => 'modele/test',
        'ai.models.documents_fallback' => '',
        'ai.pdf_text_fallback' => 'never',
        'ai.docs_monthly_quota' => 0,
        'demo.email' => 'demo@openlmnp.fr',
    ]);
}

/** Fait répondre le fournisseur simulé avec la fixture de liasse. */
function faireLireLaLiasse(): void
{
    Http::fake(['https://openrouter.test/api/v1/chat/completions' => Http::response([
        'id' => 'gen-test',
        'model' => 'modele/test',
        'choices' => [[
            'message' => [
                'role' => 'assistant',
                'content' => (string) file_get_contents(base_path('tests/Fixtures/ai/liasse-2033.json')),
            ],
            'finish_reason' => 'stop',
        ]],
        'usage' => ['prompt_tokens' => 4000, 'completion_tokens' => 500, 'cost' => 0.0024],
    ])]);
}

/**
 * Fait répondre le fournisseur simulé avec une liasse portant une ligne « Terrains ».
 * Fixture fictive (2033-C, terrain de 27 150 € sans durée ni dotation).
 */
function faireLireLaLiasseAvecTerrain(): void
{
    Http::fake(['https://openrouter.test/api/v1/chat/completions' => Http::response([
        'id' => 'gen-test',
        'model' => 'modele/test',
        'choices' => [[
            'message' => [
                'role' => 'assistant',
                'content' => (string) file_get_contents(base_path('tests/Fixtures/ai/liasse-2033-with-land.json')),
            ],
            'finish_reason' => 'stop',
        ]],
        'usage' => ['prompt_tokens' => 4000, 'completion_tokens' => 500, 'cost' => 0.0024],
    ])]);
}

/** PDF minimal reconnu comme tel par finfo — l'analyse vérifie le type réel du fichier. */
function fauxPdfLiasse(): UploadedFile
{
    return UploadedFile::fake()->createWithContent(
        'liasse-2025.pdf',
        "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n1 0 obj\n<<>>\nendobj\ntrailer\n<<>>\n%%EOF",
    );
}

/** Amène l'assistant à l'étape 3, méthode IA choisie, fournisseur simulé prêt. */
function repriseAtStepThreeAi(User $user): \Livewire\Features\SupportTesting\Testable
{
    repriseAiConfig();
    Storage::fake('local');
    faireLireLaLiasse();

    return Livewire::actingAs($user)
        ->test(RepriseDossier::class)
        ->set('rentalStartDate', '2019-06-01')
        ->set('firstYear', 2026)
        ->set('regime', RepriseDossier::REGIME_SINCE_START)
        ->call('nextStep')
        ->call('nextStep')
        ->call('chooseMethod', RepriseDossier::METHOD_AI);
}

it('ignore la méthode IA quand l\'IA de l\'instance est coupée', function () {
    repriseProperty($this->user);

    Livewire::actingAs($this->user)
        ->test(RepriseDossier::class)
        ->set('rentalStartDate', '2019-06-01')
        ->set('firstYear', 2026)
        ->set('regime', RepriseDossier::REGIME_SINCE_START)
        ->call('nextStep')
        ->call('nextStep')
        ->call('chooseMethod', RepriseDossier::METHOD_AI)
        ->assertSet('method', null);
});

it('refuse la méthode IA à un compte démo, même quand l\'instance l\'active', function () {
    repriseAiConfig();

    $demo = User::factory()->create(['is_demo' => true]);
    repriseProperty($demo);

    Livewire::actingAs($demo)
        ->test(RepriseDossier::class)
        ->set('rentalStartDate', '2019-06-01')
        ->set('firstYear', 2026)
        ->set('regime', RepriseDossier::REGIME_SINCE_START)
        ->call('nextStep')
        ->call('nextStep')
        ->call('chooseMethod', RepriseDossier::METHOD_AI)
        ->assertSet('method', null);
});

it('ne lance rien sans la case de consentement cochée', function () {
    repriseProperty($this->user);

    repriseAtStepThreeAi($this->user)
        ->set('liasseFile', fauxPdfLiasse())
        ->call('analyzeLiasse')
        ->assertSet('liasseAnalysis', null)
        ->assertSet('liasseError', 'Cochez la case de consentement avant de lancer la lecture.');

    Http::assertNothingSent();

    // Sans envoi, pas d'horodatage de consentement posé.
    expect($this->user->fresh()->ai_consented_at)->toBeNull();
});

it('retient l\'étape 3 tant qu\'une lecture IA n\'a pas été appliquée : pas de plan vide', function () {
    repriseProperty($this->user);

    repriseAtStepThreeAi($this->user)
        ->call('nextStep')
        ->assertSet('step', 3)
        ->assertSee('Lancez la lecture puis');
});

it('lit la liasse, impose la relecture, puis recopie lignes et reports d\'un clic', function () {
    $property = repriseProperty($this->user);

    $wizard = repriseAtStepThreeAi($this->user)
        ->set('liasseConsent', true)
        ->set('liasseFile', fauxPdfLiasse())
        ->call('analyzeLiasse')
        ->assertSet('liasseError', null)
        ->assertSet('liasseApplied', false);

    // La lecture n'est qu'une PROPOSITION : rien n'est écrit avant le clic de relecture.
    expect(PropertyComponent::withoutGlobalScopes()->where('property_id', $property->id)->count())->toBe(0)
        ->and($this->user->fresh()->ai_consented_at)->not->toBeNull();

    $wizard->call('applyLiasse')
        ->assertSet('liasseApplied', true)
        ->assertSet('method', RepriseDossier::METHOD_AI)
        // Étape 4 pré-remplie avec les SEULES cases lues : la case 014, absente
        // de la liasse, laisse son champ vide — jamais un zéro à sa place.
        ->assertSet('openingDeferred', '12480')
        ->assertSet('openingAccumulated', '47394')
        // Case 044 : la liasse ne porte que la 028, aucune incorporelle (014 vide).
        ->assertSet('declaredGrossAssets', '200000')
        ->assertSet('deficits', [['origin_year' => 2023, 'amount' => '1250']]);

    $components = PropertyComponent::withoutGlobalScopes()
        ->where('property_id', $property->id)
        ->orderBy('sort_order')
        ->get();

    // 8 lignes lues, 7 recopiées : les frais d'acquisition (incorporelles) sont
    // écartés — l'application les amortit déjà depuis les frais de l'étape 2.
    expect($components)->toHaveCount(7)
        ->and($components->pluck('name'))->not->toContain('Frais d\'acquisition')
        ->and($components[0]->name)->toBe('Gros œuvre')
        ->and((int) $components[0]->base_amount)->toBe(8_000_000)
        ->and((int) $components[0]->duration_years)->toBe(50)
        ->and((int) $components[0]->opening_accumulated_depreciation)->toBe(1_040_000)
        ->and($components[0]->base_source)->toBe(PropertyComponent::BASE_SOURCE_MANUAL)
        // La dotation du cabinet est recopiée AU CENTIME (566,67 €) : un recalcul
        // base ÷ durée donnerait 56 666 centimes, pas 56 667.
        ->and((int) $components[3]->annual_depreciation)->toBe(56_667);
});

/**
 * Un terrain ne s'amortit JAMAIS. Recopier la ligne 420 d'un 2033-C créait un composant
 * sans durée imprimée par la liasse (l'assistant lui en complétait une par défaut, 10 ans)
 * et donc une dotation fictive — constaté lors d'un test de reprise.
 */
it('refuse de recopier la ligne "Terrains" d\'une liasse comme composant amortissable', function () {
    $property = repriseProperty($this->user);

    repriseAiConfig();
    Storage::fake('local');
    faireLireLaLiasseAvecTerrain();

    $wizard = Livewire::actingAs($this->user)
        ->test(RepriseDossier::class)
        ->set('rentalStartDate', '2019-06-01')
        ->set('firstYear', 2026)
        ->set('regime', RepriseDossier::REGIME_SINCE_START)
        ->call('nextStep')
        ->call('nextStep')
        ->call('chooseMethod', RepriseDossier::METHOD_AI)
        ->set('liasseConsent', true)
        ->set('liasseFile', fauxPdfLiasse())
        ->call('analyzeLiasse')
        ->assertSet('liasseError', null)
        ->call('applyLiasse')
        ->assertSet('liasseApplied', true);

    $components = PropertyComponent::withoutGlobalScopes()
        ->where('property_id', $property->id)
        ->get();

    // Les deux lignes de bâti sont recopiées, la ligne "Terrains" ne l'est jamais.
    expect($components)->toHaveCount(2)
        ->and($components->pluck('name'))->not->toContain('Terrains')
        ->and($components->pluck('name')->all())->toBe(['Gros œuvre', 'Toiture']);

    $wizard->assertNotified('Terrain non recopié : Terrains');
});

it('grave la provenance « lecture assistée » et porte le résultat fiscal lu au contrôle', function () {
    repriseProperty($this->user);

    $wizard = repriseAtStepThreeAi($this->user)
        ->set('liasseConsent', true)
        ->set('liasseFile', fauxPdfLiasse())
        ->call('analyzeLiasse')
        ->call('applyLiasse')
        ->call('nextStep')
        ->assertSet('step', 4)
        ->call('nextStep')
        ->assertSet('step', 5);

    // Le résultat fiscal (2033-B) n'est connu que par la lecture : la ligne du
    // contrôle le reprend, en négatif puisque la liasse porte un déficit (case 354).
    $fiscal = collect($wizard->get('report')['lines'])
        ->firstWhere('key', ReprisesCheckService::LINE_FISCAL_RESULT);

    expect($fiscal)->not->toBeNull()
        ->and($fiscal['declared'])->toBe(-85_000);

    $wizard->call('finish')->assertSet('finished', true);

    $fiscalYear = FiscalYear::withoutGlobalScopes()
        ->where('user_id', $this->user->id)
        ->where('year', 2026)
        ->firstOrFail();

    expect($fiscalYear->opening_source)->toBe(FiscalYear::OPENING_SOURCE_AI)
        ->and((int) $fiscalYear->opening_deferred_depreciation)->toBe(1_248_000)
        ->and((int) $fiscalYear->opening_accumulated_depreciation)->toBe(4_739_400)
        ->and($fiscalYear->opening_deficits)->toBe([['origin_year' => 2023, 'amount' => 125_000]]);
});

it('abandonner la lecture supprime le fichier déposé et ne laisse rien derrière', function () {
    $property = repriseProperty($this->user);

    $wizard = repriseAtStepThreeAi($this->user)
        ->set('liasseConsent', true)
        ->set('liasseFile', fauxPdfLiasse())
        ->call('analyzeLiasse');

    $chemin = $wizard->get('liasseAnalysis')['file_path'];

    // ⚠️ Sans cette garde, le test est VACUEMENT vert : assertExists(null)
    // boucle sur Arr::wrap(null) = [] et ne vérifie rien du tout.
    expect($chemin)->not->toBeNull();
    Storage::disk()->assertExists($chemin);

    $wizard->call('discardLiasse')
        ->assertSet('liasseAnalysis', null)
        ->assertSet('liasseApplied', false);

    Storage::disk()->assertMissing($chemin);

    expect(PropertyComponent::withoutGlobalScopes()->where('property_id', $property->id)->count())->toBe(0);
});

it('affiche le refus quand aucune ligne lue ne porte de valeur brute', function () {
    repriseProperty($this->user);

    // Lecture dégradée : des lignes sans base — rien d'exploitable à recopier.
    repriseAtStepThreeAi($this->user)
        ->set('liasseAnalysis', [
            'document_type' => 'liasse_2033',
            'confidence' => 0.4,
            'values' => [],
            'rows' => [
                ['label' => 'Gros œuvre', 'base' => null, 'duration_years' => null, 'annual' => null, 'cumul' => null, 'cerfa_category' => 'constructions', 'confidence' => 0.3],
                // Une base lue « 0 » vaut absence : un composant à base nulle
                // polluerait le plan (constaté sur une vraie liasse, 2026-09-06).
                ['label' => 'Constructions', 'base' => 0, 'duration_years' => 1, 'annual' => 70260, 'cumul' => 70260, 'cerfa_category' => 'constructions', 'confidence' => 0.5],
            ],
            'file_path' => 'documents/1/ai-staging/liasse-test.pdf',
            'file_name' => 'liasse-test.pdf',
        ])
        ->call('applyLiasse')
        ->assertSet('liasseApplied', false)
        // L'erreur doit être VISIBLE dans l'écran de relecture — un clic sans
        // effet apparent est le pire des refus.
        ->assertSee('Aucune ligne exploitable');

    expect(PropertyComponent::withoutGlobalScopes()->count())->toBe(0);
});

// ─────────────────────────────────────────────────────────────────────
// Étape 3 — le dépôt de la liasse (lot ergonomie, 2026-09-24)
// ─────────────────────────────────────────────────────────────────────

/**
 * Le dépôt était un `<input type="file">` nu — le seul du dépôt, partout ailleurs on passe
 * par le `FileUpload` de Filament. Le navigateur rendait « Choisir un fichier / Aucun
 * fichier choisi », invisible sur un écran qui demande par ailleurs un consentement.
 *
 * ⚠️ Ce que ce test protège n'est PAS l'esthétique du cadre : c'est le fait que l'input
 * reste atteignable au clavier. La manière habituelle d'habiller un dépôt de fichier est
 * de masquer l'input en `display: none` et de piloter un bouton en JavaScript — ce qui le
 * sort du parcours de tabulation, et fait dépendre le clic du JavaScript.
 */
it('habille le dépôt de la liasse sans le retirer du parcours clavier', function () {
    repriseProperty($this->user);

    $html = repriseAtStepThreeAi($this->user)->html();

    expect($html)
        ->toContain('Glissez votre liasse ici, ou cliquez pour la choisir')
        ->toContain('rp-drop-input')
        // L'intitulé est rattaché au champ : cliquer dessus ouvre le sélecteur, et un
        // lecteur d'écran annonce le champ.
        ->toContain('for="rp-liasse-file"')
        ->toContain('id="rp-liasse-file"');

    // La règle qui masque l'input, telle qu'elle est réellement servie.
    preg_match('/\.rp-drop-input\s*\{([^}]*)\}/', $html, $regle);

    expect($regle[1] ?? '')
        ->toContain('opacity: 0')
        // `display: none` et `visibility: hidden` retirent l'élément de la tabulation, et
        // le glisser-déposer natif du navigateur ne l'atteint plus non plus.
        ->not->toContain('display: none')
        ->not->toContain('display:none')
        ->not->toContain('visibility: hidden');
});

/**
 * Le nom du fichier choisi venait du rendu natif du navigateur. Le cadre le masque : il
 * faut donc le réafficher, sinon l'écran ne dit plus du tout ce qu'on s'apprête à envoyer.
 */
it('réaffiche le nom du fichier déposé, que le cadre masque au navigateur', function () {
    repriseProperty($this->user);

    $html = repriseAtStepThreeAi($this->user)
        ->set('liasseFile', fauxPdfLiasse())
        ->html();

    expect($html)->toContain('liasse-2025.pdf');
});

// ─────────────────────────────────────────────────────────────────────
// Étape 3 — l'attente de la lecture (lot ergonomie, 2026-09-24)
// ─────────────────────────────────────────────────────────────────────

/**
 * L'attente se jouait en petit dans le flux, sous forme d'une liste d'étapes SIMULÉE :
 * trois lignes qui s'allumaient sur un `setInterval` de 4 secondes, sans le moindre rapport
 * avec le serveur, et une quatrième sans liaison qui ne s'allumait jamais.
 *
 * ⚠️ Ce que ce test verrouille n'est pas la modale — c'est l'ABSENCE de ces étapes. Les
 * recopier au centre de l'écran, en grand, aurait affirmé plus fort ce qu'on ne sait pas :
 * l'application envoie le document en une fois et attend, elle n'a aucun moyen de savoir
 * que « les tableaux 2033-A à 2033-D ont été repérés ».
 */
it('attend la lecture dans une modale, sans afficher d\'étapes qu\'elle ne connaît pas', function () {
    repriseProperty($this->user);

    $html = repriseAtStepThreeAi($this->user)->html();

    // La modale existe, et elle dit franchement ce qu'elle sait.
    expect($html)
        ->toContain('rp-modal-backdrop')
        ->toContain('Lecture de votre liasse en cours')
        ->toContain('ne vous montrera donc pas d\'étapes qu\'elle ne connaît pas');

    // Les quatre fausses étapes ont disparu, et ne doivent jamais revenir.
    expect($html)
        ->not->toContain('Document transmis au modèle')
        ->not->toContain('Tableaux 2033-A à 2033-D repérés')
        ->not->toContain('Lignes d\'amortissement et cases de report extraites')
        ->not->toContain('Contrôle de cohérence (ligne 572)');
});

/**
 * Une modale qui ne prévoit que le cas heureux est un piège. Trois sorties, toutes
 * présentes dans le markup : l'échec (la fenêtre se ferme et l'écran dit pourquoi),
 * l'abandon (on la masque sans annuler la lecture) et la touche Échap.
 */
it('laisse toujours une sortie à la modale d\'attente, et des astuces qui apprennent quelque chose', function () {
    repriseProperty($this->user);

    $html = repriseAtStepThreeAi($this->user)->html();

    expect($html)
        ->toContain('Masquer cette fenêtre')
        ->toContain('keydown.escape.window')
        ->toContain('La masquer n\'annule pas la lecture')
        // La première astuce est rendue par le serveur : sans JavaScript, l'encart
        // n'est pas vide. ⚠️ Assertion sans apostrophe : `{{ }}` échappe la sienne en
        // `&#039;`, et le motif ne se retrouverait pas dans le HTML servi.
        ->toContain('qui dira si le tableau a été lu en entier');

    // Chaque astuce tient sur une ligne : une astuce qui déborde n'est plus une astuce.
    foreach (app(RepriseDossier::class)->liasseWaitTips() as $astuce) {
        expect(mb_strlen($astuce))->toBeLessThanOrEqual(130);
    }
});

/**
 * Le motif d'un refus était rendu par un `<span>` de 11,5 px sous le champ de dépôt —
 * invisible pour qui regarde le bouton qu'il vient de cliquer.
 */
it('affiche le refus de lecture en encart, avec sa sortie de secours', function () {
    repriseProperty($this->user);

    repriseAtStepThreeAi($this->user)
        ->set('liasseFile', fauxPdfLiasse())
        ->call('analyzeLiasse')
        ->assertSet('liasseAnalysis', null)
        ->assertSee('La lecture n\'a pas eu lieu.', false)
        ->assertSee('Cochez la case de consentement avant de lancer la lecture.')
        ->assertSee('recopier les lignes de votre liasse à la main', false);
});

// ─────────────────────────────────────────────────────────────────────
// Étape 3 — disposition de l'écran de relecture (lot ergonomie, 2026-09-24)
// ─────────────────────────────────────────────────────────────────────

/**
 * L'écran plaçait l'aperçu du document à gauche (5fr) et la lecture à droite (7fr), pour
 * permettre une comparaison œil à œil. Arbitré par Jérémie : cette comparaison n'existait
 * pas, une liasse affichée sur un demi-écran étant ILLISIBLE.
 *
 * Les deux tableaux lus passent donc côte à côte en haut, l'aperçu sur toute la largeur en
 * dessous — et la conséquence se traite dans le même geste : la comparaison passant par le
 * défilement, l'aperçu doit devenir exploitable (pagination, zoom, page entière).
 */
it('empile la relecture : les deux tableaux lus en haut, l\'aperçu sur toute la largeur', function () {
    repriseProperty($this->user);

    $html = repriseAtStepThreeAi($this->user)
        ->set('liasseConsent', true)
        ->set('liasseFile', fauxPdfLiasse())
        ->call('analyzeLiasse')
        ->html();

    // Deux colonnes égales, et non plus l'aperçu contre la lecture.
    preg_match('/\.rp-ai-review\s*\{([^}]*)\}/', $html, $grille);
    expect($grille[1] ?? '')
        ->toContain('repeat(2, minmax(0, 1fr))')
        ->not->toContain('5fr');

    // L'aperçu vient APRÈS la grille des deux tableaux, hors d'elle.
    $grillePos = strpos($html, 'class="rp-ai-review"');
    $apercuPos = strpos($html, 'class="rp-ai-doc"');

    expect($grillePos)->not->toBeFalse()
        ->and($apercuPos)->not->toBeFalse()
        ->and($apercuPos)->toBeGreaterThan($grillePos);

    // Le repli sous 900 px reste en place, pour les deux moitiés de l'écran.
    expect($html)
        ->toContain('@media (max-width: 900px) { .rp-ai-review { grid-template-columns: 1fr; } }')
        ->toContain('height: 60vh');
});

/**
 * Sans pagination ni zoom, un aperçu que l'on ne peut plus comparer d'un coup d'œil ne sert
 * plus à rien : c'est la contrepartie de l'empilement.
 */
it('rend l\'aperçu de la liasse exploitable : pagination, zoom, page entière', function () {
    repriseProperty($this->user);

    $html = repriseAtStepThreeAi($this->user)
        ->set('liasseConsent', true)
        ->set('liasseFile', fauxPdfLiasse())
        ->call('analyzeLiasse')
        ->html();

    expect($html)
        ->toContain('Page précédente')
        ->toContain('Page suivante')
        ->toContain('rp-ai-doc-zoom')
        ->toContain('Ouvrir dans un onglet')
        // La navigation passe par les `open parameters` du PDF : c'est ce qui évite
        // d'embarquer pdf.js pour tourner sept pages.
        ->toContain('#page=1&amp;zoom=100');

    // Une page entière, et non plus les 420 px d'avant.
    preg_match('/\.rp-ai-doc iframe\s*\{([^}]*)\}/', $html, $cadre);
    expect($cadre[1] ?? '')
        ->toContain('min-height: 520px')
        ->not->toContain('420px');
});

// ─────────────────────────────────────────────────────────────────────
// Étape 3 — le refus de ventilation devient actionnable (lot ergonomie, 2026-09-24)
// ─────────────────────────────────────────────────────────────────────

/**
 * Lecture dont les lignes débordent la base amortissable : 100 000 € de prix, 15 % de
 * terrain, donc 85 000 € à ventiler — et 100 000 € de lignes lues.
 */
function liasseQuiDeborde(): array
{
    $ligne = fn (string $label, int $base) => [
        'label' => $label,
        'base' => $base,
        'duration_years' => 20,
        'annual' => (int) ($base / 20),
        'cumul' => 0,
        'cerfa_category' => 'constructions',
        'confidence' => 0.9,
    ];

    return [
        'document_type' => 'liasse_2033',
        'confidence' => 0.9,
        'values' => [],
        'rows' => [
            $ligne('Gros œuvre', 5_000_000),
            $ligne('Toiture', 3_000_000),
            $ligne('Terrain de sport attenant', 2_000_000),
        ],
        'file_path' => 'documents/1/ai-staging/liasse-test.pdf',
        'file_name' => 'liasse-test.pdf',
    ];
}

/**
 * Le refus était juste — rien n'est écrit, ce n'est pas un faux positif — mais il tenait
 * dans un message de 11,5 px qui disait quoi faire sans offrir le moindre moyen de le faire.
 */
it('rend le refus de ventilation actionnable, au lieu d\'un message sans bouton', function () {
    $property = repriseProperty($this->user, ['acquisition_price' => 10_000_000]);

    $wizard = repriseAtStepThreeAi($this->user)
        ->set('liasseAnalysis', liasseQuiDeborde())
        ->call('applyLiasse');

    // Le refus refuse VRAIMENT : rien n'est écrit.
    expect(PropertyComponent::withoutGlobalScopes()->where('property_id', $property->id)->count())->toBe(0);

    $wizard->assertSet('liasseApplied', false)
        ->assertSee('La ventilation dépasse la base amortissable')
        // Les trois remèdes que le message nommait, désormais à portée de clic.
        ->assertSee('Corriger la valeur retenue ou la part du terrain')
        ->assertSee('Écarter les lignes qui ne portent pas sur le bâti')
        ->assertSee('Revenir à l\'étape 2', false)
        // Et les lignes à décocher, avec leur montant.
        ->assertSee('Terrain de sport attenant');

    expect($wizard->get('liasseOverflow'))->not->toBeNull();
});

it('écarte une ligne depuis la modale, et la recopie passe', function () {
    $property = repriseProperty($this->user, ['acquisition_price' => 10_000_000]);

    $wizard = repriseAtStepThreeAi($this->user)
        ->set('liasseAnalysis', liasseQuiDeborde())
        ->call('applyLiasse')
        ->assertSet('liasseApplied', false);

    // La troisième ligne, 20 000 € de trop.
    $wizard->call('toggleLiasseRow', 2)
        ->call('applyLiasse')
        ->assertSet('liasseApplied', true)
        ->assertSet('liasseOverflow', null);

    $composants = PropertyComponent::withoutGlobalScopes()
        ->where('property_id', $property->id)
        ->pluck('name');

    expect($composants)->toHaveCount(2)
        ->and($composants)->not->toContain('Terrain de sport attenant');
});

it('corrige la valeur retenue depuis la modale, sans quitter l\'écran', function () {
    $property = repriseProperty($this->user, ['acquisition_price' => 10_000_000]);

    repriseAtStepThreeAi($this->user)
        ->set('liasseAnalysis', liasseQuiDeborde())
        ->call('applyLiasse')
        ->assertSet('liasseApplied', false)
        // Le comptable avait retenu 200 000 € : la base passe à 170 000 €, les lignes tiennent.
        ->set('acquisitionPrice', '200000')
        ->call('fixPropertyFromOverflow')
        ->assertSet('liasseOverflow', null)
        ->assertSet('liasseApplied', true);

    expect((int) $property->fresh()->acquisition_price)->toBe(20_000_000)
        ->and(PropertyComponent::withoutGlobalScopes()->where('property_id', $property->id)->count())->toBe(3);
});

it('refuse une correction illisible plutôt que d\'écrire un prix nul', function () {
    $property = repriseProperty($this->user, ['acquisition_price' => 10_000_000]);

    repriseAtStepThreeAi($this->user)
        ->set('liasseAnalysis', liasseQuiDeborde())
        ->call('applyLiasse')
        ->set('acquisitionPrice', 'beaucoup')
        ->call('fixPropertyFromOverflow')
        // La modale reste ouverte, et dit pourquoi. ⚠️ Message interpolé par `{{ }}` :
        // son apostrophe EST échappée, donc `assertSee()` par défaut — contrairement aux
        // textes écrits en HTML brut dans la vue, qui demandent le second argument à `false`.
        ->assertSee('Indiquez le prix d\'acquisition du bien.');

    expect((int) $property->fresh()->acquisition_price)->toBe(10_000_000);
});

it('n\'accepte pas un index de ligne inventé par le navigateur', function () {
    repriseProperty($this->user, ['acquisition_price' => 10_000_000]);

    repriseAtStepThreeAi($this->user)
        ->set('liasseAnalysis', liasseQuiDeborde())
        ->call('applyLiasse')
        ->call('toggleLiasseRow', 99)
        ->assertSet('liasseExcludedRows', []);
});

// ─────────────────────────────────────────────────────────────────────
// Correctifs de la recette bêta du 2026-10-15 (dossier de test rejoué)
// ─────────────────────────────────────────────────────────────────────

it('treats a finished reprise without deferred depreciation nor deficit as the start of the chain', function () {
    // D14 : le cas le plus courant d'un loueur bénéficiaire. La liasse ne porte ni
    // amortissement différé ni déficit, la reprise est terminée, et l'assistant de clôture
    // réclamait pourtant l'exercice précédent, que l'utilisateur n'a jamais tenu ici.
    $property = repriseProperty($this->user);

    repriseAtStepFour($this->user, $property)
        ->set('openingDeferred', '0')
        ->set('openingAccumulated', '47394')
        ->call('nextStep')
        ->call('finish')
        ->assertSet('finished', true);

    $fiscalYear = FiscalYear::withoutGlobalScopes()
        ->where('user_id', $this->user->id)->where('year', 2026)->firstOrFail();

    expect((int) $fiscalYear->opening_deferred_depreciation)->toBe(0)
        ->and($fiscalYear->openingDeficitsTotal())->toBe(0)
        ->and($fiscalYear->hasOpeningBalances())->toBeTrue();

    Livewire::actingAs($this->user)
        ->test(FiscalYearWizard::class)
        ->set('data.year', 2026)
        ->assertDontSee('L\'exercice 2025 n\'existe pas', false)
        ->set('data.status', FiscalYear::STATUS_CLOSED)
        ->call('create')
        ->assertHasNoErrors();

    // La notification de la reprise est encore dans la session : on lit la dernière.
    expect(collect(session('filament.notifications'))->pluck('title')->all())->toContain('Exercice fiscal créé')
        ->and($fiscalYear->fresh()->status)->toBe(FiscalYear::STATUS_CLOSED);
});

it('proposes the year that follows the reprise in the fiscal year wizard', function () {
    // Recette bêta : reprise terminée sur la liasse 2025 du cabinet, l'assistant de
    // clôture proposait 2025 (l'année déjà déclarée) au lieu de 2026.
    $property = repriseProperty($this->user);

    repriseAtStepFour($this->user, $property)
        ->set('openingDeferred', '0')
        ->set('openingAccumulated', '47394')
        ->call('nextStep')
        ->call('finish')
        ->assertSet('finished', true);

    Livewire::actingAs($this->user)
        ->test(FiscalYearWizard::class)
        ->assertSet('data.year', 2026)
        ->assertDontSee('L\'exercice 2025 n\'existe pas', false);
});

it('still asks for the previous year when nothing was ever taken over', function () {
    // Contre-épreuve : un exercice sans provenance de reprise n'est pas un point de départ.
    $property = repriseProperty($this->user);
    app(DepreciationService::class)->generateDefaultComponents($property);

    FiscalYear::forceCreate([
        'user_id' => $this->user->id,
        'year' => 2026,
        'status' => FiscalYear::STATUS_DRAFT,
        'opening_accumulated_depreciation' => 4_739_400,
    ]);

    expect(FiscalYear::withoutGlobalScopes()->where('user_id', $this->user->id)->first()->hasOpeningBalances())
        ->toBeFalse();

    Livewire::actingAs($this->user)
        ->test(FiscalYearWizard::class)
        ->set('data.year', 2026)
        ->assertSee('L\'exercice 2025 n\'existe pas', false);
});

it('never offers to expense fees that the accountant amortises in 016', function () {
    // D11 : écart sur 030 égal au cumul des frais, total brut 044 identique. Les frais sont
    // amortis des deux côtés, rangés en 016 chez le cabinet. Les passer en charges les
    // retirerait des immobilisations et supprimerait leur dotation annuelle.
    $property = repriseProperty($this->user);

    $component = repriseAtStepFour($this->user, $property)
        ->set('openingDeferred', '0')
        ->call('nextStep');

    $lines = collect($component->instance()->report['lines'])->keyBy('key');
    $gross = $lines[ReprisesCheckService::LINE_GROSS_ASSETS]['computed'];
    $cumul = $lines[ReprisesCheckService::LINE_ACCUMULATED_DEPRECIATION]['computed'];

    $component->call('goToStep', 4)
        ->set('declaredGrossAssets', RepriseDossier::eurosFromCents($gross))
        ->set('openingAccumulated', RepriseDossier::eurosFromCents($cumul - notaryCumulOf($property)))
        ->call('nextStep')
        ->assertSet('step', 5);

    $line = collect($component->instance()->report['lines'])
        ->firstWhere('key', ReprisesCheckService::LINE_ACCUMULATED_DEPRECIATION);

    expect($line['verdict'])->toBe(ReprisesCheckService::VERDICT_EXPLAINED)
        ->and($component->instance()->acquisitionFeesExpensedByFirm())->toBeFalse();

    $component->assertSee('expliqué')
        ->assertSee('Votre reprise est fidèle à votre liasse.')
        ->assertDontSee('Passer mes frais en charges', false)
        ->assertDontSee('Terminer quand même');

    // Et l'action appelée depuis le navigateur ne passe pas non plus.
    $component->call('expenseAcquisitionFees');

    expect($property->fresh()->acquisition_fees_treatment)->not->toBe(Property::ACQUISITION_FEES_EXPENSED);
});

/** Lecture de liasse simulée, montants synthétiques en centimes. */
function liasseSynthetique(array $rows, array $values = []): array
{
    return [
        'document_type' => 'liasse_2033',
        'confidence' => 0.9,
        'values' => $values,
        'rows' => array_map(fn (array $row) => $row + [
            'duration_years' => 50,
            'cumul' => null,
            'cerfa_category' => PropertyComponent::cerfaCategoryForLiasseLabel($row['label']),
            'confidence' => 0.9,
        ], $rows),
        'file_path' => 'documents/1/ai-staging/liasse-test.pdf',
        'file_name' => 'liasse-test.pdf',
    ];
}

it('absorbs the euro rounding of a liasse instead of refusing it for a few cents', function () {
    // D8 : 200 001 € moins 15 % de terrain = 170 000,85 € de base ; la liasse arrondit la
    // ligne Constructions à 170 001 €. Refusée au centime, la recopie affichait « 0 € »
    // d'excédent à résorber.
    $property = repriseProperty($this->user, ['acquisition_price' => 20_000_100, 'notary_fees' => 0]);

    expect((int) $property->depreciable_base)->toBe(17_000_085);

    repriseAtStepThreeAi($this->user)
        ->set('liasseAnalysis', liasseSynthetique([
            ['label' => 'Constructions', 'base' => 17_000_100, 'annual' => 340_000],
        ]))
        ->call('applyLiasse')
        ->assertSet('liasseOverflow', null)
        ->assertSet('liasseApplied', true)
        ->assertNotified('Arrondi à l\'euro de la liasse absorbé');

    $component = PropertyComponent::withoutGlobalScopes()->where('property_id', $property->id)->sole();

    // La base tient dans la base amortissable, la dotation lue n'a pas bougé.
    expect((int) $component->base_amount)->toBe(17_000_085)
        ->and((int) $component->annual_depreciation)->toBe(340_000);
});

it('still refuses a real overflow, and shows its cents instead of 0 €', function () {
    // Contre-épreuve : 1,15 € de trop sur une seule ligne n'est plus un arrondi à l'euro.
    $property = repriseProperty($this->user, ['acquisition_price' => 20_000_100, 'notary_fees' => 0]);

    $wizard = repriseAtStepThreeAi($this->user)
        ->set('liasseAnalysis', liasseSynthetique([
            ['label' => 'Constructions', 'base' => 17_000_200, 'annual' => 340_000],
        ]))
        ->call('applyLiasse')
        ->assertSet('liasseApplied', false);

    expect($wizard->get('liasseOverflow')['message'])->toContain('170 000,85 €');

    $wizard->assertSee('1,15 €')
        ->assertSee('Excédent à résorber')
        ->assertDontSee('Ça tient désormais');

    expect(PropertyComponent::withoutGlobalScopes()->where('property_id', $property->id)->count())->toBe(0);
});

it('keeps the Constructions line of a liasse in its own rubric once copied', function () {
    // D9 : même une lecture rangée en « autres » (lue avant le correctif) retrouve sa
    // rubrique d'origine à la recopie.
    $property = repriseProperty($this->user);

    repriseAtStepThreeAi($this->user)
        ->set('liasseAnalysis', liasseSynthetique([
            ['label' => 'Constructions', 'base' => 15_000_000, 'annual' => 375_000,
                'cerfa_category' => PropertyComponent::CERFA_CATEGORY_OTHER],
        ]))
        ->call('applyLiasse')
        ->assertSet('liasseApplied', true);

    $component = PropertyComponent::withoutGlobalScopes()->where('property_id', $property->id)->sole();

    expect($component->cerfa_category)->toBe(PropertyComponent::CERFA_CATEGORY_CONSTRUCTIONS);
});

it('keeps the depreciation read on the liasse, in the editor and through a save', function () {
    // D10 : la dotation du cabinet (3 700 €) n'est pas base ÷ durée (3 750 €). L'éditeur
    // la recalculait à l'affichage, et l'enregistrait ainsi au premier « Enregistrer ».
    $property = repriseProperty($this->user);

    $wizard = repriseAtStepThreeAi($this->user)
        ->set('liasseAnalysis', liasseSynthetique([
            ['label' => 'Constructions', 'base' => 15_000_000, 'annual' => 370_000, 'duration_years' => 40],
        ]))
        ->call('applyLiasse')
        ->assertSet('liasseApplied', true);

    $component = PropertyComponent::withoutGlobalScopes()->where('property_id', $property->id)->sole();

    expect((int) $component->annual_depreciation)->toBe(370_000);

    $line = collect($wizard->instance()->editorData()['components'])->firstWhere('id', $component->id);

    expect($line['annualDepreciation'])->toBe(370_000);

    // Le navigateur renvoie la ligne telle qu'il l'affiche (même calcul que `annualCentsOf()`).
    $wizard->call('saveComponents', [[
        'id' => $component->id,
        'name' => $line['name'],
        'percentage' => $line['percentage'],
        'baseAmount' => $line['baseAmount'],
        'baseSource' => $line['baseSource'],
        'annualDepreciation' => $line['annualDepreciation'] ?? intdiv($line['baseAmount'], $line['duration']),
        'duration' => $line['duration'],
        'sortOrder' => $line['sortOrder'],
        'enabled' => true,
        'cerfaCategory' => $line['cerfaCategory'],
    ]]);

    expect((int) $component->fresh()->annual_depreciation)->toBe(370_000);
});

it('prefills box 044 with the total of the liasse, not with box 028', function () {
    // D12 : le champ « case 044 » recevait la case 028 lue, d'où un faux écart au contrôle
    // égal aux incorporelles (les frais rangés en 014 par le cabinet).
    repriseProperty($this->user);

    $rows = [['label' => 'Constructions', 'base' => 15_000_000, 'annual' => 375_000]];

    // 044 absente : 028 + 014.
    repriseAtStepThreeAi($this->user)
        ->set('liasseAnalysis', liasseSynthetique($rows, ['gross_assets' => 20_000_000, 'intangible_assets' => 1_000_000]))
        ->call('applyLiasse')
        ->assertSet('declaredGrossAssets', '210000');

    // 044 lue : elle fait foi.
    repriseAtStepThreeAi($this->user)
        ->set('liasseAnalysis', liasseSynthetique($rows, [
            'gross_assets' => 20_000_000,
            'intangible_assets' => 1_000_000,
            'total_gross_assets' => 21_050_000,
        ]))
        ->call('applyLiasse')
        ->assertSet('declaredGrossAssets', '210500');
});

it('gets box 044 and the declared result back when reviewing a reprise after a reload', function () {
    // D13 : après « Terminer », rechargement, puis « Revoir ma reprise » : la case 044 et le
    // résultat fiscal revenaient « non renseigné », et l'étape 3 réaffichait un dépôt vide.
    repriseProperty($this->user);

    repriseAtStepThreeAi($this->user)
        ->set('liasseConsent', true)
        ->set('liasseFile', fauxPdfLiasse())
        ->call('analyzeLiasse')
        ->call('applyLiasse')
        ->call('nextStep')
        ->call('nextStep')
        ->assertSet('step', 5)
        ->call('finish')
        ->assertSet('finished', true);

    $fresh = Livewire::actingAs($this->user)->test(RepriseDossier::class)
        ->assertSet('finished', true)
        ->assertSet('declaredGrossAssets', '200000')
        ->assertSet('declaredFiscalResult', -85_000)
        ->assertSet('liasseApplied', true)
        ->call('reviewReprise');

    $lines = collect($fresh->instance()->report['lines'])->keyBy('key');

    expect($lines[ReprisesCheckService::LINE_GROSS_ASSETS]['declared'])->toBe(20_000_000)
        ->and($lines[ReprisesCheckService::LINE_FISCAL_RESULT]['declared'])->toBe(-85_000);

    // L'étape 3 montre le plan recopié, pas un nouveau dépôt de liasse.
    $fresh->call('goToStep', 3)
        ->assertSee('Enregistrer mon plan')
        ->assertDontSee('Faire lire ma liasse');
});
