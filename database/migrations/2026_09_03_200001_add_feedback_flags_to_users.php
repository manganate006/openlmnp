<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Mémoire de l'invitation à donner son avis. Un cookie ne suffit pas :
            // il se vide, et l'utilisateur se ferait redemander la même chose.
            // Pour un compte de démonstration c'est l'inverse — le compte meurt
            // avant le cookie —, d'où les deux mécanismes côte à côte.
            $table->timestamp('feedback_prompted_at')->nullable();
            $table->timestamp('feedback_answered_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['feedback_prompted_at', 'feedback_answered_at']);
        });
    }
};
