<?php

use App\Models\FiscalYear;
use App\Models\Property;
use App\Models\PropertyComponent;
use App\Models\User;
use App\Services\DepreciationService;
use App\Services\ReprisesCheckService;

/**
 * Contrôle de reprise — l'écran 5 de l'assistant.
 *
 * Sans lui, un bailleur qui recopie sa liasse ne saura jamais si sa reprise est juste.
 * Ici, seul le SERVICE est testé : l'écran Filament est monté à l'intégration.
 */
beforeEach(function () {
    $this->user = User::factory()->create();
    $this->service = app(ReprisesCheckService::class);
});

function makeCheckProperty(User $user, array $overrides = []): Property
{
    $property = Property::forceCreate(array_merge([
        'user_id' => $user->id,
        'name' => 'Bien du cabinet',
        'address' => '9 rue de la Liasse',
        'city' => 'Bordeaux',
        'postal_code' => '33000',
        'type' => 'apartment',
        'total_area' => 70,
        'rented_area' => 70,
        'acquisition_date' => '2019-01-10',
        'acquisition_price' => 20000000, // 200 000 €
        'notary_fees' => 0,
        'agency_fees' => 0,
        'market_value' => null,
        'land_percentage' => 15,
        'rental_start_date' => '2019-03-01',
        'rental_type' => 'seasonal',
        'is_primary_residence' => false,
    ], $overrides));

    app(DepreciationService::class)->generateDefaultComponents($property);

    return $property;
}

function makeRepriseYear(User $user, int $year, array $overrides = []): FiscalYear
{
    return FiscalYear::forceCreate(array_merge([
        'user_id' => $user->id,
        'year' => $year,
        'status' => FiscalYear::STATUS_DRAFT,
        'opening_deferred_depreciation' => 1200000, // 12 000 €
        'opening_source' => FiscalYear::OPENING_SOURCE_LIASSE,
    ], $overrides));
}

/** Ce que l'application reconstitue pour l'exercice comparé, ligne par ligne. */
function computedFor(ReprisesCheckService $service, FiscalYear $repriseYear): array
{
    $report = $service->check($repriseYear, []);

    return collect($report['lines'])->pluck('computed', 'key')->all();
}

// === TOUT TOMBE JUSTE ===

it('turns every line green when the liasse and the application agree', function () {
    makeCheckProperty($this->user);
    $repriseYear = makeRepriseYear($this->user, 2026);
    $computed = computedFor($this->service, $repriseYear);

    $report = $this->service->check($repriseYear, [
        ReprisesCheckService::LINE_GROSS_ASSETS => $computed[ReprisesCheckService::LINE_GROSS_ASSETS],
        ReprisesCheckService::LINE_ACCUMULATED_DEPRECIATION => $computed[ReprisesCheckService::LINE_ACCUMULATED_DEPRECIATION],
        ReprisesCheckService::LINE_DEFERRED_DEPRECIATION => 1200000,
    ]);

    expect($report['year'])->toBe(2025)
        ->and($report['verdict'])->toBe(ReprisesCheckService::VERDICT_MATCH)
        ->and($report['warning'])->toBeNull();
});

it('stays green within one euro, the rounding of a liasse', function () {
    makeCheckProperty($this->user);
    $repriseYear = makeRepriseYear($this->user, 2026);
    $computed = computedFor($this->service, $repriseYear);

    $report = $this->service->check($repriseYear, [
        ReprisesCheckService::LINE_ACCUMULATED_DEPRECIATION => $computed[ReprisesCheckService::LINE_ACCUMULATED_DEPRECIATION] + 99,
    ]);

    expect(lineOf($report, ReprisesCheckService::LINE_ACCUMULATED_DEPRECIATION)['verdict'])
        ->toBe(ReprisesCheckService::VERDICT_MATCH);
});

function lineOf(array $report, string $key): array
{
    return collect($report['lines'])->firstWhere('key', $key);
}

it('turns amber beyond one euro but within one percent', function () {
    makeCheckProperty($this->user);
    $repriseYear = makeRepriseYear($this->user, 2026);
    $computed = computedFor($this->service, $repriseYear);
    $reference = $computed[ReprisesCheckService::LINE_ACCUMULATED_DEPRECIATION];

    $report = $this->service->check($repriseYear, [
        // 0,5 % d'écart : au-delà de l'euro, en deçà du pour cent.
        ReprisesCheckService::LINE_ACCUMULATED_DEPRECIATION => $reference + (int) round($reference * 0.005),
    ]);

    expect(lineOf($report, ReprisesCheckService::LINE_ACCUMULATED_DEPRECIATION)['verdict'])
        ->toBe(ReprisesCheckService::VERDICT_CLOSE);
});

