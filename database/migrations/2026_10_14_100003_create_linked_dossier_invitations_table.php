<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Préparée au lot 1, CONSOMMÉE au lot 3 (invitation du second indivisaire) : la
     * table existe dès maintenant pour que la migration de lot 3 n'ait qu'à écrire
     * dedans. Même patron de jeton haché que `invitations.token_hash`
     * (App\Services\InvitationService::hash(), sha256) — pas le même usage : une
     * invitation de dossier ne crée jamais de compte, elle rattache un indivisaire
     * DÉJÀ abonné (ou l'invite à le devenir) à un dossier existant.
     */
    public function up(): void
    {
        Schema::create('linked_dossier_invitations', function (Blueprint $table) {
            $table->id();

            $table->foreignId('linked_dossier_id')->constrained('linked_dossiers')->cascadeOnDelete();

            $table->string('email');
            $table->string('token_hash')->unique();

            $table->foreignId('invited_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamp('expires_at');
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('revoked_at')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('linked_dossier_invitations');
    }
};
