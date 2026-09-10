<?php

use App\Models\Furniture;
use App\Models\Property;
use App\Models\PropertyWork;
use App\Models\User;
use App\Services\DepreciationService;

/**
 * Le cumul repris d'un cabinet ne doit pas se compter deux fois.
 *
 * Signalé le 2026-09-09 avec les chiffres qui le prouvent : sa case 030 affichait
 * **9 496 €** pour **4 736 €** déclarés dans sa liasse — soit exactement ses 4 736 € de
 * stock repris PLUS 4 760 € que l'application reconstituait pour les mêmes exercices.
 * `withOpening()` est un `bcadd` pur : rien n'empêchait le recouvrement.
 *
 * ⚠️ La borne est portée par `opening_accumulated_year`, et surtout **PAS** par
 * `depreciation_start_date`. Cette dernière est l'ORIGINE DU PLAN : elle ancre le terme et
 * le prorata de première année (une toiture refaite en 2022 sur 25 ans court jusqu'en 2046).
 * La détourner pour retarder le rejeu aurait repoussé la fin des plans d'autant — un actif
 * amorti six ans de trop, cumul final supérieur à sa base. Les tests du terme, plus bas,
 * sont là pour que personne ne refasse ce raccourci.
 */
function overlapProperty(User $user, array $overrides = []): Property
{
    return Property::forceCreate(array_merge([
        'user_id' => $user->id,
        'name' => 'Bien repris du cabinet',
        'address' => '1 rue du Cumul',
        'city' => 'Paris',
        'postal_code' => '75001',
        'type' => 'apartment',
        'total_area' => 100,
        'rented_area' => 100,
        'acquisition_date' => '2018-06-01',
        'acquisition_price' => 20_000_000,
        'notary_fees' => 0,
        'agency_fees' => 0,
        'market_value' => null,
        'land_percentage' => 20,
        'rental_start_date' => '2019-01-01',
        'rental_type' => 'seasonal',
        'is_primary_residence' => false,
    ], $overrides));
}

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->depreciation = app(DepreciationService::class);
});

/** Le cumul de la ligne d'un composant nommé, pour un exercice. */
function cumulOf(DepreciationService $svc, Property $property, string $name, int $year): int
{
    return (int) collect($svc->depreciationDetailForYear($property->fresh(), $year))
        ->firstWhere('name', $name)['cumul'];
}

// ─────────────────────────────────────────────────────────────────────
// Le recouvrement
// ─────────────────────────────────────────────────────────────────────

it('stops replaying the exercises the opening cumul already covers', function () {
    $property = overlapProperty($this->user);
    $this->depreciation->generateDefaultComponents($property);

    $roof = $property->components()->where('name', 'Toiture')->firstOrFail();
    $annual = (int) $roof->annual_depreciation;

    // Le cabinet a tenu 2019 à 2024 et arrête son cumul au 31/12/2024.
    $roof->forceFill([
        'opening_accumulated_depreciation' => 500_000,
        'opening_accumulated_year' => 2024,
    ])->save();

    $property->unsetRelation('components');

    // 2025 : le stock du cabinet, plus le SEUL exercice que nous tenons.
    expect(cumulOf($this->depreciation, $property, 'Toiture', 2025))
        ->toBe(500_000 + $annual)
        // 2026 : deux exercices tenus.
        ->and(cumulOf($this->depreciation, $property, 'Toiture', 2026))
        ->toBe(500_000 + $annual * 2);
});

it('reproduces the reported figures: 4 736 € declared, not 9 496 €', function () {
    // ⚠️ Le cas réel, reconstitué. Les montants du dossier ne sont pas reproductibles ici
    // (nous n'avons ni sa ventilation ni ses durées) : ce qui est reproduit, c'est la
    // MÉCANIQUE — un cumul repris couvrant N-1, et un rejeu qui le doublait.
    $property = overlapProperty($this->user);
    $this->depreciation->generateDefaultComponents($property);

    foreach ($property->components as $component) {
        $component->forceFill([
            'opening_accumulated_depreciation' => 100_000,
            'opening_accumulated_year' => 2024,
        ])->save();
    }

    $property->unsetRelation('components');
    $lines = collect($this->depreciation->depreciationDetailForYear($property->fresh(), 2025))
        ->where('type', 'building');

    // Chaque ligne vaut son stock repris plus UNE annuité, jamais sept.
    foreach ($lines as $line) {
        expect((int) $line['cumul'])->toBe(100_000 + (int) $line['annual']);
    }
});

it('never lets the bound rewind the replay before the plan starts', function () {
    // Une année de couverture antérieure à l'origine du plan ne doit rien avancer :
    // `replayFrom()` est un plancher, pas une substitution.
    $property = overlapProperty($this->user);
    $this->depreciation->generateDefaultComponents($property);

    $roof = $property->components()->where('name', 'Toiture')->firstOrFail();
    $annual = (int) $roof->annual_depreciation;
    $roof->forceFill(['opening_accumulated_year' => 2010])->save();

    $property->unsetRelation('components');

    // 2019 à 2021 : trois exercices, comme sans borne.
    expect(cumulOf($this->depreciation, $property, 'Toiture', 2021))->toBe($annual * 3);
});

