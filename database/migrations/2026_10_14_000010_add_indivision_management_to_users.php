<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Gestion de l'indivision : `joint` (chaque indivisaire dirige et contrôle — société de
            // fait, micro-BIC exclu), `sole` (un seul gère), null (non renseigné). Voir
            // App\Support\Indivision et le piège fiscal P13 (CE, 14/11/2025, n° 495516).
            $table->string('indivision_management', 8)->nullable()->after('co_owners');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('indivision_management');
        });
    }
};
