<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Option d'étalement des frais d'emprunt (art. 39-1-1° quater CGI, BOI-BIC-CHG-20-30-40
 * § 30-50) : globale au DOSSIER, pas à l'emprunt — une seule option pour tous les
 * emprunts de l'utilisateur, irrévocable pendant 2 ans, exercée par la première
 * inscription en charges à répartir. `null` = déduction immédiate (comportement
 * historique, inchangé pour tous les comptes existants). Une valeur = l'année à partir
 * de laquelle les frais payés sont étalés (`LoanService::feesForYear()`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedSmallInteger('loan_fees_spread_from')->nullable()->after('is_indivision');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('loan_fees_spread_from');
        });
    }
};
