<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La part de terrain accepte des décimales.
 *
 * Signalé le 2026-09-09 : « le pourcentage est assez peu précis, car votre cellule ne semble
 * pas prendre en compte les décimales ». La colonne était un `integer`, donc 17,5 % de
 * terrain — une valeur d'acte parfaitement ordinaire — n'était pas représentable. Il fallait
 * arrondir à 17 ou 18 %, et sur une valeur de bien à 175 000 € cela déplace 875 € de base
 * amortissable, soit largement plus que les écarts que l'écran de contrôle signale.
 *
 * `decimal(5,2)` : de 0,00 à 999,99, ce qui couvre les 0-50 % que le formulaire autorise
 * avec deux décimales. Même échelle que `property_components.percentage`, pour que les deux
 * pourcentages du même écran se comportent pareil.
 *
 * ⚠️ Aucune valeur ne change : un entier stocké devient le même nombre à deux décimales.
 * `getDepreciableBaseAttribute()` passe déjà par bcmath sur une CHAÎNE (`bcsub('100', …)`),
 * donc la précision supplémentaire est prise en compte sans modifier une ligne de calcul.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->decimal('land_percentage', 5, 2)->default(15)->change();
        });
    }

    public function down(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->integer('land_percentage')->default(15)->change();
        });
    }
};
