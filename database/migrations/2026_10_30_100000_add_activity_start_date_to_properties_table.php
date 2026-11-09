<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Début de l'activité de location meublée, distinct du début du régime réel.
 *
 * `rental_start_date` portait jusqu'ici les deux sens à la fois. Or un loueur qui passe du
 * micro-BIC au réel après quelques années a deux dates : la première mise en location
 * (début de l'activité, mise en service des actifs) et l'ouverture du premier exercice au
 * réel. Les annuités des années au micro-BIC sont « réputées pratiquées » : elles entrent
 * dans le cumul des amortissements, jamais dans une dotation déductible
 * (BOI-BIC-AMT-10-30-30-10 § 470, appliqué par analogie).
 *
 * ⚠️ NULLABLE et sans rétro-remplissage : vide, elle vaut `rental_start_date`
 * (`Property::activityStartDate()`), et un dossier existant garde exactement son plan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->date('activity_start_date')->nullable()->after('rental_start_date');
        });
    }

    public function down(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->dropColumn('activity_start_date');
        });
    }
};
