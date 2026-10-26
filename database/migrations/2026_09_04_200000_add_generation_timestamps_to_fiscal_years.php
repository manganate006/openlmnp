<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Remplace `pdf_path` / `fec_path` par des DATES DE GÉNÉRATION.
 *
 * La liasse fiscale et le FEC étaient écrits en clair dans `storage/app/private/{tax-returns,fec}`
 * et le chemin conservé en base. Ces deux fichiers portent l'intégralité de la comptabilité
 * (adresses, SIREN, résultats, écritures) et n'étaient chiffrés par rien.
 *
 * Ils ne sont plus persistés : ils sont recalculés à chaque téléchargement depuis les données
 * comptables. Mais leur PRÉSENCE servait de signal un peu partout (checklist d'accueil, badges,
 * statistiques, signaux de cycle de vie, encart de correction du 2033-D) — c'est ce signal-là,
 * et lui seul, que ces deux colonnes conservent.
 *
 * ⚠️ Migration ADDITIVE, volontairement : `pdf_path` et `fec_path` sont conservées (plus
 * écrites, plus lues) pour qu'une bascule arrière de l'image Docker retombe sur ses pieds.
 * `openlmnp:purge-legacy-files --fix` les vide en même temps qu'il supprime les fichiers.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fiscal_years', function (Blueprint $table) {
            $table->timestamp('tax_return_generated_at')->nullable();
            $table->timestamp('fec_generated_at')->nullable();
        });

        // Report du signal existant. `updated_at` est le seul horodatage disponible : il ne
        // date pas la génération, seulement la dernière écriture sur la ligne. On ne s'en sert
        // que pour rendre la colonne NON NULLE — c'est le booléen qui porte le sens, jamais
        // l'instant. Un exercice dont la liasse avait été générée continue donc de compter
        // comme tel dans les badges et la checklist.
        DB::table('fiscal_years')
            ->whereNotNull('pdf_path')
            ->update(['tax_return_generated_at' => DB::raw('updated_at')]);

        DB::table('fiscal_years')
            ->whereNotNull('fec_path')
            ->update(['fec_generated_at' => DB::raw('updated_at')]);
    }

    public function down(): void
    {
        Schema::table('fiscal_years', function (Blueprint $table) {
            $table->dropColumn(['tax_return_generated_at', 'fec_generated_at']);
        });
    }
};
