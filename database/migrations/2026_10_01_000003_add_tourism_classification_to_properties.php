<?php

use App\Models\Property;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Classement micro-BIC du bien (CGI art. 50-0) : conditionne le TAUX d'abattement et
 * le PLAFOND de chiffre d'affaires en deçà duquel le micro-BIC reste possible.
 *
 *   - furnished_tourism_unclassified : meublé de tourisme NON classé — 30 %,
 *     plafond 15 000 € (inchangé depuis la loi Le Meur du 19/11/2024)
 *   - furnished_tourism_classified   : meublé de tourisme CLASSÉ — 50 %,
 *     plafond 83 600 € pour les revenus 2026 (77 700 € en 2025)
 *   - long_term                      : location meublée classique (bail) — même
 *     barème que le classé depuis la même loi
 *
 * Valeurs et plafonds centralisés dans `config/micro_bic.php`
 * (App\Support\MicroBicRules) — jamais recopiés ailleurs.
 *
 * Rétrocompatible : backfill depuis `rental_type` pour les biens existants
 * (`App\Support\MicroBicRules::defaultClassificationForRentalType()`), reproduit ici
 * en SQL direct pour ne pas dépendre du code applicatif dans une migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->string('tourism_classification', 40)
                ->default(Property::CLASSIFICATION_UNCLASSIFIED)
                ->after('rental_type');
        });

        DB::table('properties')
            ->where('rental_type', 'long_term')
            ->update(['tourism_classification' => Property::CLASSIFICATION_LONG_TERM]);
    }

    public function down(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->dropColumn('tourism_classification');
        });
    }
};