it('turns red beyond one percent and says where to look', function () {
    makeCheckProperty($this->user);
    $repriseYear = makeRepriseYear($this->user, 2026);
    $computed = computedFor($this->service, $repriseYear);
    $reference = $computed[ReprisesCheckService::LINE_ACCUMULATED_DEPRECIATION];

    $report = $this->service->check($repriseYear, [
        ReprisesCheckService::LINE_ACCUMULATED_DEPRECIATION => $reference * 2,
    ]);

    $line = lineOf($report, ReprisesCheckService::LINE_ACCUMULATED_DEPRECIATION);

    expect($line['verdict'])->toBe(ReprisesCheckService::VERDICT_MISMATCH)
        ->and($report['verdict'])->toBe(ReprisesCheckService::VERDICT_MISMATCH)
        // Les causes probables sont ordonnées par fréquence : l'ordre est le message.
        ->and(array_column($line['diagnostics'], 'code'))->toBe([
            'rental_start_date',
            'land_share',
            'acquisition_fees',
            'missing_component',
            'market_value',
        ]);
});

// === UN CAS PAR DIAGNOSTIC ===

it('corroborates a land share diagnosis when no land share is set', function () {
    makeCheckProperty($this->user, ['land_percentage' => 0]);
    $repriseYear = makeRepriseYear($this->user, 2026);
    $computed = computedFor($this->service, $repriseYear);

    $report = $this->service->check($repriseYear, [
        ReprisesCheckService::LINE_ACCUMULATED_DEPRECIATION => (int) ($computed[ReprisesCheckService::LINE_ACCUMULATED_DEPRECIATION] * 0.85),
    ]);

    $diagnostics = collect(lineOf($report, ReprisesCheckService::LINE_ACCUMULATED_DEPRECIATION)['diagnostics'])
        ->keyBy('code');

    expect($diagnostics['land_share']['corroborated'])->toBeTrue();
});

it('corroborates the acquisition fees when the gap matches their amount', function () {
    // Frais de notaire amortis ici, passés en charges par le cabinet : l'écart vaut leur montant.
    makeCheckProperty($this->user, ['notary_fees' => 1600000, 'agency_fees' => 0]);
    $repriseYear = makeRepriseYear($this->user, 2026);
    $computed = computedFor($this->service, $repriseYear);

    $report = $this->service->check($repriseYear, [
        ReprisesCheckService::LINE_GROSS_ASSETS => $computed[ReprisesCheckService::LINE_GROSS_ASSETS] - 1600000,
    ]);

    $diagnostics = collect(lineOf($report, ReprisesCheckService::LINE_GROSS_ASSETS)['diagnostics'])->keyBy('code');

    expect($diagnostics['acquisition_fees']['corroborated'])->toBeTrue()
        ->and($diagnostics['missing_component']['corroborated'])->toBeFalse();
});

it('corroborates the acquisition fees on the cumulated line, where the gap is only what is already depreciated', function () {
    // LE cas de la maquette, et le plus fréquent : le cabinet a passé 16 000 € de frais de
    // notaire en charges en 2019. Sur la case 028 l'écart vaut les frais ENTIERS, mais sur
    // la case 030 il ne vaut que ce qui en a été amorti depuis — quelques milliers d'euros.
    // Ne comparer qu'aux frais bruts laissait la piste NON corroborée là où elle est vraie.
    $property = makeCheckProperty($this->user, ['notary_fees' => 1600000, 'agency_fees' => 0]);
    $repriseYear = makeRepriseYear($this->user, 2026);

    $depreciated = collect(app(DepreciationService::class)->depreciationDetailForYear($property, 2025))
        ->where('type', 'notary')
        ->sum(fn ($line) => (int) $line['cumul']);

    // Le montant déjà amorti est bien une FRACTION des frais : sans quoi ce test ne
    // distinguerait pas les deux références.
    expect($depreciated)->toBeGreaterThan(0)->toBeLessThan(1600000);

    $computed = computedFor($this->service, $repriseYear);

    $report = $this->service->check($repriseYear, [
        ReprisesCheckService::LINE_ACCUMULATED_DEPRECIATION =>
            $computed[ReprisesCheckService::LINE_ACCUMULATED_DEPRECIATION] - $depreciated,
    ]);

    $line = lineOf($report, ReprisesCheckService::LINE_ACCUMULATED_DEPRECIATION);
    $diagnostics = collect($line['diagnostics'])->keyBy('code');

    expect($line['verdict'])->toBe(ReprisesCheckService::VERDICT_MISMATCH)
        ->and($diagnostics['acquisition_fees']['corroborated'])->toBeTrue()
        ->and($report['context']['acquisition_fees_depreciated'])->toBe($depreciated);
});

