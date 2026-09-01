<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Code INSEE de la commune du bien.
 *
 * DVF est indexé par code INSEE, jamais par code postal — un code postal peut couvrir
 * plusieurs communes, et Paris, Lyon et Marseille sont découpés en arrondissements. On
 * mémorise donc la commune retenue par l'utilisateur au lieu de la redemander à chaque
 * estimation : sans ça, un bien dont le code postal est ambigu reposerait la question
 * indéfiniment.
 *
 * Nullable et sans reprise de l'existant : la valeur se remplit à la première estimation.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->string('insee_code', 5)->nullable()->after('postal_code');
        });
    }

    public function down(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->dropColumn('insee_code');
        });
    }
};
