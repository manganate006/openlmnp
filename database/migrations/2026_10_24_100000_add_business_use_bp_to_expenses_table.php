<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Part d'usage pour la location d'une charge mixte (#17) : téléphone, internet, logiciel
 * servent aussi à titre personnel et se proratisent à l'usage, pas à la surface.
 *
 * En centièmes de pour cent (10000 = 100 %), entier comme les montants : la même formule
 * entière tourne en SQL et en PHP (voir Expense::usageAdjustedSql()). NULL = 100 %, ce qui
 * laisse toutes les charges existantes inchangées.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->unsignedSmallInteger('business_use_bp')->nullable()->after('is_dedicated');
        });
    }

    public function down(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->dropColumn('business_use_bp');
        });
    }
};
