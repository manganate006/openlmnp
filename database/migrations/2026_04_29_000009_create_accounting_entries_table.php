<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounting_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fiscal_year_id')->constrained()->cascadeOnDelete();
            $table->foreignId('property_id')->nullable()->constrained()->nullOnDelete();
            $table->date('entry_date');
            $table->string('account_code', 10); // plan comptable (606, 613, 681, etc.)
            $table->string('label');
            $table->integer('debit')->default(0); // centimes
            $table->integer('credit')->default(0); // centimes
            $table->string('piece_ref')->nullable(); // référence pièce justificative
            $table->string('journal', 10)->default('OD'); // OD, HA, VE, BQ
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accounting_entries');
    }
};
