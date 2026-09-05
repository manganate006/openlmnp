<?php

use App\Models\Furniture;
use App\Models\Property;
use App\Models\PropertyWork;
use App\Models\User;
use App\Services\DepreciationService;
use App\Services\FiscalYearService;
use App\Services\TaxReturnService;

/**
 * Le bilan 2033-A.
 *
 * Ce formulaire n'avait AUCUN test jusqu'au 2026-09-05, et c'est ce qui a laissé passer trois
 * lignes fausses, découvertes en rejouant la liasse réelle d'un cabinet :
 *
 *   1. la case 028 ne portait que la valeur de référence du bien — travaux et mobilier, qui
 *      sont pourtant des immobilisations corporelles et figurent dans notre propre 2033-C,
 *      en étaient absents ;
 *   2. la case 030 mélangeait l'amortissement des frais d'acquisition à celui du corporel,
 *      alors que le Cerfa les porte en 016 ;
 *   3. il n'existait aucune case 014/016, donc les frais n'avaient nulle part où aller.
 *
 * Rien de tout cela n'entre dans un calcul de résultat. Mais l'écran de contrôle de la reprise
 * d'une comptabilité existante compare précisément 028 et 030 à ce que l'utilisateur lit sur sa
 * liasse : un bailleur ayant fait des travaux y voyait un écart qui ne venait pas de lui.
 */
function balanceProperty(User $user, array $overrides = []): Property
{
    return Property::forceCreate(array_merge([
        'user_id' => $user->id,
        'name' => 'Bien bilan',
        'address' => '1 rue Test',
        'city' => 'Paris',
        'postal_code' => '75001',
        'type' => 'apartment',
        'total_area' => 100,
        'rented_area' => 100,
        'acquisition_date' => '2022-01-01',
        'acquisition_price' => 25000000,   // 250 000 €
        'notary_fees' => 0,
        'agency_fees' => 0,
        'market_value' => null,
        'land_percentage' => 20,           // → base amortissable 200 000 €, terrain 50 000 €
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

/** Le 2033-A de l'année demandée, pour tous les biens de l'utilisateur. */
function form2033A(User $user, int $year): array
{
    $properties = Property::withoutGlobalScopes()->where('user_id', $user->id)->get();
    $fy = app(FiscalYearService::class)->getOrCreate($user, $year);

    return app(TaxReturnService::class)->compute2033A($fy, $properties, $year);
}

it('counts works and furniture among the gross corporeal assets', function () {
    // Le défaut d'origine : 028 valait le seul prix d'acquisition. Sur la liasse réelle, il y
    // manquait exactement les travaux et le mobilier — 9 144 € sur 226 645 €.
    $property = balanceProperty($this->user);
    $this->depreciation->generateDefaultComponents($property);

    PropertyWork::create([
        'property_id' => $property->id,
        'description' => 'Agencements',
        'amount' => 1000000,               // 10 000 €
        'work_date' => '2023-01-01',
        'duration_years' => 10,
        'is_dedicated' => true,
    ]);

    Furniture::create([
        'property_id' => $property->id,
        'description' => 'Mobilier',
        'amount' => 500000,                // 5 000 €
        'purchase_date' => '2023-01-01',
        'duration_years' => 5,
        'is_dedicated' => true,
    ]);

    // 250 000 € (terrain compris, il reste corporel) + 10 000 € + 5 000 €
    expect(form2033A($this->user, 2025)['028'])->toBe(26500000);
});

it('carries acquisition fees on the incorporeal lines, out of 028 and 030', function () {
    // Un cabinet porte les frais de notaire en 014/016, jamais avec les constructions.
    $property = balanceProperty($this->user, ['notary_fees' => 2500000]); // 25 000 €
    $this->depreciation->generateDefaultComponents($property);

    $form = form2033A($this->user, 2025);

    // Amortis sur 25 ans depuis la mise en location (2023) : trois exercices courus.
    expect($form['014'])->toBe(2500000)
        ->and($form['016'])->toBe(300000)
        // La valeur brute des frais ne gonfle pas le corporel…
        ->and($form['028'])->toBe(25000000)
        // … et leur amortissement ne se mélange plus au cumul corporel.
        ->and($form['030'])->toBeLessThan($form['048']);
});

it('totals the two families and keeps the balance sheet balanced', function () {
    $property = balanceProperty($this->user, ['notary_fees' => 2500000]);
    $this->depreciation->generateDefaultComponents($property);

    Furniture::create([
        'property_id' => $property->id,
        'description' => 'Mobilier',
        'amount' => 500000,
        'purchase_date' => '2023-01-01',
        'duration_years' => 5,
        'is_dedicated' => true,
    ]);

    $form = form2033A($this->user, 2025);

    expect($form['044'])->toBe($form['028'] + $form['014'])
        ->and($form['048'])->toBe($form['030'] + $form['016'])
        // L'actif net porte les DEUX familles, sinon le total ne se réconcilie plus à l'écran.
        ->and($form['112'])->toBe($form['044'] - $form['048'])
        ->and($form['180'])->toBe($form['112']);
});

it('applies the quota share of a partly rented home to the gross assets', function () {
    // 40 m² loués sur 100 : le bilan ne porte que la fraction louée du bien.
    $property = balanceProperty($this->user, [
        'is_primary_residence' => true,
        'rented_area' => 40,
    ]);
    $this->depreciation->generateDefaultComponents($property);

    PropertyWork::create([
        'property_id' => $property->id,
        'description' => 'Travaux communs',
        'amount' => 1000000,
        'work_date' => '2023-01-01',
        'duration_years' => 10,
        'is_dedicated' => false,           // partagés : quote-part appliquée
    ]);

    // (250 000 € + 10 000 €) × 40 %
    expect(form2033A($this->user, 2025)['028'])->toBe(10400000);
});
