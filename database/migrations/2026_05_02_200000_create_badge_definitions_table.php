<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('badge_definitions', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('category'); // onboarding, regularite, qualite, exploration
            $table->string('name');
            $table->text('description');
            $table->string('icon');
            $table->string('color')->default('gray');
            $table->boolean('is_yearly')->default(false);
            $table->integer('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->json('unlock_conditions');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('badge_definitions');
    }
};
