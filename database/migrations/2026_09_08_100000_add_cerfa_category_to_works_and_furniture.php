<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La ligne du 2033-C d'un travail ou d'un meuble devient une donnée, plus une constante.
 *
 * Jusqu'ici elle était codée en dur dans `DepreciationService` : travaux → agencements
 * (450/540), mobilier → autres (470/560). Un cabinet qui classait des travaux de gros œuvre
 * en constructions (430/520) ne pouvait donc pas être reproduit, et le contrôle de reprise
 * signalait un écart que rien ne permettait de corriger. Les composants d'immeuble portaient
 * déjà cette colonne depuis le 2026-09-04.
 *
 * ⚠️ Nullable, et `null` vaut l'ancien comportement : aucune ligne existante ne change de
 * place, il n'y a donc rien à réparer après la migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('property_works', function (Blueprint $table) {
            $table->string('cerfa_category', 16)->nullable()->after('duration_years');
        });

        Schema::table('furniture', function (Blueprint $table) {
            $table->string('cerfa_category', 16)->nullable()->after('duration_years');
        });
    }

    public function down(): void
    {
        Schema::table('property_works', function (Blueprint $table) {
            $table->dropColumn('cerfa_category');
        });

        Schema::table('furniture', function (Blueprint $table) {
            $table->dropColumn('cerfa_category');
        });
    }
};