it('corroborates a missing component when the cabinet declares more than we rebuild', function () {
    $property = makeCheckProperty($this->user);
    // Le cabinet a une ligne de plus que la ventilation standard.
    PropertyComponent::withoutGlobalScopes()->where('property_id', $property->id)->first()->delete();

    $repriseYear = makeRepriseYear($this->user, 2026);
    $computed = computedFor($this->service, $repriseYear);

    $report = $this->service->check($repriseYear, [
        ReprisesCheckService::LINE_ACCUMULATED_DEPRECIATION => $computed[ReprisesCheckService::LINE_ACCUMULATED_DEPRECIATION] * 2,
    ]);

    $diagnostics = collect(lineOf($report, ReprisesCheckService::LINE_ACCUMULATED_DEPRECIATION)['diagnostics'])->keyBy('code');

    expect($diagnostics['missing_component']['corroborated'])->toBeTrue();
});

it('corroborates a market value retained instead of the acquisition price', function () {
    makeCheckProperty($this->user, ['market_value' => 25000000]);
    $repriseYear = makeRepriseYear($this->user, 2026);
    $computed = computedFor($this->service, $repriseYear);

    $report = $this->service->check($repriseYear, [
        ReprisesCheckService::LINE_GROSS_ASSETS => (int) ($computed[ReprisesCheckService::LINE_GROSS_ASSETS] * 0.8),
    ]);

    $diagnostics = collect(lineOf($report, ReprisesCheckService::LINE_GROSS_ASSETS)['diagnostics'])->keyBy('code');

    expect($diagnostics['market_value']['corroborated'])->toBeTrue();
});

it('corroborates a rental start date when the property started around the compared year', function () {
    makeCheckProperty($this->user, ['rental_start_date' => '2025-07-01']);
    $repriseYear = makeRepriseYear($this->user, 2026);
    $computed = computedFor($this->service, $repriseYear);

    $report = $this->service->check($repriseYear, [
        ReprisesCheckService::LINE_ACCUMULATED_DEPRECIATION => $computed[ReprisesCheckService::LINE_ACCUMULATED_DEPRECIATION] * 3 + 100000,
    ]);

    $diagnostics = collect(lineOf($report, ReprisesCheckService::LINE_ACCUMULATED_DEPRECIATION)['diagnostics'])->keyBy('code');

    expect($diagnostics['rental_start_date']['corroborated'])->toBeTrue();
});

// === LIGNES RECOPIÉES : CONTRÔLE DE TRANSCRIPTION ===

it('reads a mismatch on the deferred depreciation as a transcription error', function () {
    makeCheckProperty($this->user);
    $repriseYear = makeRepriseYear($this->user, 2026, ['opening_deferred_depreciation' => 1200000]);

    $report = $this->service->check($repriseYear, [
        ReprisesCheckService::LINE_DEFERRED_DEPRECIATION => 2100000, // chiffres inversés
    ]);

    $line = lineOf($report, ReprisesCheckService::LINE_DEFERRED_DEPRECIATION);

    expect($line['verdict'])->toBe(ReprisesCheckService::VERDICT_MISMATCH)
        ->and($line['diagnostics'][0]['code'])->toBe('transcription')
        ->and($line['diagnostics'][0]['hint'])->toContain('2033-D case 870');
});

