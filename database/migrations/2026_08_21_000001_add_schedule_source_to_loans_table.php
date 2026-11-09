<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Origine du tableau d'amortissement d'un emprunt.
 *
 * `computed` (défaut) : lignes calculées par LoanService, régénérables à volonté.
 * `imported` : échéances RÉELLES lues sur le tableau de la banque (différé,
 * assurance dégressive, modulation) — LoanService ne les écrase plus sans
 * confirmation explicite, sous peine de faire disparaître des intérêts
 * déductibles déjà déclarés.
 *
 * Migration purement additive : aucune table recréée, le volume de production
 * (/opt/openlmnp-data) n'est pas touché.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('loans', function (Blueprint $table) {
            $table->string('schedule_source')->default('computed')->after('insurance_rate');
        });
    }

    public function down(): void
    {
        Schema::table('loans', function (Blueprint $table) {
            $table->dropColumn('schedule_source');
        });
    }
};
