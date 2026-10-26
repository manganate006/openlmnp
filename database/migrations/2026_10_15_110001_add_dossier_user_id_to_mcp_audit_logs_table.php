<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Lot 5 des dossiers liés : trace, sur chaque appel d'un jeton rattaché à un
     * dossier, POUR QUEL dossier il a agi — en plus de `user_id`, qui reste le
     * PRINCIPAL (voir `App\Http\Middleware\McpGuard::audit()`).
     *
     * `nullOnDelete()` ici, à la différence de `personal_access_tokens` : un
     * journal d'audit n'a aucune conséquence de SÉCURITÉ à perdre cette
     * référence si le `User` du dossier disparaissait un jour — seul
     * l'historique perd un détail, la ligne elle-même (qui a appelé, quand,
     * quel outil) reste utile.
     */
    public function up(): void
    {
        Schema::table('mcp_audit_logs', function (Blueprint $table) {
            $table->foreignId('dossier_user_id')->nullable()->after('user_id')
                ->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('mcp_audit_logs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('dossier_user_id');
        });
    }
};
