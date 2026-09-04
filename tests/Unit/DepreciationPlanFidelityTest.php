<?php

use App\Models\Furniture;
use App\Models\Property;
use App\Models\PropertyComponent;
use App\Models\PropertyWork;
use App\Models\User;
use App\Services\DepreciationService;
use App\Services\FiscalYearService;
use App\Services\TaxReturnService;

/**
 * Fidélité du plan d'amortissement à celui d'un cabinet comptable (lot 4).
 *
 * Un bailleur qui quitte son expert-comptable doit RETROUVER ses chiffres. Cinq
 * verrous manquaient, et chacun garantissait un écart avec sa liasse :
 *
 *   1. tous les composants démarraient à la mise en location du bien ;
 *   2. un composant hors catalogue ne pouvait pas être créé ;
 *   3. la ligne du 2033-C se déduisait du NOM du composant ;
 *   4. les frais d'acquisition étaient toujours amortis sur 25 ans ;
 *   5. travaux et mobilier recalculaient toujours `montant ÷ durée`, et aucun stock
 *      d'amortissements antérieurs ne pouvait être repris.
 *
 * Chacun de ces tests échoue sur le code d'avant le lot 4 — c'est la seule chose qui
 * fait d'eux des verrous plutôt que de la décoration.
 */
function planProperty(User $user, array $overrides = []): Property
{
    return Property::forceCreate(array_merge([
        'user_id' => $user->id,
        'name' => 'Bien reprise',
        'address' => '1 rue Test',
        'city' => 'Paris',
        'postal_code' => '75001',
        'type' => 'apartment',
        'total_area' => 100,
        'rented_area' => 100,
        'acquisition_date' => '2018-01-01',
        'acquisition_price' => 20000000,
        'notary_fees' => 0,
        'agency_fees' => 0,
        'market_value' => null,
        'land_percentage' => 20,
        'rental_start_date' => '2019-01-01',
        'rental_type' => 'seasonal',
        'is_primary_residence' => false,
        'acquisition_fees_treatment' => Property::ACQUISITION_FEES_AMORTIZED,
        'acquisition_fees_duration' => Property::ACQUISITION_FEES_DEFAULT_DURATION,
    ], $overrides));
}

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->depreciation = app(DepreciationService::class);
    $this->taxReturn = app(TaxReturnService::class);
});

// -----------------------------------------------------------------------------
// 1. Date de départ par composant
// -----------------------------------------------------------------------------

it('starts a component on its own date instead of the property rental start', function () {
    $property = planProperty($this->user);
    $this->depreciation->generateDefaultComponents($property);

    // Une toiture refaite et mise en service trois ans après la mise en location.
    $roof = $property->components()->where('name', 'Toiture')->firstOrFail();
    $roof->forceFill(['depreciation_start_date' => '2022-01-01'])->save();

    $property->unsetRelation('components');
    $detail = collect($this->depreciation->depreciationDetailForYear($property, 2021))
        ->firstWhere('name', 'Toiture');

    // 2021 précède la date du composant : rien ne se dote, rien ne se cumule.
    expect($detail['annual'])->toBe('0')
        ->and($detail['cumul'])->toBe('0');
});

it('replays the cumul from the component start date, not the property one', function () {
    $property = planProperty($this->user);
    $this->depreciation->generateDefaultComponents($property);

    $roof = $property->components()->where('name', 'Toiture')->firstOrFail();
    $annual = (int) $roof->annual_depreciation;
    $roof->forceFill(['depreciation_start_date' => '2022-01-01'])->save();

    $property->unsetRelation('components');
    $detail = collect($this->depreciation->depreciationDetailForYear($property, 2024))
        ->firstWhere('name', 'Toiture');

    // 2022, 2023, 2024 : trois exercices pleins depuis la date du composant, alors que
    // le bien est loué depuis 2019 — six exercices sous l'ancienne règle.
    expect((int) $detail['cumul'])->toBe($annual * 3);
});

it('keeps the first-year prorata on the component start date', function () {
    $property = planProperty($this->user);
    $this->depreciation->generateDefaultComponents($property);

    $roof = $property->components()->where('name', 'Toiture')->firstOrFail();
    $annual = (int) $roof->annual_depreciation;
    $roof->forceFill(['depreciation_start_date' => '2022-07-01'])->save();

    $property->unsetRelation('components');
    $detail = collect($this->depreciation->depreciationDetailForYear($property, 2022))
        ->firstWhere('name', 'Toiture');

    // Du 1er juillet au 31 décembre : 184 jours sur 365.
    expect((int) $detail['annual'])->toBe((int) bcmul((string) $annual, bcdiv('184', '365', 10), 0))
        ->and((int) $detail['annual'])->toBeLessThan($annual);
});

// -----------------------------------------------------------------------------
// 3. Catégorie Cerfa explicite — le rétro-classement ne doit RIEN déplacer
// -----------------------------------------------------------------------------

