<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Retrait de la question « Chaque indivisaire participe-t-il à la gestion ? » : la
     * distinction micro-BIC exclu (société de fait, piège P13)/micro-BIC avec mise en garde
     * s'est avérée trop incertaine pour être modélisée correctement (voir `App\Support\Indivision`
     * et le CHANGELOG) — le micro-BIC d'une indivision suit désormais les mêmes règles que celui
     * d'un dossier individuel, partout dans l'application.
     *
     * La migration qui ajoutait la colonne (`2026_10_14_000010_add_indivision_management_to_users`)
     * n'est pas réécrite : une migration déjà posée ne se corrige que par une nouvelle migration.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('indivision_management');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('indivision_management', 8)->nullable()->after('co_owners');
        });
    }
};
