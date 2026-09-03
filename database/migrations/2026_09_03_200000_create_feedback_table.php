<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('feedback', function (Blueprint $table) {
            $table->id();

            // ⚠️ Une ligne est créée dès que l'invitation est AFFICHÉE, pas au clic.
            // C'est ce qui donne le dénominateur : sans les impressions, « 4 réponses »
            // ne veut rien dire — ni pour juger la fonctionnalité, ni pour départager
            // les trois variantes du test.
            //
            // Donc `sentiment` est nullable : null = affichée sans réponse.
            $table->string('sentiment', 16)->nullable();

            // « a » (modale centrée) | « b » (bandeau bas) | « c » (carte flottante).
            // Tirée au sort à l'affichage puis figée par cookie, pour qu'une même
            // personne ne voie jamais deux mises en forme différentes.
            $table->string('variant', 4);

            // Fermeture explicite (croix ou « Plus tard »), à distinguer d'une invitation
            // simplement ignorée : ce n'est pas le même signal.
            $table->timestamp('dismissed_at')->nullable();

            $table->text('message')->nullable();
            $table->string('author_name')->nullable();
            $table->string('author_email')->nullable();

            // Consentement explicite à la publication (art. L111-7-2 du Code de la
            // consommation). Sans lui, le message est un retour privé, pas un
            // témoignage : il ne doit jamais se retrouver sur le site.
            $table->boolean('can_publish')->default(false);

            // « demo » | « user » — d'où vient le retour. Un avis émis depuis la
            // démonstration porte sur des données fictives, pas sur la comptabilité
            // réelle de son auteur : il n'est pas publiable comme retour d'usage.
            $table->string('audience', 16);

            // « session » (première invitation) | « return » (visiteur revenu).
            $table->string('trigger', 16);

            // Contexte technique : page, durée de session, actions comptées, version.
            // Jamais de donnée fiscale ni de montant.
            $table->json('context')->nullable();

            // ⚠️ nullOnDelete(), SURTOUT PAS cascadeOnDelete() : les comptes de
            // démonstration sont détruits toutes les heures par `openlmnp:demo-cleanup`.
            // En cascade, tout retour venu de la démo disparaîtrait dans l'heure —
            // c'est-à-dire exactement la population qu'on cherche à écouter.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            $table->timestamps();

            // L'index du test A/B/C : le rapport hebdomadaire groupe par variante
            // sur une fenêtre de dates.
            $table->index(['variant', 'created_at']);
            $table->index(['sentiment', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('feedback');
    }
};
