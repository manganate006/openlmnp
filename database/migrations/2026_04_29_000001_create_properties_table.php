<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('properties', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name'); // Ex: "Appartement Airbnb"
            $table->string('address');
            $table->string('city');
            $table->string('postal_code', 10);
            $table->enum('type', ['apartment', 'house', 'room', 'studio', 'other'])->default('apartment');
            $table->integer('total_area'); // m² déclarés aux impôts
            $table->integer('rented_area'); // m² loués
            $table->date('acquisition_date');
            $table->integer('acquisition_price'); // centimes
            $table->integer('notary_fees')->default(0); // centimes
            $table->integer('market_value')->nullable(); // centimes - valeur vénale
            $table->date('market_value_date')->nullable();
            $table->integer('land_percentage')->default(15); // % terrain non amortissable
            $table->date('rental_start_date'); // début mise en location
            $table->enum('rental_type', ['seasonal', 'long_term', 'mixed'])->default('seasonal');
            $table->boolean('is_primary_residence')->default(false);
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('properties');
    }
};
