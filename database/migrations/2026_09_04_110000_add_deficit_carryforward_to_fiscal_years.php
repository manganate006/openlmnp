<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Le déficit reportable devient une notion à part entière, distincte de l'amortissement différé.
 *
 * Les deux stocks se confondaient : les cases 982/983/984 du 2033-D, qui suivent les DÉFICITS,
 * étaient alimentées par `previous_deferred`, c'est-à-dire par l'amortissement réputé différé.
 * Toute liasse d'un bailleur ayant de l'amortissement différé déclarait donc des déficits qu'il
 * n'avait pas.
 *
 * Ce sont bien deux stocks distincts, avec des régimes de péremption différents :
 *   - amortissement différé (CGI art. 39 C, II-3) : reporté SANS limite de durée ;
 *   - déficit LMNP (CGI art. 156, I-1° ter) : imputable sur les seuls bénéfices de même nature
 *     des DIX années suivantes, le millésime le plus ancien d'abord.
 * L'administration en demande d'ailleurs deux états de suivi séparés (BOI-FORM-000038 pour les
 * amortissements, BOI-FORM-000039 pour les déficits).
 *
 * ⚠️ Colonnes DÉRIVÉES, figées en base : `openlmnp:repair-deficits` les recalcule sur les
 * dossiers déjà tenus.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fiscal_years', function (Blueprint $table) {
            // Stock de déficits antérieurs à l'ouverture de l'exercice (2033-D case 982), centimes.
            $table->integer('previous_deficit')->default(0)->after('opening_source');

            // Déficits imputés sur le bénéfice de l'exercice (case 983), centimes.
            $table->integer('deficit_imputed')->default(0)->after('previous_deficit');

            // Déficits restant à reporter à la clôture (case 984), centimes.
            $table->integer('deficit_carryforward')->default(0)->after('deficit_imputed');

            // Détail par millésime à la clôture :
            // [{"origin_year": 2022, "opening": 120000, "imputed": 50000, "expired": 0, "remaining": 70000}]
            $table->json('deficit_detail')->nullable()->after('deficit_carryforward');
        });
    }

    public function down(): void
    {
        Schema::table('fiscal_years', function (Blueprint $table) {
            $table->dropColumn([
                'previous_deficit',
                'deficit_imputed',
                'deficit_carryforward',
                'deficit_detail',
            ]);
        });
    }
};
