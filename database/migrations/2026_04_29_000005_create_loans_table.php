<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained()->cascadeOnDelete();
            $table->string('bank_name')->nullable();
            $table->integer('amount'); // centimes - montant emprunté
            $table->decimal('annual_rate', 5, 3); // taux annuel (ex: 1.500 pour 1,5%)
            $table->integer('duration_months');
            $table->date('start_date');
            $table->integer('monthly_payment'); // centimes - mensualité
            $table->integer('insurance_monthly')->default(0); // centimes - assurance
            $table->timestamps();
        });

        Schema::create('loan_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('loan_id')->constrained()->cascadeOnDelete();
            $table->date('payment_date');
            $table->integer('month_number');
            $table->integer('capital_amount'); // centimes
            $table->integer('interest_amount'); // centimes
            $table->integer('insurance_amount')->default(0); // centimes
            $table->integer('remaining_capital'); // centimes
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loan_payments');
        Schema::dropIfExists('loans');
    }
};
