<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('badge_progress', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('badge_definition_id')->constrained()->cascadeOnDelete();
            $table->integer('fiscal_year')->nullable();
            $table->integer('current_value')->default(0);
            $table->integer('target_value');
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'badge_definition_id', 'fiscal_year']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('badge_progress');
    }
};