it('classifies every catalog component exactly as the name-based table did', function () {
    // La table historique de TaxReturnService::compute2033C(), recopiée ici pour
    // qu'elle soit comparée à une source indépendante du code qu'elle vérifie.
    $historical = [
        'Gros œuvre' => 'constructions',
        'Toiture' => 'constructions',
        'Installations électriques' => 'installations',
        'Plomberie / sanitaire' => 'installations',
        'Étanchéité' => 'agencements',
        'Agencements intérieurs' => 'agencements',
    ];

    foreach (DepreciationService::FULL_CATALOG as $entry) {
        expect(PropertyComponent::cerfaCategoryForName($entry['name']))
            ->toBe($historical[$entry['name']] ?? 'autres');
    }

    // Un nom inconnu tombe en « autres », exactement comme le `?? 'autres'` d'avant.
    expect(PropertyComponent::cerfaCategoryForName('Ascenseur'))->toBe('autres');
});

it('does not move a single euro between Cerfa lines when components are generated', function () {
    $property = planProperty($this->user);
    $this->depreciation->generateDefaultComponents($property);

    $c = $this->taxReturn->compute2033C(collect([$property]), 2024);

    // Base amortissable = 200 000 € × 80 % = 160 000 €.
    // constructions = gros œuvre 50 % + toiture 10 % = 96 000 €
    // installations = électricité 10 % + plomberie 10 % = 32 000 €
    // agencements   = étanchéité 5 % + agencements 15 % = 32 000 €
    expect($c['categories']['constructions']['brut'])->toBe(9600000)
        ->and($c['categories']['installations']['brut'])->toBe(3200000)
        ->and($c['categories']['agencements']['brut'])->toBe(3200000)
        ->and($c['categories']['autres']['brut'])->toBe(0);
});

it('follows the stored Cerfa category rather than the component name', function () {
    $property = planProperty($this->user);
    $this->depreciation->generateDefaultComponents($property);

    // Le cabinet rangeait la toiture en « agencements » : son nom disait pourtant
    // « constructions », et c'est le nom qui décidait jusqu'au 2026-09-04.
    $property->components()->where('name', 'Toiture')->firstOrFail()
        ->forceFill(['cerfa_category' => PropertyComponent::CERFA_CATEGORY_FITTINGS])->save();

    $property->unsetRelation('components');
    $c = $this->taxReturn->compute2033C(collect([$property]), 2024);

    expect($c['categories']['constructions']['brut'])->toBe(8000000)   // gros œuvre seul
        ->and($c['categories']['agencements']['brut'])->toBe(3200000 + 1600000);
});

it('routes a free-name component to the Cerfa line it was given', function () {
    $property = planProperty($this->user);

    app(DepreciationService::class)->syncComponents($property, [[
        'id' => null,
        'name' => 'Ascenseur',
        'duration_years' => 20,
        'sort_order' => 20,
        'base_source' => PropertyComponent::BASE_SOURCE_MANUAL,
        'base_amount' => 1000000,
        'cerfa_category' => PropertyComponent::CERFA_CATEGORY_INSTALLATIONS,
    ]]);

    $property->unsetRelation('components');
    $c = $this->taxReturn->compute2033C(collect([$property]), 2024);

    // Sous l'ancienne règle, « Ascenseur » tombait forcément en « autres ».
    expect($c['categories']['installations']['brut'])->toBe(1000000)
        ->and($c['categories']['autres']['brut'])->toBe(0);
});

// -----------------------------------------------------------------------------
// 4. Frais d'acquisition : traitement et durée
// -----------------------------------------------------------------------------

it('stops amortizing acquisition fees the accountant already expensed', function () {
    $property = planProperty($this->user, [
        'notary_fees' => 2500000,
        'acquisition_fees_treatment' => Property::ACQUISITION_FEES_EXPENSED,
    ]);
    $this->depreciation->generateDefaultComponents($property);

    $lines = $this->depreciation->depreciationDetailForYear($property, 2024);

    expect(collect($lines)->where('type', 'notary'))->toBeEmpty();

    // Et ils ne figurent plus au bilan brut : ils ne sont pas une immobilisation.
    $c = $this->taxReturn->compute2033C(collect([$property]), 2024);
    expect($c['categories']['constructions']['brut'])->toBe(9600000);
});

it('honours a custom acquisition fees duration', function () {
    $property = planProperty($this->user, [
        'notary_fees' => 1500000,
        'acquisition_fees_duration' => 15,
    ]);

    $lines = collect($this->depreciation->depreciationDetailForYear($property, 2024));
    $notary = $lines->firstWhere('name', 'Frais de notaire');

    // 15 000 € sur 15 ans = 1 000 €/an, et non 600 € (25 ans).
    expect((int) $notary['annual'])->toBe(100000);
});

it('keeps line 572 equal to line 254 whatever the acquisition fees treatment', function () {
    foreach ([
        Property::ACQUISITION_FEES_AMORTIZED,
        Property::ACQUISITION_FEES_EXPENSED,
        Property::ACQUISITION_FEES_EXCLUDED,
    ] as $treatment) {
        $user = User::factory()->create();
        $property = planProperty($user, [
            'notary_fees' => 3960000,
            'agency_fees' => 2875000,
            'acquisition_fees_treatment' => $treatment,
            'acquisition_fees_duration' => 20,
        ]);
        $this->depreciation->generateDefaultComponents($property);

        $properties = Property::withoutGlobalScopes()->where('user_id', $user->id)->get();
        $fy = app(FiscalYearService::class)->getOrCreate($user, 2024);

        expect($this->taxReturn->compute2033C($properties, 2024)['total_dotation'])
            ->toBe($this->taxReturn->compute2033B($fy, $properties, 2024)['254']);
    }
});

