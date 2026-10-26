<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Carte « Nouveauté » de la page Quoi de neuf (maquette écran 8, variante B) : la version pour
 * laquelle l'utilisateur a déjà fermé la carte, pas un simple booléen — sans quoi la carte de la
 * PROCHAINE version resterait masquée pour toujours. Même patron que `onboarding_dismissed_at`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('whats_new_dismissed_version')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('whats_new_dismissed_version');
        });
    }
};
