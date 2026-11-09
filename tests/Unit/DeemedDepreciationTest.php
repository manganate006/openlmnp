<?php

use App\Models\Furniture;
use App\Models\Property;
use App\Models\PropertyWork;
use App\Models\User;
use App\Services\DepreciationService;
use App\Services\FiscalYearService;
use App\Services\TaxReturnService;

/**
 * Début de l'activité, début du réel et amortissements réputés pratiqués.
 *
 * Deux dates, deux rôles :
 *   - `activity_start_date` — la première mise en location, micro-BIC compris. C'est la
 *     mise en service des actifs : les plans partent de là, jamais avant ;
 *   - `rental_start_date` — l'ouverture du premier exercice au réel. Avant, rien n'est
 *     DÉDUCTIBLE.
 *
 * Les annuités des années au micro-BIC sont « réputées pratiquées » : comptées dans le
 * cumul (2033-A 030, 2033-C 570/576), jamais dans une dotation (2033-B 254, 2033-C
 * dotations, FEC). C'est la lecture recommandée par le registre des pièges (P04), qui
 * applique par analogie le BOI-BIC-AMT-10-30-30-10 § 470. Jamais des amortissements
 * différés non plus (318 / 350) : ils n'ont jamais été déductibles.
 *
 * Montants synthétiques (dépôt public).
 */
function deemedProperty(User $user, array $overrides = []): Property
{
    return Property::forceCreate(array_merge([
        'user_id' => $user->id,
        'name' => 'Bien micro puis réel',
        'address' => '1 rue Test',
        'city' => 'Lyon',
        'postal_code' => '69001',
        'type' => 'apartment',
        'total_area' => 50,
        'rented_area' => 50,
        'acquisition_date' => '2022-06-01',
        'acquisition_price' => 15000000,
        'notary_fees' => 1000000,
        'agency_fees' => 0,
        'market_value' => null,
        'land_percentage' => 20,
        'rental_start_date' => '2025-01-01',
        'activity_start_date' => '2023-01-01',
        'rental_type' => 'seasonal',
        'is_primary_residence' => false,
        'acquisition_fees_treatment' => Property::ACQUISITION_FEES_AMORTIZED,
        'acquisition_fees_duration' => 20,
    ], $overrides));
}

function deemedFurniture(Property $property, string $date, int $amount = 600000, int $years = 6): Furniture
{
    return Furniture::create([
        'property_id' => $property->id,
        'description' => 'Mobilier ' . $date,
        'purchase_date' => $date,
        'amount' => $amount,
        'tva_rate' => 20,
        'duration_years' => $years,
        'is_dedicated' => true,
    ]);
}

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->depreciation = app(DepreciationService::class);
    $this->taxReturn = app(TaxReturnService::class);
});

// -----------------------------------------------------------------------------
// (a) Rien ne s'amortit avant la première mise en location
// -----------------------------------------------------------------------------

it('starts furniture bought before the first rental on the first rental, not on its purchase', function () {
    // Bien ordinaire, sans date d'activité : l'activité commence au réel, le 15 mars 2023.
    $property = deemedProperty($this->user, [
        'rental_start_date' => '2023-03-15',
        'activity_start_date' => null,
    ]);
    $item = deemedFurniture($property, '2022-11-01');
    $annual = (int) $item->annual_depreciation;
    $property->refresh();

    // 2022 : le meuble est acheté, mais aucune activité n'existe encore.
    expect($this->depreciation->calculateAnnualDepreciation($property, 2022)['furniture'])->toBe('0');

    // 2023 : prorata depuis la mise en location (292 jours sur 365), pas une annuité pleine.
    $prorata = (int) bcmul((string) $annual, bcdiv('292', '365', 10), 0);
    expect((int) $this->depreciation->calculateAnnualDepreciation($property, 2023)['furniture'])->toBe($prorata);

    $line = collect($this->depreciation->depreciationDetailForYear($property, 2023))->firstWhere('type', 'furniture');
    expect($line['in_service'])->toBe('2023-03-15')
        ->and((int) $line['cumul'])->toBe($prorata);
});

it('starts works done before the first rental on the first rental as well', function () {
    $property = deemedProperty($this->user, [
        'rental_start_date' => '2023-07-01',
        'activity_start_date' => null,
    ]);
    $work = PropertyWork::create([
        'property_id' => $property->id,
        'description' => 'Rénovation avant location',
        'work_date' => '2023-02-01',
        'amount' => 1000000,
        'tva_rate' => 0,
        'duration_years' => 10,
        'is_dedicated' => true,
    ]);
    $property->refresh();

    // Du 1er juillet au 31 décembre : 184 jours sur 365.
    $expected = (int) bcmul((string) $work->annual_depreciation, bcdiv('184', '365', 10), 0);

    expect((int) $this->depreciation->calculateAnnualDepreciation($property, 2023)['works'])->toBe($expected);
});

