<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('linked_dossier_members', function (Blueprint $table) {
            $table->id();

            $table->foreignId('linked_dossier_id')->constrained('linked_dossiers')->cascadeOnDelete();

            // Le compte PRINCIPAL d'un indivisaire — jamais le User du dossier lui-même.
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();

            // 'owner' | 'member' — voir App\Enums\DossierMemberRole. Un seul membre actif
            // (ni révoqué) peut porter 'owner' par dossier : vérifié en ÉCRITURE par
            // App\Services\LinkedDossierService (clause WHERE + transaction), pas par une
            // contrainte SQL — SQLite ne sait pas poser d'index unique partiel portable
            // vers PostgreSQL dans ce projet.
            $table->string('role')->default('member');

            $table->foreignId('invited_by')->nullable()->constrained('users')->nullOnDelete();

            // Le créateur est accepté immédiatement (accepted_at = created_at) ; un
            // second indivisaire invité (lot 3) ne l'est qu'à la confirmation du lien.
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('revoked_at')->nullable();

            $table->timestamps();

            $table->unique(['linked_dossier_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('linked_dossier_members');
    }
};
