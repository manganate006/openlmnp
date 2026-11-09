<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Diagnostic de performance énergétique (DPE) du bien.
 *
 * Quatre colonnes reprises telles quelles de la base ADEME (`dpe03existant`) :
 *   - `dpe_number`         : `numero_dpe`, l'identifiant de l'attestation ;
 *   - `dpe_class`          : `etiquette_dpe`, A à G ;
 *   - `dpe_established_at` : `date_etablissement_dpe` ;
 *   - `dpe_valid_until`    : `date_fin_validite_dpe`, sert à l'alerte d'expiration.
 *
 * Remplies soit par l'action « Retrouver le DPE » (recherche ADEME sur l'adresse du bien
 * ou sur ce numéro), soit à la main : la saisie manuelle reste toujours possible, aucun
 * champ n'est verrouillé par la recherche. Nullable et sans reprise de l'existant, comme
 * `insee_code` avant elles.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->string('dpe_number', 20)->nullable()->after('insee_code');
            $table->string('dpe_class', 1)->nullable()->after('dpe_number');
            $table->date('dpe_established_at')->nullable()->after('dpe_class');
            $table->date('dpe_valid_until')->nullable()->after('dpe_established_at');
        });
    }

    public function down(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->dropColumn(['dpe_number', 'dpe_class', 'dpe_established_at', 'dpe_valid_until']);
        });
    }
};