// -----------------------------------------------------------------------------
// (b) Activité en 2023, réel en 2025 : 2023 et 2024 réputés pratiqués
// -----------------------------------------------------------------------------

it('deducts nothing for the micro-BIC years, then the normal annuity from the first real year', function () {
    $property = deemedProperty($this->user);
    $this->depreciation->generateDefaultComponents($property);
    $property->refresh();

    $building = (string) $property->components->sum('annual_depreciation');

    foreach ([2023, 2024] as $year) {
        $dep = $this->depreciation->calculateAnnualDepreciation($property, $year);
        expect($dep['total'])->toBe('0')
            ->and($dep['building'])->toBe('0')
            ->and($dep['notary'])->toBe('0');
    }

    // 2025 : annuité pleine (le plan court depuis le 1er janvier 2023), pas une première
    // année de plan.
    expect($this->depreciation->calculateAnnualDepreciation($property, 2025)['building'])->toBe($building);
});

it('counts the micro-BIC annuities in the cumul of the first real year', function () {
    $property = deemedProperty($this->user);
    $this->depreciation->generateDefaultComponents($property);
    $item = deemedFurniture($property, '2022-09-01');
    $property->refresh();

    $detail = collect($this->depreciation->depreciationDetailForYear($property, 2025));

    // Composants : trois annuités pleines au 31/12/2025, dont deux réputées pratiquées.
    foreach ($detail->where('type', 'building') as $line) {
        $component = $property->components->firstWhere('name', $line['name']);
        expect((int) $line['cumul'])->toBe(3 * (int) $component->annual_depreciation)
            ->and((int) $line['annual'])->toBe((int) $component->annual_depreciation)
            ->and($line['in_service'])->toBe('2023-01-01');
    }

    // Meuble acheté AVANT l'activité : son plan part de la première location.
    $furniture = $detail->firstWhere('type', 'furniture');
    expect($furniture['in_service'])->toBe('2023-01-01')
        ->and((int) $furniture['cumul'])->toBe(3 * (int) $item->annual_depreciation);

    // Frais d'acquisition : idem, depuis la première location.
    $notary = $detail->firstWhere('type', 'notary');
    $feesAnnual = (int) bcdiv('1000000', '20', 0);
    expect((int) $notary['cumul'])->toBe(3 * $feesAnnual)
        ->and((int) $notary['annual'])->toBe($feesAnnual);
});

it('prints the deemed annuities as opening cumul on the 2033-C and in 030, never in 254', function () {
    $property = deemedProperty($this->user);
    $this->depreciation->generateDefaultComponents($property);
    deemedFurniture($property, '2023-06-01');
    $property->refresh();

    $properties = Property::withoutGlobalScopes()->where('user_id', $this->user->id)->get();
    $fy = app(FiscalYearService::class)->getOrCreate($this->user, 2025);

    $form2033B = $this->taxReturn->compute2033B($fy, $properties, 2025);
    $form2033C = $this->taxReturn->compute2033C($properties, 2025);
    $form2033A = $this->taxReturn->compute2033A($fy, $properties, 2025);

    $dotation2025 = (int) $this->depreciation->calculateAnnualDepreciation($property, 2025)['total'];
    $cumul2024 = collect($this->depreciation->depreciationDetailForYear($property, 2024))
        ->sum(fn ($line) => (int) $line['cumul']);

    expect((int) $form2033B['254'])->toBe($dotation2025)
        ->and($form2033C['total_dotation'])->toBe($dotation2025)
        // Le cumul de début d'exercice = les années au micro-BIC.
        ->and($form2033C['total_cumul_debut'])->toBe($cumul2024)
        ->and($cumul2024)->toBeGreaterThan(0)
        ->and($form2033C['total_cumul'])->toBe($cumul2024 + $dotation2025)
        ->and($form2033A['030'] + $form2033A['016'])->toBe($form2033C['total_cumul']);
});

it('never turns the deemed annuities into deferred depreciation', function () {
    $property = deemedProperty($this->user);
    $this->depreciation->generateDefaultComponents($property);
    $property->refresh();

    // Aucune recette : tout l'amortissement de l'exercice est différé (art. 39 C)… mais
    // seulement celui de l'exercice.
    $fy = app(FiscalYearService::class)->getOrCreate($this->user, 2025);
    $totals = app(FiscalYearService::class)->computeTotals($fy);

    $dotation2025 = (int) $this->depreciation->calculateAnnualDepreciation($property, 2025)['total'];

    expect($totals['total_depreciation'])->toBe($dotation2025)
        ->and($totals['deferred_depreciation'])->toBe($dotation2025);
});

