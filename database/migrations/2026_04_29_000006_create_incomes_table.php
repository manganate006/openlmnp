<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('incomes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained()->cascadeOnDelete();
            $table->date('income_date');
            $table->integer('amount'); // centimes - montant brut loyer
            $table->integer('platform_fee')->default(0); // centimes - commission plateforme
            $table->integer('tourist_tax')->default(0); // centimes - taxe de séjour
            $table->enum('source', ['airbnb', 'booking', 'abritel', 'direct', 'other'])->default('airbnb');
            $table->string('reservation_ref')->nullable();
            $table->string('guest_name')->nullable();
            $table->date('checkin_date')->nullable();
            $table->date('checkout_date')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('incomes');
    }
};
