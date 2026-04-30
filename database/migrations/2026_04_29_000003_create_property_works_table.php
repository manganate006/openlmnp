<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('property_works', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained()->cascadeOnDelete();
            $table->string('description');
            $table->integer('amount'); // centimes
            $table->date('work_date');
            $table->integer('duration_years')->default(10);
            $table->boolean('is_dedicated')->default(true); // 100% dédié ou quote-part
            $table->integer('annual_depreciation'); // centimes
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('property_works');
    }
};
