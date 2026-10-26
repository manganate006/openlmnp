<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Un dossier lié EST un User à part entière (jamais de connexion directe —
            // voir App\Support\DossierContext) : ce booléen est ce qui l'exclut partout
            // où l'instance compte ou authentifie des comptes « réels » (statistiques,
            // diagnostic, MCP local, canAccessPanel).
            $table->boolean('is_linked_dossier')->default(false)->index()->after('is_indivision');

            // Quota de dossiers liés OFFERTS que ce compte peut créer en tant que
            // principal. NULL = pas de valeur propre, la résolution retombe sur
            // App\Support\DossierQuota (illimité en auto-hébergement, 0 sur le cloud
            // tant que la vitrine — lot 4 — ou `openlmnp:dossier-quota` ne l'ont pas posé).
            $table->unsignedSmallInteger('linked_dossier_quota')->nullable()->after('is_linked_dossier');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['is_linked_dossier', 'linked_dossier_quota']);
        });
    }
};
