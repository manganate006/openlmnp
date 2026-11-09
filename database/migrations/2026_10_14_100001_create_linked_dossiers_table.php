<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('linked_dossiers', function (Blueprint $table) {
            $table->id();

            // Seul identifiant EXPOSÉ (URL, sélecteur de dossier) : jamais `id`, qui
            // numéroterait les dossiers de l'instance entière à qui sait compter.
            $table->ulid('public_id')->unique();

            // Le User du dossier lui-même (is_linked_dossier = true). Unique : un
            // User ne porte jamais deux dossiers.
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();

            // Qui a créé le dossier (débité de son quota). Conservé même si le
            // créateur quitte l'indivision (décision n°5) : NULL seulement si le
            // compte principal est supprimé par un administrateur.
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();

            // 'indivision' aux lots 1-2. `kind` existe dès maintenant pour que les
            // dossiers payants futurs (SCI à l'IR, conjoint — décision n°10) n'exigent
            // pas de nouvelle colonne.
            $table->string('kind')->default('indivision');

            // UNIQUE y compris pour un dossier archivé : un SIREN déjà suivi ailleurs
            // reste refusé même après archivage (anti-détournement, décision n°4).
            $table->string('siren', 9)->unique();

            // Dernière fiche Sirene consultée (lecture FRAICHE, sans cache — voir
            // SireneClient::lookup(fresh: true)) : trace de la vérification faite à la
            // création, jamais relue pour autoriser quoi que ce soit après coup.
            $table->json('sirene_snapshot')->nullable();
            $table->timestamp('sirene_checked_at')->nullable();

            $table->timestamp('archived_at')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('linked_dossiers');
    }
};
