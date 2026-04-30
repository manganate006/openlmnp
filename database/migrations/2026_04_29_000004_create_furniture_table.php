<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('furniture', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained()->cascadeOnDelete();
            $table->string('description');
            $table->integer('amount'); // centimes
            $table->date('purchase_date');
            $table->integer('duration_years')->default(5);
            $table->boolean('is_dedicated')->default(true);
            $table->boolean('is_second_hand')->default(false);
            $table->integer('annual_depreciation'); // centimes
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('furniture');
    }
};
