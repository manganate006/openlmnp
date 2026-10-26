<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Clé OpenRouter de l'utilisateur — chiffrée par le cast Eloquent
            // `encrypted` (APP_KEY, unique par instance). Jamais sérialisée.
            $table->text('ai_api_key')->nullable();

            // Horodatage du consentement à l'envoi de données à un fournisseur d'IA tiers.
            $table->timestamp('ai_consented_at')->nullable();

            // Autorise l'assistant à consulter les données comptables (lecture seule).
            $table->boolean('ai_data_access')->default(false);

            // Surcharge de modèle par utilisateur (facultatif ; sinon config('ai.models.*')).
            $table->string('ai_model_documents')->nullable();
            $table->string('ai_model_chat')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'ai_api_key',
                'ai_consented_at',
                'ai_data_access',
                'ai_model_documents',
                'ai_model_chat',
            ]);
        });
    }
};
