<?php

use App\Models\Property;
use App\Models\PropertyComponent;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Rend le plan d'amortissement capable de reproduire celui d'un cabinet comptable.
 *
 * Cinq colonnes, un seul objectif : qu'un bailleur qui quitte son expert-comptable
 * retrouve EXACTEMENT les chiffres de sa liasse. Jusqu'ici l'application imposait ses
 * propres conventions — tous les composants démarrent à la mise en location, les frais
 * d'acquisition s'amortissent sur 25 ans, la ligne Cerfa se déduit du NOM du composant —
 * et le moindre écart avec le cabinet était irréparable.
 *
 * ⚠️ `cerfa_category` est rétro-classée par la table de correspondance qui vivait dans
 * `TaxReturnService::compute2033C()`, À L'IDENTIQUE : aucun montant ne change de ligne
 * Cerfa du fait de cette migration. C'est ce que vérifie `TaxReturnCerfaCategoryTest`.
 *
 * ⚠️ `opening_accumulated_depreciation` (les trois tables) n'entre JAMAIS dans la dotation
 * d'un exercice : il ne s'ajoute qu'aux cumuls affichés (2033-A case 030, colonne
 * « amortissements » du 2033-C). L'y faire entrer doublerait la charge de l'exercice.
 */
return new class extends Migration
{
    /**
     * Rattachement d'un composant à une ligne du 2033-C, tel qu'il se faisait par le nom.
     * Recopié depuis `TaxReturnService::compute2033C()` — ne rien y changer ici.
     */
    private const LEGACY_NAME_TO_CATEGORY = [
        'Gros œuvre' => 'constructions',
        'Toiture' => 'constructions',
        'Installations électriques' => 'installations',
        'Plomberie / sanitaire' => 'installations',
        'Étanchéité' => 'agencements',
        'Agencements intérieurs' => 'agencements',
    ];

    public function up(): void
    {
        Schema::table('property_components', function (Blueprint $table) {
            // Défaut = rental_start_date du bien, résolu à la lecture et non recopié :
            // déplacer la mise en location doit continuer d'entraîner tout le plan.
            $table->date('depreciation_start_date')->nullable();
            $table->string('cerfa_category', 16)->nullable();
            $table->integer('opening_accumulated_depreciation')->default(0);
        });

        Schema::table('property_works', function (Blueprint $table) {
            $table->string('depreciation_source', 16)->default('computed');
            $table->integer('opening_accumulated_depreciation')->default(0);
        });

        Schema::table('furniture', function (Blueprint $table) {
            $table->string('depreciation_source', 16)->default('computed');
            $table->integer('opening_accumulated_depreciation')->default(0);
        });

        Schema::table('properties', function (Blueprint $table) {
            $table->string('acquisition_fees_treatment', 16)->default(Property::ACQUISITION_FEES_AMORTIZED);
            $table->unsignedSmallInteger('acquisition_fees_duration')->default(25);
        });

        $this->classifyExistingComponents();
    }

    public function down(): void
    {
        Schema::table('property_components', function (Blueprint $table) {
            $table->dropColumn(['depreciation_start_date', 'cerfa_category', 'opening_accumulated_depreciation']);
        });

        Schema::table('property_works', function (Blueprint $table) {
            $table->dropColumn(['depreciation_source', 'opening_accumulated_depreciation']);
        });

        Schema::table('furniture', function (Blueprint $table) {
            $table->dropColumn(['depreciation_source', 'opening_accumulated_depreciation']);
        });

        Schema::table('properties', function (Blueprint $table) {
            $table->dropColumn(['acquisition_fees_treatment', 'acquisition_fees_duration']);
        });
    }

    /**
     * Fige la ligne Cerfa de chaque composant existant sur celle que son nom lui donnait.
     *
     * Écriture en SQL direct : le hook `saving()` du modèle recalculerait la dotation,
     * ce que cette migration n'a aucune raison de toucher.
     */
    private function classifyExistingComponents(): void
    {
        foreach (self::LEGACY_NAME_TO_CATEGORY as $name => $category) {
            DB::table('property_components')->where('name', $name)->update(['cerfa_category' => $category]);
        }

        DB::table('property_components')
            ->whereNull('cerfa_category')
            ->update(['cerfa_category' => PropertyComponent::CERFA_CATEGORY_OTHER]);
    }
};
