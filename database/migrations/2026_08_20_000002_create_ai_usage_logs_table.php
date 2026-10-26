<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_usage_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // documents | chat | chat_tool | key_check
            $table->string('feature');

            // Slug du modèle effectivement utilisé.
            $table->string('model')->nullable();

            // instance | user
            $table->string('key_source')->nullable();

            // ok | error | refused | quota
            $table->string('status');

            // Classe d'erreur typée, jamais le message brut du fournisseur.
            $table->string('error_code')->nullable();

            $table->integer('prompt_tokens')->default(0);
            $table->integer('completion_tokens')->default(0);
            $table->decimal('cost_usd', 10, 6)->nullable();
            $table->integer('duration_ms')->default(0);

            // Compteurs et types SEULEMENT : jamais de contenu, de base64 ni de clé.
            $table->json('meta')->nullable();

            $table->timestamp('created_at')->useCurrent();

            // Index de comptage des quotas (fenêtre par utilisateur et fonction).
            $table->index(['user_id', 'feature', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_usage_logs');
    }
};
