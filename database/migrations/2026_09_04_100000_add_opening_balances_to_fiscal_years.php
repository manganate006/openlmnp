<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Soldes d'ouverture d'un exercice de reprise.
 *
 * Jusqu'ici, le report d'amortissements différés se lisait UNIQUEMENT dans l'exercice N-1
 * présent en base. Un bailleur qui quitte son cabinet n'a aucun de ces exercices : son
 * report était donc perdu, et il n'avait d'autre choix que de ressaisir tout l'historique.
 *
 * Ces quatre colonnes portent ce que la liasse N-1 du cabinet indique, et rien d'autre :
 * elles ne remplacent jamais un exercice réellement calculé, elles ne servent que lorsque
 * cet exercice n'existe pas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fiscal_years', function (Blueprint $table) {
            // ARD repris de la liasse N-1 (2033-D case 870 ou 2033-B case 318), en centimes.
            $table->integer('opening_deferred_depreciation')->default(0)->after('previous_deferred');

            // Déficits reportables par millésime : [{"origin_year": 2022, "amount": 120000}, ...]
            $table->json('opening_deficits')->nullable()->after('opening_deferred_depreciation');

            // Cumul d'amortissements déclaré (2033-A case 030), en centimes.
            // ⚠️ CONTRÔLE UNIQUEMENT : jamais une entrée de calcul, sinon on double compte.
            $table->integer('opening_accumulated_depreciation')->default(0)->after('opening_deficits');

            // Provenance : liasse / manuel / ia.
            $table->string('opening_source', 16)->nullable()->after('opening_accumulated_depreciation');
        });
    }

    public function down(): void
    {
        Schema::table('fiscal_years', function (Blueprint $table) {
            $table->dropColumn([
                'opening_deferred_depreciation',
                'opening_deficits',
                'opening_accumulated_depreciation',
                'opening_source',
            ]);
        });
    }
};
