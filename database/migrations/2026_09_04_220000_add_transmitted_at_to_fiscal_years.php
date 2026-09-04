<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Crée la colonne que le modèle déclarait depuis toujours sans qu'elle existe.
 *
 * `FiscalYear` porte `transmitted_at` dans son `$fillable` et ses `casts`, et deux outils MCP
 * la lisent — mais aucune migration ne l'avait jamais créée. Lue sur un modèle, elle rendait
 * simplement `null` : inoffensif, et donc invisible.
 *
 * ⚠️ Ce qui l'était moins : SQLite traite un identifiant entre guillemets doubles qui ne
 * correspond à aucune colonne comme un LITTÉRAL DE CHAÎNE, par compatibilité historique. Un
 * `whereNotNull('transmitted_at')` ne levait donc pas « no such column » — il rendait
 * `WHERE 'transmitted_at' IS NOT NULL`, toujours vrai, soit TOUS les exercices au lieu
 * d'aucun. Mesuré sur la production le 2026-09-04 : 80 lignes rendues sur 80. Le premier code
 * qui aurait filtré là-dessus aurait été faux sans qu'aucune erreur ne le signale.
 *
 * La colonne porte la date de télédéclaration effective d'un exercice. Elle reste nulle pour
 * tous les exercices existants : rien ne permet de reconstituer après coup ce qui a été
 * réellement déposé, et l'inventer serait pire que de l'ignorer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fiscal_years', function (Blueprint $table) {
            $table->timestamp('transmitted_at')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('fiscal_years', function (Blueprint $table) {
            $table->dropColumn('transmitted_at');
        });
    }
};
