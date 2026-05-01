<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('loans', function (Blueprint $table) {
            $table->string('insurance_type', 10)->default('fixed')->after('insurance_monthly');
            $table->decimal('insurance_rate', 5, 4)->default(0)->after('insurance_type');
        });
    }

    public function down(): void
    {
        Schema::table('loans', function (Blueprint $table) {
            $table->dropColumn(['insurance_type', 'insurance_rate']);
        });
    }
};
