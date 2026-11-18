<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Frais d'emprunt (art. 39-1-1° quater CGI, BOI-BIC-CHG-20-30-40) : frais de dossier,
 * courtage, part NON restituable d'une caution. `fees_paid_on` nullable : la date
 * effective retenue est alors `start_date` (voir `LoanService::feesForYear()`), comme
 * `monthly_payment`/`insurance_monthly` le font déjà pour leurs propres défauts.
 *
 * Défaut `fees_amount = 0` : comportement inchangé pour tous les emprunts existants, qui
 * ne portent aucun frais tant qu'ils n'en saisissent pas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('loans', function (Blueprint $table) {
            $table->unsignedBigInteger('fees_amount')->default(0)->after('is_dedicated'); // centimes
            $table->date('fees_paid_on')->nullable()->after('fees_amount');
        });
    }

    public function down(): void
    {
        Schema::table('loans', function (Blueprint $table) {
            $table->dropColumn(['fees_amount', 'fees_paid_on']);
        });
    }
};