it('ends the plan earlier: the deemed years are consumed, not postponed', function () {
    $property = deemedProperty($this->user);
    $item = deemedFurniture($property, '2023-01-01', 600000, 5);
    $property->refresh();

    // Plan 2023 → 2027 : au réel, seuls 2025, 2026 et 2027 se déduisent.
    expect((int) $this->depreciation->calculateAnnualDepreciation($property, 2027)['furniture'])
        ->toBe((int) $item->annual_depreciation)
        ->and($this->depreciation->calculateAnnualDepreciation($property, 2028)['furniture'])->toBe('0');
});

it('keeps a component own start date when it is later than the first rental', function () {
    $property = deemedProperty($this->user);
    $this->depreciation->generateDefaultComponents($property);
    $roof = $property->components()->where('name', 'Toiture')->firstOrFail();
    $roof->forceFill(['depreciation_start_date' => '2024-01-01'])->save();
    $property->refresh();

    $line = collect($this->depreciation->depreciationDetailForYear($property, 2025))->firstWhere('name', 'Toiture');

    expect($line['in_service'])->toBe('2024-01-01')
        ->and((int) $line['cumul'])->toBe(2 * (int) $roof->annual_depreciation);
});

it('brings a component start date earlier than the first rental back to it', function () {
    $property = deemedProperty($this->user);
    $this->depreciation->generateDefaultComponents($property);
    $roof = $property->components()->where('name', 'Toiture')->firstOrFail();
    $roof->forceFill(['depreciation_start_date' => '2020-01-01'])->save();
    $property->refresh();

    expect($this->depreciation->componentStartDate($roof->fresh(), $property)->format('Y-m-d'))->toBe('2023-01-01');
});

it('lets a cumul taken over from an accountant win over the deemed annuities', function () {
    $property = deemedProperty($this->user);
    $this->depreciation->generateDefaultComponents($property);
    $roof = $property->components()->where('name', 'Toiture')->firstOrFail();
    $roof->forceFill([
        'opening_accumulated_depreciation' => 12345,
        'opening_accumulated_year' => 2024,
    ])->save();
    $property->refresh();

    $line = collect($this->depreciation->depreciationDetailForYear($property, 2025))->firstWhere('name', 'Toiture');

    // Le cumul repris couvre 2023-2024 : le rejeu ne reprend qu'en 2025.
    expect((int) $line['cumul'])->toBe(12345 + (int) $roof->annual_depreciation);
});

it('puts the property in the balance sheet from its first rental', function () {
    $property = deemedProperty($this->user);

    expect($this->depreciation->isInServiceForYear($property, 2022))->toBeFalse()
        ->and($this->depreciation->isInServiceForYear($property, 2023))->toBeTrue();
});

// -----------------------------------------------------------------------------
// (c) Sans date d'activité, rien ne change
// -----------------------------------------------------------------------------

it('treats an empty activity start date exactly like the real start date', function () {
    $empty = deemedProperty($this->user, ['activity_start_date' => null, 'rental_start_date' => '2023-03-15']);
    $same = deemedProperty($this->user, ['activity_start_date' => '2023-03-15', 'rental_start_date' => '2023-03-15']);

    foreach ([$empty, $same] as $property) {
        $this->depreciation->generateDefaultComponents($property);
        deemedFurniture($property, '2024-05-02');
        PropertyWork::create([
            'property_id' => $property->id,
            'description' => 'Travaux',
            'work_date' => '2023-09-01',
            'amount' => 800000,
            'tva_rate' => 0,
            'duration_years' => 10,
            'is_dedicated' => true,
        ]);
    }

    foreach ([2023, 2024, 2030] as $year) {
        $a = $this->depreciation->calculateAnnualDepreciation($empty->fresh(), $year);
        $b = $this->depreciation->calculateAnnualDepreciation($same->fresh(), $year);
        expect($a['total'])->toBe($b['total']);

        $cumulA = collect($this->depreciation->depreciationDetailForYear($empty->fresh(), $year))->pluck('cumul')->all();
        $cumulB = collect($this->depreciation->depreciationDetailForYear($same->fresh(), $year))->pluck('cumul')->all();
        expect($cumulA)->toBe($cumulB);
    }

    expect($empty->activityStartDate()->format('Y-m-d'))->toBe('2023-03-15');
});

it('brings an activity start date later than the real start back to the real start', function () {
    $property = deemedProperty($this->user, ['activity_start_date' => '2026-01-01']);

    expect($property->activityStartDate()->format('Y-m-d'))->toBe('2025-01-01')
        ->and($property->hasMicroBicYearsBeforeReal())->toBeFalse();
});
