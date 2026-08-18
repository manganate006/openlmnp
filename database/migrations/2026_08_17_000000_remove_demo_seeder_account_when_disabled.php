<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * DatabaseSeeder appelait DemoSeeder sur toute installation, même hors
     * mode démo, créant demo@openlmnp.fr avec le mot de passe publié dans le
     * README (démo officielle). Sur une installation self-hébergée avec
     * DEMO_MODE=false, ce compte connu est un risque de sécurité, pas
     * seulement des données fictives invisibles. La suppression de
     * l'utilisateur cascade (FK) vers ses biens et toutes leurs données.
     */
    public function up(): void
    {
        if (config('demo.enabled')) {
            return;
        }

        User::where('email', config('demo.email', 'demo@openlmnp.fr'))->delete();
    }

    public function down(): void
    {
        // Suppression de données, non réversible.
    }
};
