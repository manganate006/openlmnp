<?php

use App\Models\Furniture;
use App\Models\Property;
use App\Models\PropertyWork;
use App\Models\User;
use App\Services\DepreciationService;
use App\Services\DiagnosticReportService;

/**
 * Le rapport de diagnostic.
 *
 * Il existe parce que deux tickets de septembre 2026 ont buté sur la même chose : nous ne
 * savions pas ce que l'utilisateur avait saisi. L'un a joint deux captures d'écran et son
 * dossier a pu être reconstitué à l'euro ; l'autre n'en a pas joint, et son cas est resté
 * indécidable.
 *
 * ⚠️ Les tests de CONFIDENTIALITÉ sont les plus importants du fichier. Ce rapport est
 * destiné à être collé dans une issue GitHub publique : y laisser fuir une adresse ou une
 * commune serait un dommage qu'on ne peut pas reprendre.
 */
function diagnosticProperty(User $user, array $overrides = []): Property
{
    return Property::forceCreate(array_merge([
        'user_id' => $user->id,
        'name' => 'Studio de Camille',
        'address' => '14 rue Confidentielle',
        'city' => 'Villefranche-sur-Saône',
        'postal_code' => '69400',
        'insee_code' => '69264',
        'type' => 'apartment',
        'total_area' => 100,
        'rented_area' => 100,
        'acquisition_date' => '2022-01-01',
        'acquisition_price' => 20_000_000,
        'notary_fees' => 1_600_000,
        'agency_fees' => 0,
        'market_value' => null,
        'land_percentage' => 15,
        'rental_start_date' => '2023-01-01',
        'rental_type' => 'seasonal',
        'is_primary_residence' => false,
    ], $overrides));
}

beforeEach(function () {
    $this->user = User::factory()->create([
        'email' => 'camille.dupont@exemple.test',
        'name'  => 'Camille Dupont',
    ]);
    $this->service = app(DiagnosticReportService::class);
});

// ─────────────────────────────────────────────────────────────────────
// Confidentialité
// ─────────────────────────────────────────────────────────────────────

it('never leaks a single identifying field', function () {
    $property = diagnosticProperty($this->user);
    app(DepreciationService::class)->generateDefaultComponents($property);

    $report = $this->service->build($this->user, 2025);
    $text = $this->service->toText($report);
    $serialised = json_encode($report, JSON_UNESCAPED_UNICODE);

    // ⚠️ On cherche dans les DEUX représentations : le texte est ce que l'utilisateur relit,
    // le tableau est ce que `--json` publie. Ne contrôler que le premier laisserait la fuite
    // passer par la seconde porte.
    foreach ([
        'Camille Dupont',
        'camille.dupont@exemple.test',
        '14 rue Confidentielle',
        'Villefranche-sur-Saône',
        '69400',
        '69264',
        'Studio de Camille',
    ] as $secret) {
        expect($text)->not->toContain($secret)
            ->and($serialised)->not->toContain($secret);
    }
});

it('numbers the properties instead of naming them', function () {
    diagnosticProperty($this->user);
    diagnosticProperty($this->user, ['name' => 'Le deuxième']);

    $text = $this->service->toText($this->service->build($this->user, 2025));

    expect($text)->toContain('Bien #1')->toContain('Bien #2');
});

it('keeps the labels the user typed, because they are the diagnosis', function () {
    // Contrepartie assumée de l'anonymisation : un intitulé de composant est ce qui permet de
    // reconnaître le plan d'un cabinet. La modale prévient qu'il faut relire avant d'envoyer.
    $property = diagnosticProperty($this->user);
    app(DepreciationService::class)->syncComponents($property, [
        ['name' => 'Toiture ardoise du cabinet', 'duration_years' => 25, 'sort_order' => 0, 'percentage' => 100],
    ]);

    expect($this->service->toText($this->service->build($this->user, 2025)))
        ->toContain('Toiture ardoise du cabinet');
});

// ─────────────────────────────────────────────────────────────────────
// Ce qu'il doit dire
// ─────────────────────────────────────────────────────────────────────

it('reports the inputs that decide the depreciable base', function () {
    $property = diagnosticProperty($this->user);
    app(DepreciationService::class)->generateDefaultComponents($property);

    $report = $this->service->build($this->user, 2025);
    $inputs = $report['properties'][0]['inputs'];

    expect($inputs['prix_acquisition'])->toBe(20_000_000)
        ->and($inputs['valeur_reference'])->toBe(20_000_000)
        ->and($inputs['part_terrain_pct'])->toBe(15)
        ->and($inputs['frais_notaire'])->toBe(1_600_000)
        ->and($inputs['traitement_frais'])->toBe(Property::ACQUISITION_FEES_AMORTIZED)
        ->and($report['properties'][0]['base_amortissable'])->toBe(17_000_000);
});