// -----------------------------------------------------------------------------
// 5. Dotations manuelles et cumuls d'ouverture
// -----------------------------------------------------------------------------

it('preserves a manual depreciation on works instead of recomputing it', function () {
    $property = planProperty($this->user);

    $work = PropertyWork::create([
        'property_id' => $property->id,
        'description' => 'Rénovation salle de bain',
        'amount' => 1000000,
        'tva_rate' => 0,
        'work_date' => '2020-01-01',
        'duration_years' => 10,
        'is_dedicated' => true,
        'depreciation_source' => PropertyWork::DEPRECIATION_SOURCE_MANUAL,
        'annual_depreciation' => 97531, // l'arrondi du cabinet, pas 100 000
    ]);

    expect((int) $work->fresh()->annual_depreciation)->toBe(97531);

    // Et il survit à une simple sauvegarde ultérieure.
    $work->update(['description' => 'Salle de bain']);
    expect((int) $work->fresh()->annual_depreciation)->toBe(97531);
});

it('preserves a manual depreciation on furniture instead of recomputing it', function () {
    $property = planProperty($this->user);

    $item = Furniture::create([
        'property_id' => $property->id,
        'description' => 'Canapé',
        'amount' => 150000,
        'tva_rate' => 0,
        'purchase_date' => '2021-01-01',
        'duration_years' => 5,
        'is_dedicated' => true,
        'depreciation_source' => Furniture::DEPRECIATION_SOURCE_MANUAL,
        'annual_depreciation' => 29876,
    ]);

    expect((int) $item->fresh()->annual_depreciation)->toBe(29876);
});

it('adds the opening accumulated depreciation to the cumul and never to the year', function () {
    $property = planProperty($this->user);

    $work = PropertyWork::create([
        'property_id' => $property->id,
        'description' => 'Rénovation',
        'amount' => 1000000,
        'tva_rate' => 0,
        'work_date' => '2023-01-01',
        'duration_years' => 10,
        'is_dedicated' => true,
        'opening_accumulated_depreciation' => 400000,
    ]);

    $property->unsetRelation('works');
    $line = collect($this->depreciation->depreciationDetailForYear($property, 2024))
        ->firstWhere('name', 'Rénovation');

    // Dotation de l'exercice : intacte. Cumul : deux exercices + le stock repris.
    expect((int) $line['annual'])->toBe((int) $work->annual_depreciation)
        ->and((int) $line['cumul'])->toBe((int) $work->annual_depreciation * 2 + 400000);
});

it('does not let an opening cumul inflate the yearly charge of the tax return', function () {
    $property = planProperty($this->user);
    $this->depreciation->generateDefaultComponents($property);

    $properties = Property::withoutGlobalScopes()->where('user_id', $this->user->id)->get();
    $fy = app(FiscalYearService::class)->getOrCreate($this->user, 2024);
    $before254 = $this->taxReturn->compute2033B($fy, $properties, 2024)['254'];
    $before030 = $this->taxReturn->compute2033A($fy, $properties, 2024)['030'];

    PropertyComponent::withoutGlobalScopes()
        ->where('property_id', $property->id)
        ->update(['opening_accumulated_depreciation' => 100000]);

    $properties = Property::withoutGlobalScopes()->where('user_id', $this->user->id)->get();
    $after254 = $this->taxReturn->compute2033B($fy, $properties, 2024)['254'];
    $after030 = $this->taxReturn->compute2033A($fy, $properties, 2024)['030'];

    // La charge de l'exercice ne bouge pas d'un centime…
    expect($after254)->toBe($before254)
        // … et le cumul du bilan monte exactement des six stocks repris.
        ->and($after030 - $before030)->toBe(6 * 100000);
});

it('keeps line 572 equal to line 254 when opening cumuls are in play', function () {
    $property = planProperty($this->user, ['notary_fees' => 1200000]);
    $this->depreciation->generateDefaultComponents($property);

    PropertyComponent::withoutGlobalScopes()
        ->where('property_id', $property->id)
        ->update(['opening_accumulated_depreciation' => 250000]);

    Furniture::create([
        'property_id' => $property->id, 'description' => 'Literie',
        'purchase_date' => '2022-01-01', 'amount' => 300000, 'tva_rate' => 0,
        'duration_years' => 5, 'is_dedicated' => true,
        'opening_accumulated_depreciation' => 60000,
    ]);

    $properties = Property::withoutGlobalScopes()->where('user_id', $this->user->id)->get();
    $fy = app(FiscalYearService::class)->getOrCreate($this->user, 2024);

    expect($this->taxReturn->compute2033C($properties, 2024)['total_dotation'])
        ->toBe($this->taxReturn->compute2033B($fy, $properties, 2024)['254']);
});
