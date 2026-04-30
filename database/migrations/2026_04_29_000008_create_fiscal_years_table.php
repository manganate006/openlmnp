<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fiscal_years', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->integer('year');
            $table->enum('status', ['draft', 'closed'])->default('draft');
            $table->integer('total_income')->default(0); // centimes
            $table->integer('total_expenses')->default(0); // centimes
            $table->integer('total_depreciation')->default(0); // centimes
            $table->integer('capped_depreciation')->default(0); // centimes - après plafonnement
            $table->integer('deferred_depreciation')->default(0); // centimes - report
            $table->integer('previous_deferred')->default(0); // centimes - report N-1
            $table->integer('fiscal_result')->default(0); // centimes
            $table->json('form_data')->nullable(); // données liasse fiscale 2031+2033
            $table->string('pdf_path')->nullable();
            $table->string('fec_path')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'year']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fiscal_years');
    }
};
