<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Défaut gênant n°6 du test de bout en bout (2026-10-15) : une invitation REFUSÉE
 * par l'invité (App\Http\Controllers\Dossier\AcceptDossierInvitationController::
 * decline()) était écrite avec `revoked_at` — la MÊME colonne qu'une révocation PAR
 * LE PROPRIÉTAIRE du dossier — et s'affichait donc « Révoquée » dans la liste, aux
 * yeux de qui a invité. Les deux sont des décisions différentes, de deux personnes
 * différentes : `declined_at` les distingue.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('linked_dossier_invitations', function (Blueprint $table) {
            $table->timestamp('declined_at')->nullable()->after('revoked_at');
        });
    }

    public function down(): void
    {
        Schema::table('linked_dossier_invitations', function (Blueprint $table) {
            $table->dropColumn('declined_at');
        });
    }
};
