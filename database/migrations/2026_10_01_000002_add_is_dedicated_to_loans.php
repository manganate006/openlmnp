<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Un emprunt qui finance EXCLUSIVEMENT le logement loué (ex : prêt travaux dédié au
 * studio indépendant) ne doit pas être proratisé par la quote-part de surface — au
 * contraire d'un emprunt qui finance l'achat de toute la propriété.
 *
 * Défaut `false` : comportement inchangé pour tous les emprunts existants, qui
 * continuent d'être proratisés comme avant cette colonne.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('loans', function (Blueprint $table) {
            $table->boolean('is_dedicated')->default(false)->after('insurance_rate');
        });
    }

    public function down(): void
    {
        Schema::table('loans', function (Blueprint $table) {
            $table->dropColumn('is_dedicated');
        });
    }
};
