<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expenses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained()->cascadeOnDelete();
            $table->date('expense_date');
            $table->integer('amount'); // centimes
            $table->enum('category', [
                'property_tax',      // Taxe foncière
                'insurance',         // Assurance
                'energy',            // Électricité, gaz, eau
                'maintenance',       // Entretien, réparations
                'supplies',          // Fournitures, linge, consommables
                'platform_fees',     // Commissions plateformes
                'accounting',        // Expert-comptable, logiciel
                'telecom',           // Internet, téléphone
                'travel',            // Frais de déplacement
                'cleaning',          // Ménage
                'other',             // Divers
            ]);
            $table->string('description');
            $table->boolean('is_dedicated')->default(false); // 100% dédié ou prorata
            $table->string('receipt_path')->nullable(); // pièce justificative
            $table->enum('recurring_type', ['once', 'monthly', 'quarterly', 'yearly'])->default('once');
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expenses');
    }
};
