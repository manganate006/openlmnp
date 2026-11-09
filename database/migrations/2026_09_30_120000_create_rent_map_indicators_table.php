<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * « Carte des loyers » (ANIL / ministère de la Transition écologique, data.gouv.fr,
 * Licence Ouverte 2.0) : un repère de loyer d'annonce par commune et par type de bien.
 *
 * ⚠️ Donnée de RÉFÉRENCE publique, partagée par tous les utilisateurs — contrairement au
 * reste du schéma, cette table ne porte NI `user_id` NI scope. Voir `App\Models\RentMapIndicator`
 * et `App\Services\OpenData\RentMapSource` pour ce que mesure `per_m2_cents` (⚠️ loyer d'une
 * location NON MEUBLÉE, charges comprises — jamais une recette de meublé) et pour le piège
 * Paris / Lyon / Marseille (publiés par arrondissement, jamais par commune globale).
 *
 * Remplie par `openlmnp:refresh-rent-map` (planifiée en décembre, la source étant republiée
 * à cette période). Une instance qui ne l'a jamais lancée, ou sans accès réseau, a simplement
 * une table vide : le repère ne s'affiche pas, rien ne casse.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rent_map_indicators', function (Blueprint $table) {
            $table->id();

            $table->string('insee_code', 5);

            // 'apartment' (toutes typologies), 'apartment_1_2', 'apartment_3_plus', 'house' —
            // les 4 fichiers du jeu de données. Voir RentMapSource::TYPES.
            $table->string('property_type', 20);

            // Millésime du jeu de données (le jeu n'est pas comparable d'un millésime à
            // l'autre — la note méthodologique le dit explicitement).
            $table->unsignedSmallInteger('vintage');

            $table->unsignedInteger('per_m2_cents');
            $table->unsignedInteger('low_per_m2_cents');
            $table->unsignedInteger('high_per_m2_cents');

            // Nullable : présents dans le millésime 2025, mais pas garantis par la source
            // pour toujours — un import qui perdrait ces colonnes ne doit pas échouer.
            $table->unsignedInteger('observations')->nullable();
            $table->unsignedInteger('mesh_observations')->nullable();

            // 'commune', 'maille' ou 'epci' (dictionnaire de variables, `TYPPRED`).
            $table->string('prediction_level', 10)->nullable();

            $table->decimal('r2', 6, 5)->nullable();

            $table->timestamps();

            // Une ligne par commune ET par type de bien : `openlmnp:refresh-rent-map`
            // supprime puis réinsère les lignes d'un type à chaque import, ce qui rend la
            // commande idempotente sans jamais violer cette contrainte.
            $table->unique(['insee_code', 'property_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rent_map_indicators');
    }
};
