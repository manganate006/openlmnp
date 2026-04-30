<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('property_components', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained()->cascadeOnDelete();
            $table->string('name'); // Ex: "Gros œuvre", "Toiture"
            $table->integer('percentage'); // % de la base amortissable (ex: 50)
            $table->integer('duration_years'); // durée d'amortissement
            $table->integer('base_amount'); // centimes - montant de base calculé
            $table->integer('annual_depreciation'); // centimes - amortissement annuel
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('property_components');
    }
};
