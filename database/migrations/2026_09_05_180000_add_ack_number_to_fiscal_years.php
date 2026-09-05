<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Crée la seconde colonne que le modèle déclarait sans qu'elle existe.
 *
 * La migration du 2026-09-04 a créé `transmitted_at` et laissé `ack_number` derrière elle :
 * le champ est dans le `$fillable` de `FiscalYear` et **deux outils MCP le renvoient**
 * (`list_fiscal_years`, `get_fiscal_year`), sans qu'aucune colonne ne le porte. Lu sur un
 * modèle, il rend `null` — inoffensif, et donc invisible.
 *
 * ⚠️ Ce qui l'était moins, et c'est la raison d'être de cette migration : SQLite traite un
 * identifiant entre guillemets doubles qui ne correspond à aucune colonne comme un LITTÉRAL
 * DE CHAÎNE, par compatibilité historique. Un `whereNotNull('ack_number')` n'aurait donc pas
 * levé « no such column » — il aurait rendu `WHERE 'ack_number' IS NOT NULL`, toujours vrai,
 * soit TOUS les exercices au lieu d'aucun. Le premier code à filtrer là-dessus aurait été
 * faux sans qu'aucune erreur ne le signale. C'est exactement ce qui s'était produit sur
 * `transmitted_at`, mesuré en production le 2026-09-04 : 80 lignes rendues sur 80.
 *
 * La colonne porte le numéro d'accusé de réception rendu par la télétransmission (EDI-TDFC).
 * ⚠️ **Rien ne l'écrit aujourd'hui** — pas plus que `transmitted_at` : aucun écran ne saisit
 * encore le dépôt effectif d'une liasse. Les deux colonnes vont par paire et seront
 * renseignées ensemble le jour où cet écran existera. Créer celle-ci maintenant est le choix
 * additif : elle rend vrai ce que le MCP annonce déjà, et retire la mine sous le prochain
 * filtre SQL. Elle reste nulle sur tous les exercices existants — reconstituer après coup un
 * numéro d'accusé serait l'inventer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fiscal_years', function (Blueprint $table) {
            $table->string('ack_number')->nullable()->after('transmitted_at');
        });
    }

    public function down(): void
    {
        Schema::table('fiscal_years', function (Blueprint $table) {
            $table->dropColumn('ack_number');
        });
    }
};
