<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Régime TVA sur le bien
        Schema::table('properties', function (Blueprint $table) {
            $table->string('tva_regime', 10)->default('exempt')->after('rental_type');
        });

        // TVA sur les charges
        Schema::table('expenses', function (Blueprint $table) {
            $table->integer('tva_rate')->default(0)->after('amount');
            $table->integer('amount_ht')->default(0)->after('tva_rate');
            $table->integer('amount_tva')->default(0)->after('amount_ht');
        });

        // TVA sur les travaux
        Schema::table('property_works', function (Blueprint $table) {
            $table->integer('tva_rate')->default(0)->after('amount');
            $table->integer('amount_ht')->default(0)->after('tva_rate');
            $table->integer('amount_tva')->default(0)->after('amount_ht');
        });

        // TVA sur le mobilier
        Schema::table('furniture', function (Blueprint $table) {
            $table->integer('tva_rate')->default(0)->after('amount');
            $table->integer('amount_ht')->default(0)->after('tva_rate');
            $table->integer('amount_tva')->default(0)->after('amount_ht');
        });

        // TVA sur les revenus
        Schema::table('incomes', function (Blueprint $table) {
            $table->integer('tva_rate')->default(0)->after('amount');
            $table->integer('amount_ht')->default(0)->after('tva_rate');
            $table->integer('tva_collected')->default(0)->after('amount_ht');
        });

        // Résumé TVA sur l'exercice fiscal
        Schema::table('fiscal_years', function (Blueprint $table) {
            $table->integer('total_tva_collected')->default(0)->after('fiscal_result');
            $table->integer('total_tva_deductible')->default(0)->after('total_tva_collected');
            $table->integer('tva_balance')->default(0)->after('total_tva_deductible');
        });

        // Backfill : pour les données existantes, amount_ht = amount (tva_rate = 0)
        DB::table('expenses')->update(['amount_ht' => DB::raw('amount')]);
        DB::table('property_works')->update(['amount_ht' => DB::raw('amount')]);
        DB::table('furniture')->update(['amount_ht' => DB::raw('amount')]);
        DB::table('incomes')->update(['amount_ht' => DB::raw('amount')]);
    }

    public function down(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->dropColumn('tva_regime');
        });

        Schema::table('expenses', function (Blueprint $table) {
            $table->dropColumn(['tva_rate', 'amount_ht', 'amount_tva']);
        });

        Schema::table('property_works', function (Blueprint $table) {
            $table->dropColumn(['tva_rate', 'amount_ht', 'amount_tva']);
        });

        Schema::table('furniture', function (Blueprint $table) {
            $table->dropColumn(['tva_rate', 'amount_ht', 'amount_tva']);
        });

        Schema::table('incomes', function (Blueprint $table) {
            $table->dropColumn(['tva_rate', 'amount_ht', 'tva_collected']);
        });

        Schema::table('fiscal_years', function (Blueprint $table) {
            $table->dropColumn(['total_tva_collected', 'total_tva_deductible', 'tva_balance']);
        });
    }
};
