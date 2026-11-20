<?php

use App\Models\Lease;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bail d'une location meublée à l'année (issue #24).
 *
 * Saisi une fois sur le bien, le bail sert de modèle aux loyers mensuels. Les loyers
 * générés restent de vraies lignes `incomes`, comme les échéances des charges
 * récurrentes (#9) : tous les calculs les captent sans qu'on en touche un seul.
 *
 * `charges_mode` est une chaîne et non un `enum` : sous SQLite, un `enum` devient une
 * contrainte CHECK, et ajouter une valeur demanderait de reconstruire la table.
 *
 * Deux baux d'un même bien ne se chevauchent pas dans cette version (voir
 * `Lease::assertValid()`). La colocation viendra plus tard, avec un choix explicite.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained()->cascadeOnDelete();
            $table->string('tenant_name');
            $table->date('start_date');
            $table->date('end_date')->nullable(); // vide tant que le bail court
            $table->integer('rent_amount'); // centimes, loyer hors charges
            $table->integer('charges_amount')->default(0); // centimes
            $table->string('charges_mode', 16)->default(Lease::CHARGES_PROVISIONS);
            $table->unsignedTinyInteger('due_day')->default(1); // jour d'échéance, 1 à 31
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['property_id', 'start_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leases');
    }
};
