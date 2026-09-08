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
 * Les quatre traitements des frais d'acquisition, et l'invariant qui doit survivre aux quatre.
 *
 * Née de l'issue #11 : un utilisateur découvrait au bilan une ligne « Immob. incorporelles
 * brut (014) » de 8 900 € que rien n'expliquait. Le calcul était juste au centime — c'étaient
 * ses frais de notaire et d'agence — mais trois choses le trompaient :
 *
 *   1. l'aide du bien affirmait que « prix d'achat + frais de notaire » servait de base
 *      d'amortissement, ce qui était faux ;
 *   2. le libellé de l'option annonçait « incorporés au coût du bien » alors que le code en
 *      faisait une immobilisation SÉPARÉE ;
 *   3. rien, nulle part, ne reliait un montant de la liasse à ce qui l'avait produit.
 *
 * `capitalized` livre enfin ce que le libellé promettait, et `amortized` — la vieille
 * option, renommée — range désormais ses frais avec les corporelles : un frais d'acquisition
 * capitalisé fait partie du coût de l'immobilisation acquise (PCG art. 213-8).
 */
function feesProperty(User $user, array $overrides = []): Property
{
    return Property::forceCreate(array_merge([
        'user_id' => $user->id,
        'name' => 'Bien à frais',
        'address' => '1 rue des Frais',
        'city' => 'Paris',
        'postal_code' => '75001',
        'type' => 'apartment',
        'total_area' => 100,
        'rented_area' => 100,
        'acquisition_date' => '2022-01-01',
        'acquisition_price' => 20_000_000,   // 200 000 €
        'notary_fees' => 1_600_000,          // 16 000 €
        'agency_fees' => 0,
        'market_value' => null,
        'land_percentage' => 15,
        'rental_start_date' => '2023-01-01',
        'rental_type' => 'seasonal',
        'is_primary_residence' => false,
    ], $overrides));
}

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->depreciation = app(DepreciationService::class);
    $this->taxReturn = app(TaxReturnService::class);
});

// ─────────────────────────────────────────────────────────────────────
// La base amortissable
// ─────────────────────────────────────────────────────────────────────

it('leaves the depreciable base untouched under every treatment but capitalisation', function (string $treatment) {
    $property = feesProperty($this->user, ['acquisition_fees_treatment' => $treatment]);

    // 200 000 € × 85 % — les frais restent dehors.
    expect((int) $property->depreciable_base)->toBe(17_000_000);
})->with([
    Property::ACQUISITION_FEES_AMORTIZED,
    Property::ACQUISITION_FEES_EXPENSED,
    Property::ACQUISITION_FEES_EXCLUDED,
]);

it('grows the depreciable base by the built share of the fees when they are capitalised', function () {
    $property = feesProperty($this->user, [
        'acquisition_fees_treatment' => Property::ACQUISITION_FEES_CAPITALIZED,
    ]);

    // (200 000 + 16 000) × 85 % : la part terrain des frais (2 400 €) cesse de s'amortir,
    // et c'est exactement ce que la capitalisation implique.
    expect((int) $property->depreciable_base)->toBe(18_360_000)
        ->and($property->referenceValue())->toBe('21600000');
});

it('ignores capitalised fees when a market value takes over as the reference', function () {
    // Une valeur vénale est la valeur d'entrée dans l'activité : les frais d'une acquisition
    // antérieure n'ont aucune raison de s'y ajouter. La combinaison n'est pas refusée — elle
    // est signalée, sur le formulaire du bien et dans le rapport de diagnostic.
    $property = feesProperty($this->user, [
        'acquisition_fees_treatment' => Property::ACQUISITION_FEES_CAPITALIZED,
        'market_value' => 25_000_000,
    ]);

    expect($property->referenceValue())->toBe('25000000')
        ->and((int) $property->depreciable_base)->toBe(21_250_000)
        ->and($property->acquisitionFeesIgnoredByMarketValue())->toBeTrue();
});

// ─────────────────────────────────────────────────────────────────────
// L'invariant qui tient les deux formulaires ensemble
// ─────────────────────────────────────────────────────────────────────

/**
 * ⚠️ `044 − 490` n'est pas un écart quelconque : c'est EXACTEMENT le reliquat de ventilation,
 * `depreciable_base − Σ base_amount`. Tous les autres termes des deux sommes viennent des
 * mêmes expressions et s'annulent au centime — à condition que la valeur de référence du bien
 * soit la même des deux côtés. Elle était recalculée à trois endroits indépendants jusqu'au
 * 2026-09-08 ; faire entrer les frais dans l'un des trois seulement aurait fait diverger le
 * bilan de l'état des immobilisations, sans qu'aucun des deux paraisse faux isolément.
 */
