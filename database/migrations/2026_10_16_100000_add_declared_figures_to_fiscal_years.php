<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Deux montants de la liasse reprise, conservés pour le contrôle de reprise.
 *
 * Jusqu'ici la case 044 (total des immobilisations brutes) et le résultat fiscal déclaré
 * (2033-B, cases 352 / 354) ne vivaient que dans l'assistant : « Revoir ma reprise », après
 * un rechargement de page, les affichait « non renseigné » (constaté le 2026-10-15).
 *
 * ⚠️ CONTRÔLE UNIQUEMENT, comme `opening_accumulated_depreciation` : aucun calcul ne les lit.
 * `null` = case non renseignée, jamais zéro.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fiscal_years', function (Blueprint $table) {
            // 2033-A case 044, en centimes.
            $table->bigInteger('opening_declared_gross_assets')->nullable()->after('opening_source');

            // 2033-B : bénéfice (352) positif, déficit (354) négatif, en centimes.
            $table->bigInteger('opening_declared_result')->nullable()->after('opening_declared_gross_assets');
        });
    }

    public function down(): void
    {
        Schema::table('fiscal_years', function (Blueprint $table) {
            $table->dropColumn(['opening_declared_gross_assets', 'opening_declared_result']);
        });
    }
};
