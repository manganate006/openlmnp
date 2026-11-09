<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('feedback', function (Blueprint $table) {
            // Freins déclarés par un visiteur de la démonstration : « qu'est-ce qui vous
            // manque pour franchir le pas ». Tableau d'IDENTIFIANTS stables (`prix`,
            // `reprise`…), jamais de libellés — reformuler une case ne doit ni casser
            // l'historique ni fausser les comptages du rapport hebdomadaire.
            //
            // Comme le sentiment, chaque case est enregistrée DÈS LE CLIC : quelqu'un qui
            // coche puis referme sans rien envoyer a quand même livré son signal.
            $table->json('blockers')->nullable()->after('sentiment');
        });
    }

    public function down(): void
    {
        Schema::table('feedback', function (Blueprint $table) {
            $table->dropColumn('blockers');
        });
    }
};