it('compares the remaining deficits against the opening vintages', function () {
    makeCheckProperty($this->user);
    $repriseYear = makeRepriseYear($this->user, 2026, [
        'opening_deficits' => [
            ['origin_year' => 2022, 'amount' => 120000],
            ['origin_year' => 2023, 'amount' => 80000],
        ],
    ]);

    $report = $this->service->check($repriseYear, [
        ReprisesCheckService::LINE_DEFICIT_CARRYFORWARD => 200000,
    ]);

    $line = lineOf($report, ReprisesCheckService::LINE_DEFICIT_CARRYFORWARD);

    expect($line['computed'])->toBe(200000)
        ->and($line['verdict'])->toBe(ReprisesCheckService::VERDICT_MATCH);
});

// === LIGNES NON RENSEIGNÉES ET RÉSULTAT INFORMATIF ===

it('does not compare a line the liasse does not fill', function () {
    makeCheckProperty($this->user);
    $repriseYear = makeRepriseYear($this->user, 2026);

    $report = $this->service->check($repriseYear, []);

    expect(collect($report['lines'])->pluck('verdict')->unique()->all())
        ->toBe([ReprisesCheckService::VERDICT_UNCHECKED]);
});

it('keeps the fiscal result informative, with nothing to rebuild', function () {
    makeCheckProperty($this->user);
    $repriseYear = makeRepriseYear($this->user, 2026);

    $report = $this->service->check($repriseYear, [
        ReprisesCheckService::LINE_FISCAL_RESULT => 450000,
    ]);

    $line = lineOf($report, ReprisesCheckService::LINE_FISCAL_RESULT);

    expect($line['computed'])->toBeNull()
        ->and($line['verdict'])->toBe(ReprisesCheckService::VERDICT_UNCHECKED)
        ->and($line['cerfa'])->toBe('2033-B cases 352 / 354');
});

// === LE PIÈGE DU LOT 1 REMONTE JUSQU'ICI ===

it('warns when an empty previous fiscal year contradicts the opening balances', function () {
    makeCheckProperty($this->user);

    // Exercice 2025 créé par erreur, jamais alimenté.
    FiscalYear::forceCreate([
        'user_id' => $this->user->id,
        'year' => 2025,
        'status' => FiscalYear::STATUS_DRAFT,
    ]);

    $report = $this->service->check(makeRepriseYear($this->user, 2026), []);

    expect($report['warning'])->toContain('2025')->toContain('aucune donnée');
});

// === CONTEXTE ===

it('exposes the amortisable base and the acquisition fees behind the diagnosis', function () {
    makeCheckProperty($this->user, ['notary_fees' => 1500000, 'agency_fees' => 500000]);
    $repriseYear = makeRepriseYear($this->user, 2026);

    $report = $this->service->check($repriseYear, []);

    expect($report['context']['acquisition_fees'])->toBe(2000000)
        ->and($report['context']['amortisable_base'])->toBeGreaterThan(0);
});

it('flags acquisition fees the accountant had expensed, on case 014', function () {
    // Le cas que la case 030 assainie ne révèle plus : un comptable qui a passé les frais en
    // charges l'année de l'acquisition porte 0 en case 014, alors que l'application les amortit
    // encore. Avant le 2026-09-05 l'écart se voyait par accident, à travers une case 030 qui
    // mélangeait corporel et incorporel ; il se voit désormais là où il appartient.
    makeCheckProperty($this->user, ['notary_fees' => 1600000]);

    $repriseYear = makeRepriseYear($this->user, 2025);

    $report = $this->service->check($repriseYear, [
        ReprisesCheckService::LINE_INTANGIBLE_ASSETS => 0,
    ]);

    $line = collect($report['lines'])->firstWhere('key', ReprisesCheckService::LINE_INTANGIBLE_ASSETS);

    expect($line['computed'])->toBe(1600000)
        ->and($line['declared'])->toBe(0)
        ->and($line['verdict'])->toBe(ReprisesCheckService::VERDICT_MISMATCH);
});

it('says nothing when the accountant capitalised the fees like we do', function () {
    makeCheckProperty($this->user, ['notary_fees' => 1600000]);

    $repriseYear = makeRepriseYear($this->user, 2025);

    $report = $this->service->check($repriseYear, [
        ReprisesCheckService::LINE_INTANGIBLE_ASSETS => 1600000,
    ]);

    $line = collect($report['lines'])->firstWhere('key', ReprisesCheckService::LINE_INTANGIBLE_ASSETS);

    expect($line['verdict'])->toBe(ReprisesCheckService::VERDICT_MATCH);
});
