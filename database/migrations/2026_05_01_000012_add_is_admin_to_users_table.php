<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_admin')->default(false)->after('email');
        });

        // Set admin for known admin account + first user as fallback
        $adminSet = DB::table('users')
            ->where('email', '***REDACTED-EMAIL***')
            ->update(['is_admin' => true]);

        if (! $adminSet) {
            $firstUser = DB::table('users')->orderBy('id')->first();
            if ($firstUser) {
                DB::table('users')->where('id', $firstUser->id)->update(['is_admin' => true]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('is_admin');
        });
    }
};
