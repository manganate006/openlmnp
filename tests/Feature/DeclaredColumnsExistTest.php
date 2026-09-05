<?php

use App\Models\FiscalYear;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

/**
 * Un modèle ne doit pas déclarer assignable une colonne qui n'existe pas.
 *
 * Le défaut s'est produit DEUX FOIS sur `fiscal_years` : `transmitted_at`, corrigée le
 * 2026-09-04, puis `ack_number`, restée derrière. À chaque fois le symptôme était nul —
 * lue sur un modèle, la colonne absente rend simplement `null`.
 *
 * ⚠️ Ce qui rend la chose sournoise tient à SQLite : un identifiant entre guillemets
 * doubles qui ne correspond à aucune colonne est traité comme un LITTÉRAL DE CHAÎNE, par
 * compatibilité historique. Un `whereNotNull('ack_number')` ne lève donc pas
 * « no such column » — il compile en `WHERE 'ack_number' IS NOT NULL`, toujours vrai, et
 * rend TOUTES les lignes. Mesuré en production sur `transmitted_at` le 2026-09-04 :
 * 80 exercices rendus sur 80. Le premier filtre écrit là-dessus est faux, en silence.
 *
 * Ce garde-fou ne liste rien : il dérive de `getFillable()`, la source de vérité du
 * modèle. Ajouter un champ sans sa migration le fait échouer sans qu'on ait à y penser.
 */
it('ne déclare aucune colonne assignable qui manque en base', function () {
    $manquantes = [];
    $audites = 0;

    foreach (glob(app_path('Models') . '/*.php') as $file) {
        $class = 'App\\Models\\' . basename($file, '.php');

        if (! class_exists($class)) {
            continue;
        }

        $reflection = new ReflectionClass($class);

        if ($reflection->isAbstract() || ! $reflection->isSubclassOf(Model::class)) {
            continue;
        }

        $model = $reflection->newInstance();
        $table = $model->getTable();

        if (! Schema::hasTable($table)) {
            continue;
        }

        $audites++;

        foreach ($model->getFillable() as $column) {
            if (! Schema::hasColumn($table, $column)) {
                $manquantes[] = "{$table}.{$column} (déclarée par {$class})";
            }
        }
    }

    expect($audites)->toBeGreaterThan(10, 'l\'audit doit réellement parcourir les modèles');
    expect($manquantes)->toBe([], "colonnes déclarées sans exister :\n  " . implode("\n  ", $manquantes));
});

/**
 * Le cas particulier qui a motivé le garde-fou, vérifié par le chemin dangereux.
 *
 * Le test générique ci-dessus regarde le schéma ; celui-ci exécute réellement la requête
 * qui serait fausse. Les deux sont nécessaires : le premier dit ce qui manque, le second
 * prouve que le filtre se comporte bien.
 */
it('expose ack_number comme une vraie colonne, filtrable', function () {
    expect(Schema::hasColumn('fiscal_years', 'ack_number'))
        ->toBeTrue('la colonne que le modèle déclare doit exister en base');

    $user = User::factory()->create();

    foreach ([2023, 2024, 2025] as $year) {
        FiscalYear::create([
            'user_id' => $user->id,
            'year'    => $year,
            'status'  => FiscalYear::STATUS_DRAFT,
        ]);
    }

    // Sans colonne, SQLite rendait ici 3 au lieu de 0, sans lever d'erreur.
    expect(FiscalYear::whereNotNull('ack_number')->count())
        ->toBe(0, 'un filtre sur une colonne vide ne doit rendre aucune ligne');

    FiscalYear::where('year', 2024)->update(['ack_number' => 'EDI-2024-000123']);

    expect(FiscalYear::whereNotNull('ack_number')->count())->toBe(1);
    expect(FiscalYear::where('year', 2024)->value('ack_number'))->toBe('EDI-2024-000123');
});

/**
 * Ce que le MCP annonce doit pouvoir porter une valeur.
 *
 * `list_fiscal_years` et `get_fiscal_year` renvoient `ack_number` depuis toujours : sans
 * colonne, ils annonçaient un champ qui ne pouvait JAMAIS être renseigné.
 */
it('rend le numéro d\'accusé lisible par le modèle une fois renseigné', function () {
    $user = User::factory()->create();

    $fy = FiscalYear::create([
        'user_id'        => $user->id,
        'year'           => 2024,
        'status'         => FiscalYear::STATUS_DRAFT,
        'transmitted_at' => '2025-05-12 09:30:00',
        'ack_number'     => 'EDI-2025-987654',
    ]);

    // Les deux colonnes vont par paire : elles seront écrites ensemble le jour où un écran
    // enregistrera le dépôt effectif d'une liasse.
    expect($fy->fresh()->ack_number)->toBe('EDI-2025-987654');
    expect($fy->fresh()->transmitted_at?->format('Y-m-d'))->toBe('2025-05-12');
});
