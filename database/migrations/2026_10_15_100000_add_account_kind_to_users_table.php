<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Voir App\Enums\AccountKind. 'standard' par défaut : tous les comptes
            // existants (et tout compte créé par la voie ordinaire — inscription,
            // provisioning, promotion de démo) restent inchangés. Seul
            // App\Services\LinkedDossierInvitationService::acceptAsNewMember() pose
            // 'member', par forceFill — comme is_linked_dossier, VOLONTAIREMENT
            // absent du #[Fillable] de User pour qu'un update() de formulaire ne
            // puisse jamais l'écrire.
            $table->string('account_kind', 8)->default('standard')->after('linked_dossier_quota');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('account_kind');
        });
    }
};
