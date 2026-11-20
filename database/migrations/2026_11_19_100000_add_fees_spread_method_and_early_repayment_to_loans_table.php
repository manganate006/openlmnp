<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lot 2 des frais d'emprunt (BOI-BIC-CHG-20-30-40 § 60-130) :
 *
 * - `fees_spread_method` : méthode d'étalement choisie PAR EMPRUNT (§ 60), à la différence
 *   de l'option d'étalement elle-même qui reste globale au dossier (`users.loan_fees_spread_from`,
 *   lot 1). Défaut `linear` : fractions annuelles égales (§ 70, sans prorata temporis). `interest`
 *   proratise sur les intérêts courus de chaque année (§ 100-110) — seulement disponible si
 *   l'emprunt porte un tableau d'amortissement (sinon repli silencieux sur `linear`, signalé par
 *   `get_loan`).
 * - `early_repayment_date` / `early_repayment_amount` : remboursement anticipé (§ 120-130). Rien
 *   dans l'application ne distingue aujourd'hui une échéance normale d'un remboursement anticipé
 *   (`schedule_source` ne porte que `computed`/`imported`, et aucune échéance n'est marquée comme
 *   telle) : il n'existe donc PAS de signal fiable à partir duquel le déduire automatiquement. Ces
 *   deux colonnes sont la saisie minimale qui permet d'appliquer la règle sans régénérer le
 *   tableau d'amortissement (hors périmètre de ce lot).
 *
 * Défauts inchangés pour tous les emprunts existants : `linear` reproduit exactement le
 * comportement du lot 1, et les deux colonnes de remboursement anticipé sont nullables.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('loans', function (Blueprint $table) {
            $table->string('fees_spread_method')->default('linear')->after('fees_paid_on');
            $table->date('early_repayment_date')->nullable()->after('fees_spread_method');
            $table->unsignedBigInteger('early_repayment_amount')->nullable()->after('early_repayment_date'); // centimes
        });
    }

    public function down(): void
    {
        Schema::table('loans', function (Blueprint $table) {
            $table->dropColumn(['fees_spread_method', 'early_repayment_date', 'early_repayment_amount']);
        });
    }
};
