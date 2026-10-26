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
        // D'abord la cause que les chiffres appuient (le cabinet déclare PLUS : il nous
        // manque quelque chose), puis les autres par ordre de fréquence.
        ->and(array_column($line['diagnostics'], 'code'))->toBe([
            'missing_component',
            'rental_start_date',
            'land_share',
            'acquisition_fees',
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

    // Sur la valeur brute, la cause se nomme « passés en charges » : c'est la seule lecture
    // possible d'un total 044 auquel manquent les frais entiers.
    expect($diagnostics['acquisition_fees_expensed']['corroborated'])->toBeTrue()
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

it('flags acquisition fees the accountant had expensed, on the gross total', function () {
    // Le cas à révéler : un comptable qui a passé les frais en charges l'année de
    // l'acquisition ne les porte NULLE PART dans ses immobilisations, alors que nous les
    // amortissons encore. Son total 044 est donc inférieur au nôtre du montant des frais.
    //
    // ⚠️ Ce test a porté sur la case 014 du 2026-09-05 au 2026-09-08. Il ne pouvait plus
    // rien mesurer une fois les frais reclassés en corporelles : notre 014 vaut zéro, donc
    // « 0 déclaré contre 0 calculé » aurait été un accord parfait sur un dossier faux.
    makeCheckProperty($this->user, ['notary_fees' => 1600000]);

    $repriseYear = makeRepriseYear($this->user, 2025);

    // 200 000 € d'immobilisations chez le cabinet, 216 000 € chez nous : l'écart vaut
    // exactement les frais.
    $report = $this->service->check($repriseYear, [
        ReprisesCheckService::LINE_GROSS_ASSETS => 20_000_000,
    ]);

    $line = collect($report['lines'])->firstWhere('key', ReprisesCheckService::LINE_GROSS_ASSETS);

    expect($line['computed'])->toBe(21_600_000)
        ->and($line['declared'])->toBe(20_000_000)
        ->and($line['verdict'])->toBe(ReprisesCheckService::VERDICT_MISMATCH);
});

it('says nothing when the accountant capitalised the fees like we do', function () {
    makeCheckProperty($this->user, ['notary_fees' => 1600000]);

    $repriseYear = makeRepriseYear($this->user, 2025);

    $report = $this->service->check($repriseYear, [
        ReprisesCheckService::LINE_GROSS_ASSETS => 21_600_000,
    ]);

    $line = collect($report['lines'])->firstWhere('key', ReprisesCheckService::LINE_GROSS_ASSETS);

    expect($line['verdict'])->toBe(ReprisesCheckService::VERDICT_MATCH);
});

it('does not care whether the accountant showed the fees on 014 or on 028', function () {
    // La raison d'être du passage à la case 044 : deux cabinets présentent les mêmes frais
    // sur deux lignes différentes, et aucun des deux n'a tort. Comparer 028 aurait mis en
    // rouge une convention de présentation qui ne coûte pas un euro.
    makeCheckProperty($this->user, ['notary_fees' => 1600000]);

    $repriseYear = makeRepriseYear($this->user, 2025);

    // Le cabinet a porté 200 000 € en 028 et 16 000 € en 014 : son total est le nôtre.
    $report = $this->service->check($repriseYear, [
        ReprisesCheckService::LINE_GROSS_ASSETS => 20_000_000 + 1_600_000,
    ]);

    $line = collect($report['lines'])->firstWhere('key', ReprisesCheckService::LINE_GROSS_ASSETS);

    expect($line['cerfa'])->toBe('2033-A case 044')
        ->and($line['verdict'])->toBe(ReprisesCheckService::VERDICT_MATCH);
});

// ─────────────────────────────────────────────────────────────────────
// Un écart « proche » ne doit plus être muet
// ─────────────────────────────────────────────────────────────────────

it('explains a close gap instead of leaving the user with a silent badge', function () {
    // ⚠️ Le cas resté sans réponse : sous la tolérance, aucun diagnostic n'était produit.
    // L'utilisateur lisait « proche » et n'avait aucun moyen de savoir s'il devait s'en
    // inquiéter — alors que c'est exactement ce qui subsiste après avoir corrigé un double
    // comptage, et que la cause est parfaitement identifiable.
    makeCheckProperty($this->user);

    $repriseYear = makeRepriseYear($this->user, 2025);

    $computed = (int) $this->service->check($repriseYear, [])['lines'][1]['computed'];

    // Un écart de 0,5 % : sous la tolérance de 1 %, donc verdict « proche ».
    $report = $this->service->check($repriseYear, [
        ReprisesCheckService::LINE_ACCUMULATED_DEPRECIATION => (int) round($computed * 0.995),
    ]);

    $line = collect($report['lines'])
        ->firstWhere('key', ReprisesCheckService::LINE_ACCUMULATED_DEPRECIATION);

    expect($line['verdict'])->toBe(ReprisesCheckService::VERDICT_CLOSE)
        ->and($line['diagnostics'])->toHaveCount(1)
        ->and($line['diagnostics'][0]['code'])->toBe('prorata_convention')
        // ⚠️ Le mot « arrondi » est proscrit : la troncature au centime ne peut pas produire
        // des dizaines d'euros, et l'écrire enverrait l'utilisateur sur une fausse piste.
        ->and($line['diagnostics'][0]['hint'])->not->toContain('arrondi')
        ->and($line['diagnostics'][0]['hint'])->toContain('JOUR');
});

it('explains a close gap caused by acquisition fees, instead of blaming a convention', function () {
    // ⚠️ Le cas réel du 2026-10-15 : −859 € sur la 030, entièrement expliqués par le
    // rangement des frais d'acquisition (030 chez nous, 016 chez le cabinet) — mais
    // AVANT correctif, l'écran l'attribuait à une convention de prorata. Un montant
    // synthétique rejoue la même mécanique, sans recopier le chiffre réel.
    $property = makeCheckProperty($this->user, ['notary_fees' => 60_000, 'agency_fees' => 0]);
    $repriseYear = makeRepriseYear($this->user, 2025);

    $depreciated = collect(app(DepreciationService::class)->depreciationDetailForYear($property, 2024))
        ->where('type', 'notary')
        ->sum(fn ($line) => (int) $line['cumul']);

    $computed = computedFor($this->service, $repriseYear)[ReprisesCheckService::LINE_ACCUMULATED_DEPRECIATION];

    // Le test ne mesure rien hors de la fourchette « proche » : on le vérifie plutôt
    // que de le supposer (même précaution que le test « bien neuf » de TaxReturnService).
    expect($depreciated)->toBeGreaterThan(100)
        ->and(bcdiv((string) $depreciated, (string) $computed, 6))->toBeLessThanOrEqual('0.01');

    $report = $this->service->check($repriseYear, [
        ReprisesCheckService::LINE_ACCUMULATED_DEPRECIATION => $computed - $depreciated,
    ]);

    $line = lineOf($report, ReprisesCheckService::LINE_ACCUMULATED_DEPRECIATION);
    $diagnostics = collect($line['diagnostics'])->keyBy('code');

    expect($line['verdict'])->toBe(ReprisesCheckService::VERDICT_CLOSE)
        ->and($diagnostics->has('acquisition_fees'))->toBeTrue()
        ->and($diagnostics['acquisition_fees']['corroborated'])->toBeTrue()
        ->and($diagnostics->has('prorata_convention'))->toBeFalse()
        ->and($diagnostics['acquisition_fees']['hint'])
            ->toContain('amortis en 030')
            ->toContain('016');
});

it('still blames a prorata convention when nothing corroborates the acquisition fees', function () {
    // Contre-épreuve du test précédent : sans frais de notaire, rien ne corrobore la
    // piste « frais d'acquisition » — l'écran doit continuer à dire « convention »,
    // pas se taire ni inventer une corroboration.
    makeCheckProperty($this->user, ['notary_fees' => 0, 'agency_fees' => 0]);
    $repriseYear = makeRepriseYear($this->user, 2025);

    $computed = (int) $this->service->check($repriseYear, [])['lines'][1]['computed'];

    $report = $this->service->check($repriseYear, [
        ReprisesCheckService::LINE_ACCUMULATED_DEPRECIATION => (int) round($computed * 0.995),
    ]);

    $line = lineOf($report, ReprisesCheckService::LINE_ACCUMULATED_DEPRECIATION);

    expect($line['verdict'])->toBe(ReprisesCheckService::VERDICT_CLOSE)
        ->and($line['diagnostics'])->toHaveCount(1)
        ->and($line['diagnostics'][0]['code'])->toBe('prorata_convention');
});

it('cites travaux and mobilier among the causes of a missing component', function () {
    makeCheckProperty($this->user);
    $repriseYear = makeRepriseYear($this->user, 2026);
    $computed = computedFor($this->service, $repriseYear);

    $report = $this->service->check($repriseYear, [
        ReprisesCheckService::LINE_ACCUMULATED_DEPRECIATION =>
            $computed[ReprisesCheckService::LINE_ACCUMULATED_DEPRECIATION] * 2,
    ]);

    $diagnostics = collect(lineOf($report, ReprisesCheckService::LINE_ACCUMULATED_DEPRECIATION)['diagnostics'])
        ->keyBy('code');

    expect($diagnostics['missing_component']['hint'])
        ->toContain('travaux')
        ->toContain('mobilier');
});

it('keeps the full list of causes for a real mismatch', function () {
    // La contrepartie : au-delà de la tolérance, il faut bien dérouler toutes les pistes.
    makeCheckProperty($this->user);

    $repriseYear = makeRepriseYear($this->user, 2025);

    $report = $this->service->check($repriseYear, [
        ReprisesCheckService::LINE_ACCUMULATED_DEPRECIATION => 1,
    ]);

    $line = collect($report['lines'])
        ->firstWhere('key', ReprisesCheckService::LINE_ACCUMULATED_DEPRECIATION);

    expect($line['verdict'])->toBe(ReprisesCheckService::VERDICT_MISMATCH)
        ->and(count($line['diagnostics']))->toBeGreaterThan(1);
});

// === Recette bêta du 2026-10-15 : diagnostic 030/016 et causes par ligne (D11) ===

/** Cumul des frais d'acquisition déjà amortis à la clôture 2025. */
function feesCumulAt2025(Property $property): int
{
    return (int) collect(app(DepreciationService::class)->depreciationDetailForYear($property->fresh(), 2025))
        ->where('type', 'notary')
        ->sum(fn ($line) => (int) $line['cumul']);
}

it('explains a 030 gap equal to the depreciated fees when the gross total 044 agrees', function () {
    // Premier exercice tenu par le cabinet : bien loué depuis le 1er janvier 2025, frais
    // amortis en 016 chez lui, en 030 chez nous. Le total brut est le même des deux côtés.
    $property = makeCheckProperty($this->user, [
        'notary_fees' => 1_500_000,
        'rental_start_date' => '2025-01-01',
        'acquisition_date' => '2025-01-01',
    ]);
    $repriseYear = makeRepriseYear($this->user, 2026, ['opening_deferred_depreciation' => 0]);
    $computed = computedFor($this->service, $repriseYear);
    $fees = feesCumulAt2025($property);

    expect($fees)->toBeGreaterThan(0);

    $report = $this->service->check($repriseYear, [
        ReprisesCheckService::LINE_GROSS_ASSETS => $computed[ReprisesCheckService::LINE_GROSS_ASSETS],
        ReprisesCheckService::LINE_ACCUMULATED_DEPRECIATION => $computed[ReprisesCheckService::LINE_ACCUMULATED_DEPRECIATION] - $fees,
    ]);

    $line = lineOf($report, ReprisesCheckService::LINE_ACCUMULATED_DEPRECIATION);

    expect($line['verdict'])->toBe(ReprisesCheckService::VERDICT_EXPLAINED)
        ->and(array_column($line['diagnostics'], 'code'))->toBe(['acquisition_fees_presentation'])
        // Rien « à vérifier » : l'écart n'en est pas un.
        ->and($report['verdict'])->toBe(ReprisesCheckService::VERDICT_EXPLAINED);
});

it('puts the acquisition fees first when their amount explains the gap, before mere conditions', function () {
    // Même dossier, sans la case 044 : rien ne départage 016 et charges, l'écart reste à
    // vérifier, mais la cause chiffrée passe devant la date de mise en location, que tout
    // premier exercice « corrobore ».
    $property = makeCheckProperty($this->user, [
        'notary_fees' => 1_500_000,
        'rental_start_date' => '2025-01-01',
        'acquisition_date' => '2025-01-01',
    ]);
    $repriseYear = makeRepriseYear($this->user, 2026, ['opening_deferred_depreciation' => 0]);
    $computed = computedFor($this->service, $repriseYear);
    $fees = feesCumulAt2025($property);

    $report = $this->service->check($repriseYear, [
        ReprisesCheckService::LINE_ACCUMULATED_DEPRECIATION => $computed[ReprisesCheckService::LINE_ACCUMULATED_DEPRECIATION] - $fees,
    ]);

    $line = lineOf($report, ReprisesCheckService::LINE_ACCUMULATED_DEPRECIATION);

    expect($line['verdict'])->not->toBe(ReprisesCheckService::VERDICT_EXPLAINED)
        ->and($line['diagnostics'][0]['code'])->toBe('acquisition_fees')
        ->and($line['diagnostics'][0]['evidence'])->toBe('amount');
});

it('never blames a prorata or the land share for a gap on the gross total 044', function () {
    // Un prorata ne change pas une valeur brute, et la 044 porte le terrain : ces deux
    // causes n'ont rien à faire sur cette ligne, même « corroborées » par une condition.
    makeCheckProperty($this->user, [
        'land_percentage' => 0,
        'rental_start_date' => '2025-01-01',
        'acquisition_date' => '2025-01-01',
    ]);
    $repriseYear = makeRepriseYear($this->user, 2026);
    $computed = computedFor($this->service, $repriseYear);

    $report = $this->service->check($repriseYear, [
        // Le cabinet porte 9 000 € de plus : des travaux et du mobilier absents ici.
        ReprisesCheckService::LINE_GROSS_ASSETS => $computed[ReprisesCheckService::LINE_GROSS_ASSETS] + 900_000,
    ]);

    $codes = array_column(lineOf($report, ReprisesCheckService::LINE_GROSS_ASSETS)['diagnostics'], 'code');

    expect($codes)->not->toContain('rental_start_date')
        ->and($codes)->not->toContain('land_share')
        ->and($codes[0])->toBe('missing_component');
});

it('names expensed fees on the gross total when the gap is their full amount', function () {
    makeCheckProperty($this->user, ['notary_fees' => 1_500_000]);
    $repriseYear = makeRepriseYear($this->user, 2026);
    $computed = computedFor($this->service, $repriseYear);

    $report = $this->service->check($repriseYear, [
        ReprisesCheckService::LINE_GROSS_ASSETS => $computed[ReprisesCheckService::LINE_GROSS_ASSETS] - 1_500_000,
    ]);

    $line = lineOf($report, ReprisesCheckService::LINE_GROSS_ASSETS);

    expect($line['diagnostics'][0]['code'])->toBe('acquisition_fees_expensed')
        ->and($line['diagnostics'][0]['corroborated'])->toBeTrue();
});