it('keeps 044 − 490 equal to the unallocated share under every treatment', function (string $treatment) {
    $property = feesProperty($this->user, ['acquisition_fees_treatment' => $treatment]);
    $this->depreciation->generateDefaultComponents($property);

    PropertyWork::create([
        'property_id' => $property->id,
        'description' => 'Réfection',
        'amount' => 900_000,
        'work_date' => '2023-04-01',
        'duration_years' => 10,
        'is_dedicated' => true,
    ]);

    Furniture::create([
        'property_id' => $property->id,
        'description' => 'Mobilier',
        'amount' => 400_000,
        'purchase_date' => '2023-04-01',
        'duration_years' => 5,
        'is_dedicated' => true,
    ]);

    $property->refresh();
    $properties = Property::withoutGlobalScopes()->where('user_id', $this->user->id)->get();
    $fy = app(FiscalYearService::class)->getOrCreate($this->user, 2025);

    $form2033A = $this->taxReturn->compute2033A($fy, $properties, 2025);
    $form2033C = $this->taxReturn->compute2033C($properties, 2025);

    $reliquat = (int) bcsub(
        $property->depreciable_base,
        (string) $property->components->sum('base_amount'),
        0
    );

    expect($form2033A['044'] - $form2033C['total_brut'])->toBe($reliquat);
})->with([
    Property::ACQUISITION_FEES_AMORTIZED,
    Property::ACQUISITION_FEES_CAPITALIZED,
    Property::ACQUISITION_FEES_EXPENSED,
    Property::ACQUISITION_FEES_EXCLUDED,
]);

it('keeps line 572 equal to line 254 under every treatment', function (string $treatment) {
    $property = feesProperty($this->user, ['acquisition_fees_treatment' => $treatment]);
    $this->depreciation->generateDefaultComponents($property);

    $properties = Property::withoutGlobalScopes()->where('user_id', $this->user->id)->get();
    $fy = app(FiscalYearService::class)->getOrCreate($this->user, 2025);

    $form2033B = $this->taxReturn->compute2033B($fy, $properties, 2025);
    $form2033C = $this->taxReturn->compute2033C($properties, 2025);

    expect($form2033C['total_dotation'])->toBe((int) $form2033B['254']);
})->with([
    Property::ACQUISITION_FEES_AMORTIZED,
    Property::ACQUISITION_FEES_CAPITALIZED,
    Property::ACQUISITION_FEES_EXPENSED,
    Property::ACQUISITION_FEES_EXCLUDED,
]);

// ─────────────────────────────────────────────────────────────────────
// Le dossier de l'issue #11, chiffre pour chiffre
// ─────────────────────────────────────────────────────────────────────

it('reproduces the balance sheet of issue #11 with the fees where they belong', function () {
    // Reconstitué depuis les captures du rapporteur : bien à 98 400 € une fois le mobilier
    // sorti du prix, 15 % de terrain, 5 100 € de mobilier, 8 900 € de frais d'acquisition.
    // Son bilan portait 028 = 103 500, 014 = 8 900, 044 = 112 400 — et c'est la ligne 014
    // qu'il ne s'expliquait pas.
    $property = feesProperty($this->user, [
        'acquisition_price' => 9_840_000,
        'notary_fees' => 890_000,
        'rental_start_date' => '2022-03-01',
    ]);

    $this->depreciation->syncComponents($property, [
        ['name' => 'Gros œuvre', 'duration_years' => 40, 'sort_order' => 0, 'percentage' => 60],
        ['name' => 'Installations', 'duration_years' => 20, 'sort_order' => 1, 'percentage' => 20],
        ['name' => 'Agencements', 'duration_years' => 15, 'sort_order' => 2, 'percentage' => 20],
    ]);

    Furniture::create([
        'property_id' => $property->id,
        'description' => 'Mobilier',
        'amount' => 510_000,
        'purchase_date' => '2022-03-01',
        'duration_years' => 10,
        'is_dedicated' => true,
    ]);

    $properties = Property::withoutGlobalScopes()->where('user_id', $this->user->id)->get();
    $fy = app(FiscalYearService::class)->getOrCreate($this->user, 2022);
    $form = $this->taxReturn->compute2033A($fy, $properties, 2022);

    // Le TOTAL ne change pas — c'est bien le même bilan, au centime près.
    expect($form['044'])->toBe(11_240_000)
        // Mais plus rien n'atterrit sur la ligne qu'il ne s'expliquait pas…
        ->and($form['014'])->toBe(0)
        // … et les 8 900 € sont désormais au milieu des corporelles, avec le bien et le
        // mobilier : 98 400 + 5 100 + 8 900.
        ->and($form['028'])->toBe(11_240_000);

    // Et la ligne du 2033-C qui les porte est bien celle des constructions.
    $lines = collect($this->depreciation->depreciationDetailForYear($property->fresh(), 2022));

    expect($lines->firstWhere('type', 'notary')['cerfa_category'])
        ->toBe(PropertyComponent::CERFA_CATEGORY_CONSTRUCTIONS);
});

// ─────────────────────────────────────────────────────────────────────
// La quote-part
// ─────────────────────────────────────────────────────────────────────

it('prorates the fees by the quota share even outside a primary residence', function () {
    // ⚠️ La quote-part n'était appliquée aux frais QUE sur une résidence principale, alors
    // qu'elle l'est toujours à la valeur du bien. Une chambre louée dans un bien qui n'est
    // pas la résidence principale portait donc 100 % de ses frais face à une base déjà
    // proratisée — un actif qui n'existait qu'à moitié.
    $property = feesProperty($this->user, [
        'total_area' => 100,
        'rented_area' => 40,
        'is_primary_residence' => false,
    ]);
    $this->depreciation->generateDefaultComponents($property);

    $line = collect($this->depreciation->depreciationDetailForYear($property->fresh(), 2025))
        ->firstWhere('type', 'notary');

    // 16 000 € × 40 %
    expect((int) $line['base'])->toBe(640_000);
});
