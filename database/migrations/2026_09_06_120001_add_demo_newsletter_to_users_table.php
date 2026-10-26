<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Le visiteur a coché « recevoir aussi les rappels fiscaux » en prolongeant son
            // bac à sable.
            //
            // ⚠️ Ce n'est qu'une INTENTION, pas un consentement : le consentement reste le
            // double opt-in de la vitrine (lien signé à cliquer, `optin_confirmed_at` comme
            // preuve). Cette colonne sert à savoir qu'on a transmis la demande, pas à
            // autoriser un envoi.
            $table->timestamp('demo_newsletter_at')->nullable()->after('demo_email_consent_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('demo_newsletter_at');
        });
    }
};
