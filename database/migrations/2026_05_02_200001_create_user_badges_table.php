<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_badges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('badge_definition_id')->constrained()->cascadeOnDelete();
            $table->timestamp('unlocked_at');
            $table->integer('fiscal_year')->nullable();
            $table->json('context')->nullable();
            $table->boolean('is_notified')->default(false);
            $table->timestamps();

            $table->unique(['user_id', 'badge_definition_id', 'fiscal_year']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_badges');
    }
};