it('replays from the plan start when no bound is given', function () {
    // Non-régression : `null` doit valoir exactement le comportement d'avant, sinon la
    // migration changerait les chiffres de tous les dossiers existants.
    $property = overlapProperty($this->user);
    $this->depreciation->generateDefaultComponents($property);

    $roof = $property->components()->where('name', 'Toiture')->firstOrFail();
    $annual = (int) $roof->annual_depreciation;
    $roof->forceFill([
        'opening_accumulated_depreciation' => 500_000,
        'opening_accumulated_year' => null,
    ])->save();

    $property->unsetRelation('components');

    // 2019 à 2025 : sept exercices rejoués, plus le stock.
    expect(cumulOf($this->depreciation, $property, 'Toiture', 2025))->toBe(500_000 + $annual * 7);
});

// ─────────────────────────────────────────────────────────────────────
// Le terme du plan, que la borne ne doit PAS déplacer
// ─────────────────────────────────────────────────────────────────────

it('leaves the end of the plan anchored on its origin, not on the opening bound', function () {
    // ⚠️ Le piège qu'il fallait éviter. Retarder le rejeu via `depreciation_start_date`
    // aurait aussi retardé le terme : une toiture de 2019 sur 25 ans aurait couru jusqu'en
    // 2049 au lieu de 2043, et se serait amortie six ans de trop.
    $property = overlapProperty($this->user);
    $this->depreciation->generateDefaultComponents($property);

    $roof = $property->components()->where('name', 'Toiture')->firstOrFail();
    $duration = (int) $roof->duration_years;
    $roof->forceFill([
        'opening_accumulated_depreciation' => 500_000,
        'opening_accumulated_year' => 2024,
    ])->save();

    $lastYear = 2019 + $duration - 1;
    $property->unsetRelation('components');

    $stillRunning = collect($this->depreciation->depreciationDetailForYear($property->fresh(), $lastYear))
        ->firstWhere('name', 'Toiture');
    $overdue = collect($this->depreciation->depreciationDetailForYear($property->fresh(), $lastYear + 1))
        ->firstWhere('name', 'Toiture');

    expect((int) $stillRunning['annual'])->toBeGreaterThan(0)
        ->and($overdue['annual'])->toBe('0');
});

it('keeps depreciation_start_date anchoring the term, as it always did', function () {
    // Non-régression du comportement VOULU : une toiture refaite et mise en service en
    // 2022 court bien jusqu'en 2046, et non jusqu'en 2043.
    $property = overlapProperty($this->user);
    $this->depreciation->generateDefaultComponents($property);

    $roof = $property->components()->where('name', 'Toiture')->firstOrFail();
    $duration = (int) $roof->duration_years;
    $roof->forceFill(['depreciation_start_date' => '2022-01-01'])->save();

    $property->unsetRelation('components');
    $lastYear = 2022 + $duration - 1;

    $stillRunning = collect($this->depreciation->depreciationDetailForYear($property->fresh(), $lastYear))
        ->firstWhere('name', 'Toiture');

    expect((int) $stillRunning['annual'])->toBeGreaterThan(0);
});

// ─────────────────────────────────────────────────────────────────────
// Travaux et mobilier — ils n'avaient AUCUN moyen d'éviter le recouvrement
// ─────────────────────────────────────────────────────────────────────

it('bounds the replay of works and furniture too', function () {
    // `depreciation_start_date` n'existe que sur les composants : avant cette borne, un
    // travail ou un meuble portant un cumul repris le doublait, sans échappatoire.
    $property = overlapProperty($this->user);

    $work = PropertyWork::create([
        'property_id' => $property->id,
        'description' => 'Réfection reprise du cabinet',
        'amount' => 1_000_000,
        'work_date' => '2019-01-01',
        'duration_years' => 10,
        'is_dedicated' => true,
        'opening_accumulated_depreciation' => 600_000,
        'opening_accumulated_year' => 2024,
    ]);

    $item = Furniture::create([
        'property_id' => $property->id,
        'description' => 'Mobilier repris du cabinet',
        'amount' => 500_000,
        'purchase_date' => '2019-01-01',
        'duration_years' => 10,
        'is_dedicated' => true,
        'opening_accumulated_depreciation' => 300_000,
        'opening_accumulated_year' => 2024,
    ]);

    $lines = collect($this->depreciation->depreciationDetailForYear($property->fresh(), 2025))
        ->keyBy('type');

    expect((int) $lines['work']['cumul'])->toBe(600_000 + (int) $work->annual_depreciation)
        ->and((int) $lines['furniture']['cumul'])->toBe(300_000 + (int) $item->annual_depreciation);
});
