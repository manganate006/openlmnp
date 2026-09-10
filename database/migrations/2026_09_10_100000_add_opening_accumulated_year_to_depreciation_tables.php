<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Jusqu'à quel exercice le cumul repris d'une comptabilité antérieure court.
 *
 * Sans cette borne, `DepreciationService::withOpening()` ajoute le cumul repris à un rejeu
 * qui recouvre les MÊMES exercices : le stock du cabinet et notre reconstitution se comptent
 * deux fois. Un utilisateur l'a signalé le 2026-09-09 avec les chiffres qui le prouvent —
 * sa case 030 valait 9 496 € pour 4 736 € déclarés, soit exactement 4 736 + 4 760 de rejeu.
 *
 * ⚠️ Ce n'est PAS `depreciation_start_date`, et il ne fallait pas s'en servir pour ça.
 * Cette date-là est l'ORIGINE DU PLAN — une toiture refaite en 2022 sur 25 ans court
 * jusqu'en 2046 — et elle ancre donc aussi le terme et le prorata de première année.
 * La détourner pour retarder le rejeu aurait repoussé la fin des plans d'autant, et fait
 * amortir les actifs au-delà de leur durée.
 *
 * Sémantique : « le cumul repris couvre les exercices jusqu'à cette année INCLUSE ».
 * Le rejeu démarre donc l'année suivante. `null` = pas de reprise, rejeu depuis l'origine :
 * aucune ligne existante ne change de valeur à la migration.
 */
return new class extends Migration
{
    private const TABLES = ['property_components', 'property_works', 'furniture'];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->unsignedSmallInteger('opening_accumulated_year')
                    ->nullable()
                    ->after('opening_accumulated_depreciation');
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->dropColumn('opening_accumulated_year');
            });
        }
    }
};
