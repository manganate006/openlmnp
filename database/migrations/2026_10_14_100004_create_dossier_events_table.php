<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Audit des opérations sur un dossier lié (création, bascule, retour, révocation,
     * archivage...). Jamais relu par un calcul : purement journal. `linked_dossier_id`
     * SANS contrainte vers `linked_dossiers` : un événement doit survivre à la
     * suppression de sa ligne (ce que ce dépôt ne fait d'ailleurs jamais).
     * Exception RGPD : quand le dossier lui-même est supprimé avec son dernier
     * membre, son journal part avec lui (App\Support\DossierEventScrubber).
     */
    public function up(): void
    {
        Schema::create('dossier_events', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('linked_dossier_id')->index();

            // Qui a déclenché l'événement — nullable : une purge ou une tâche planifiée
            // future n'a pas d'utilisateur à l'origine.
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();

            // created | switched | left | member_invited | member_revoked | archived | transferred
            $table->string('type');

            $table->json('meta')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dossier_events');
    }
};
