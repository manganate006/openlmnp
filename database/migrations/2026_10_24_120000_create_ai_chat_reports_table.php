<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Conversations de l'assistant transmises par l'utilisateur sur un pouce vers le bas,
 * avec son accord explicite. Le texte est masqué AVANT l'écriture (App\Services\Ai\
 * Assistant\ConversationRedactor) et purgé au bout de 90 jours (AiChatReport::prunable).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_chat_reports', function (Blueprint $table) {
            $table->id();
            // Le signalement survit à la suppression du compte : son texte est déjà masqué.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('reason', 32)->nullable();
            $table->string('route', 120)->nullable();
            $table->string('model', 120)->nullable();
            $table->json('transcript');
            $table->timestamp('created_at')->useCurrent();
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_chat_reports');
    }
};