it('shows the reference value the fees actually joined', function () {
    // Le chiffre qu'un utilisateur ne reconnaît pas quand il capitalise ses frais : sa
    // valeur de référence n'est plus son prix d'achat.
    $property = diagnosticProperty($this->user, [
        'acquisition_fees_treatment' => Property::ACQUISITION_FEES_CAPITALIZED,
    ]);
    app(DepreciationService::class)->generateDefaultComponents($property);

    $inputs = $this->service->build($this->user, 2025)['properties'][0]['inputs'];

    expect($inputs['valeur_reference'])->toBe(21_600_000)
        ->and($inputs['frais_ecartes_par_valeur_venale'])->toBeFalse();
});

it('flags capitalised fees that a market value quietly discards', function () {
    // Le piège silencieux : l'utilisateur a choisi « intégrés au coût du bien », et rien à
    // l'écran de la liasse ne dit que sa valeur vénale les a écartés.
    $property = diagnosticProperty($this->user, [
        'acquisition_fees_treatment' => Property::ACQUISITION_FEES_CAPITALIZED,
        'market_value' => 25_000_000,
    ]);
    app(DepreciationService::class)->generateDefaultComponents($property);

    $report = $this->service->build($this->user, 2025);

    expect($report['properties'][0]['inputs']['frais_ecartes_par_valeur_venale'])->toBeTrue()
        ->and($this->service->toText($report))->toContain('Frais capitalisés IGNORÉS');
});

it('reports the three gaps that explain almost every ticket', function () {
    $property = diagnosticProperty($this->user);

    // Sous-ventilation délibérée : 80 % seulement de la base est rattachée à un composant.
    app(DepreciationService::class)->syncComponents($property, [
        ['name' => 'Gros œuvre', 'duration_years' => 40, 'sort_order' => 0, 'percentage' => 80],
    ]);

    $report = $this->service->build($this->user, 2025);

    // 17 000 000 × 20 %
    expect($report['gaps']['base_moins_composants'])->toBe(3_400_000)
        // L'invariant : `044 − 490` vaut exactement la part non ventilée.
        ->and($report['gaps']['044_moins_490'])->toBe($report['gaps']['base_moins_composants'])
        // Et la dotation ne peut pas diverger entre les deux formulaires.
        ->and($report['gaps']['572_moins_254'])->toBe(0);
});

it('lists works and furniture with both the VAT-inclusive and excluding amounts', function () {
    $property = diagnosticProperty($this->user, ['tva_regime' => Property::TVA_LIABLE]);

    PropertyWork::create([
        'property_id' => $property->id,
        'description' => 'Réfection toiture',
        'amount' => 1_200_000,
        'tva_rate' => 2000,
        'work_date' => '2023-01-01',
        'duration_years' => 10,
        'is_dedicated' => true,
    ]);

    Furniture::create([
        'property_id' => $property->id,
        'description' => 'Canapé',
        'amount' => 600_000,
        'tva_rate' => 2000,
        'purchase_date' => '2023-01-01',
        'duration_years' => 5,
        'is_dedicated' => true,
    ]);

    $report = $this->service->build($this->user, 2025);
    $property = $report['properties'][0];

    expect($property['works'][0]['montant_ttc'])->toBe(1_200_000)
        ->and($property['works'][0]['montant_ht'])->toBe(1_000_000)
        ->and($property['furniture'][0]['montant_ht'])->toBe(500_000);
});

it('survives an account that has no property at all', function () {
    // Le premier écran qu'un utilisateur perdu ouvre est rarement celui qu'on croit.
    $report = $this->service->build($this->user, 2025);

    expect($report['properties'])->toBeEmpty()
        ->and($report)->not->toHaveKey('forms')
        ->and($this->service->toText($report))->toContain('Aucun bien enregistré');
});

it('carries a schema version, so a pasted report stays readable later', function () {
    expect($this->service->build($this->user, 2025)['schema_version'])
        ->toBe(DiagnosticReportService::SCHEMA_VERSION);
});

// ─────────────────────────────────────────────────────────────────────
// La commande console
// ─────────────────────────────────────────────────────────────────────

it('prints the report from the console for a self-hosted instance', function () {
    $property = diagnosticProperty($this->user);
    app(DepreciationService::class)->generateDefaultComponents($property);

    // Un seul compte : inutile de réclamer son adresse à quelqu'un qui est seul chez lui.
    $this->artisan('openlmnp:diagnostic', ['--year' => 2025])
        ->expectsOutputToContain('RAPPORT DE DIAGNOSTIC OPENLMNP')
        ->expectsOutputToContain('BASE AMORTISSABLE')
        ->assertSuccessful();
});

it('asks for an address when several accounts share the instance', function () {
    User::factory()->create(['email' => 'second@exemple.test']);

    $this->artisan('openlmnp:diagnostic')
        ->expectsOutputToContain('précisez une adresse')
        ->assertFailed();
});
