<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Lot 5 des dossiers liés (jeton MCP rattaché à un dossier) — voir
     * `_admin/docs/plan-dossiers-lies-2026-10-14.md` § 3, option C retenue.
     *
     * Le jeton reste porté par le PRINCIPAL (`tokenable_type`/`tokenable_id`,
     * colonnes Sanctum natives, inchangées) : cette colonne ne fait que désigner,
     * en plus, le `User` du dossier pour lequel ce jeton agit. NULLE = jeton
     * personnel, comme avant ce lot.
     *
     * ⚠️ `cascadeOnDelete()`, PAS `nullOnDelete()` : un `nullOnDelete()` ferait,
     * si le `User` du dossier désigné disparaissait un jour, RETOMBER ce jeton en
     * jeton personnel du principal — exactement le repli silencieux que
     * `App\Http\Middleware\McpGuard` s'interdit (un jeton « dossier » ne doit
     * JAMAIS se mettre à écrire dans le dossier personnel). Supprimer le jeton
     * avec le dossier qu'il visait est le seul choix sûr.
     */
    public function up(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->foreignId('dossier_user_id')->nullable()->after('tokenable_id')
                ->constrained('users')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->dropConstrainedForeignId('dossier_user_id');
        });
    }
};
