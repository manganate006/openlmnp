<?php

use App\Models\Property;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Remplace le booléen `is_primary_residence` par un champ explicite à trois valeurs.
 *
 * Le booléen ne distinguait pas deux situations fiscalement très différentes :
 *   - une ou plusieurs PIÈCES de la résidence principale (ex : une chambre louée sur
 *     Airbnb dans la maison qu'on habite) ;
 *   - un LOGEMENT INDÉPENDANT situé dans la résidence principale ou sur la même
 *     propriété (studio avec entrée propre, rez-de-chaussée aménagé, dépendance) —
 *     un meublé distinct de la résidence principale pour la loi : pas de limite de
 *     120 jours, pas d'art. 35 bis, CFE due en principe, THRS si usage familial.
 *
 * Additive et rétrocompatible : `is_primary_residence` est CONSERVÉE (colonne encore
 * lue par des intégrations tierces) et resynchronisée par le modèle à chaque
 * enregistrement, mais plus aucun calcul ne s'appuie dessus — voir
 * `DepreciationService`, où la quote-part s'applique désormais à l'identique quelle
 * que soit la situation (c'était déjà le cas pour les travaux et le mobilier).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->string('residence_situation', 32)
                ->default(Property::RESIDENCE_SEPARATE)
                ->after('is_primary_residence');
        });

        DB::table('properties')
            ->where('is_primary_residence', true)
            ->update(['residence_situation' => Property::RESIDENCE_PART_OF_RESIDENCE]);
    }

    public function down(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->dropColumn('residence_situation');
        });
    }
};
