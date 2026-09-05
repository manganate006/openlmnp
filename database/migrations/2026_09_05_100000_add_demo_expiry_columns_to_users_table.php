<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Colonnes de l'expiration du bac à sable de démonstration.
 *
 * Additive et sans valeur par défaut recalculée : un déploiement peut être rebasculé
 * en arrière sans perdre de donnée (la bascule automatique de `deploy-app.sh` ne défait
 * pas les migrations, elle n'est sûre que tant qu'elles sont additives).
 *
 * ⚠️ Toute colonne ajoutée ici doit AUSSI entrer dans l'attribut #[Fillable] de
 * App\Models\User : en Laravel 13 c'est un attribut PHP, et une colonne oubliée est
 * ignorée EN SILENCE par update() alors que les fabriques, elles, l'écrivent — le
 * symptôme est donc trompeur (création correcte, mise à jour muette).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Paliers de relance déjà servis, en heures restantes. En base et non dans le
            // navigateur : un vidage du stockage local ne doit pas rejouer les relances.
            $table->json('demo_reminders_seen')->nullable();

            // Ce que le seed a créé, mémorisé à la création : {property_id, fiscal_year_ids}.
            // Sans ce marqueur, distinguer le bien d'exemple de celui saisi par le visiteur
            // relèverait de la devinette au moment de la conversion.
            $table->json('demo_seed')->nullable();

            // Adresse laissée en échange de la prolongation, et l'horodatage du consentement.
            // Horodatage plutôt que booléen : c'est la date qui fait foi en cas de contestation.
            $table->string('demo_email')->nullable();
            $table->timestamp('demo_email_consent_at')->nullable();
            $table->timestamp('demo_extended_at')->nullable();

            // Jeton de reprise, porté jusqu'aux metadata Stripe puis relu par le webhook.
            // Unique et indexé : c'est un critère de recherche sur le chemin du paiement.
            $table->string('demo_claim_token', 64)->nullable()->unique();

            // Promotion en place : le compte démo EST devenu le compte payant.
            $table->timestamp('demo_promoted_at')->nullable();

            // Sort réservé aux données d'exemple, choisi une seule fois après promotion.
            $table->string('demo_seed_choice', 20)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // L'index unique de demo_claim_token tombe avec la colonne sous SQLite comme
            // sous PostgreSQL ; pas de dropUnique() explicite, qui échouerait sur SQLite.
            $table->dropColumn([
                'demo_reminders_seen',
                'demo_seed',
                'demo_email',
                'demo_email_consent_at',
                'demo_extended_at',
                'demo_claim_token',
                'demo_promoted_at',
                'demo_seed_choice',
            ]);
        });
    }
};
