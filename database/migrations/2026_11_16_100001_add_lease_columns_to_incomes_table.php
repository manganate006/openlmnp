<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ce qu'une recette garde de son bail (issue #24).
 *
 * - `lease_id` marque un loyer généré depuis un bail. La source reste `direct` : elle
 *   décrit le canal, et un bail sans plateforme, c'est du direct. Si le bail est
 *   supprimé, ses loyers restent (ce sont des recettes réelles) et perdent seulement
 *   ce lien, d'où `nullOnDelete()`.
 * - `charges_amount` est la part des charges DÉJÀ COMPRISE dans `amount`. Elle ne sert
 *   qu'à l'affichage : en BIC, ce que verse le locataire est une recette charges
 *   comprises, au forfait comme en provisions, et `FiscalYearService` continue de ne
 *   lire que `amount`.
 * - `paid_date` est la date d'encaissement. Informative : aucun calcul ne la lit. La
 *   recette se rattache à l'exercice par `income_date`, la date d'échéance, comme un
 *   loyer échu est acquis à l'exercice même s'il n'est pas encore payé.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('incomes', function (Blueprint $table) {
            $table->foreignId('lease_id')->nullable()->after('property_id')
                ->constrained()->nullOnDelete();
            $table->integer('charges_amount')->default(0)->after('amount');
            $table->date('paid_date')->nullable()->after('income_date');
        });
    }

    public function down(): void
    {
        Schema::table('incomes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('lease_id');
            $table->dropColumn(['charges_amount', 'paid_date']);
        });
    }
};
