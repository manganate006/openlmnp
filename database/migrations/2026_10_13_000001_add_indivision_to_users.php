<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Le dossier est celui d'une indivision : une seule liasse, au nom de l'indivision,
            // dont le résultat est réparti entre indivisaires sur la 2031 bis-SD (cadre E).
            // Un compte = un dossier : les biens personnels de chaque indivisaire relèvent
            // d'un autre compte.
            $table->boolean('is_indivision')->default(false)->after('siren');

            // Indivisaires : [{name, address, birth, tax_id, share_bp}], `share_bp` en points
            // de base (10 000 = 100 %) pour que la répartition reste en entiers (bcmath).
            $table->json('co_owners')->nullable()->after('is_indivision');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['is_indivision', 'co_owners']);
        });
    }
};
