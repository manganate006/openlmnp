<?php

use App\Models\Property;
use App\Models\PropertyComponent;
use App\Services\DepreciationService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Bascule la source de vérité d'un composant du pourcentage vers la base amortissable.
 *
 * Un pourcentage entier ne peut pas représenter une ventilation exacte : c'est ce qui
 * rendait impossible de reproduire un plan d'amortissement déjà pratiqué par un
 * comptable (issue #8). `percentage` devient décimal et surtout DÉRIVÉ ; `base_source`
 * dit qui pilote la base, et protège celles qui sont saisies à la main.
 *
 * Le rétro-classement n'invente pas de règle : il applique celle qu'`openlmnp:repair-components`
 * appliquait déjà. Un écart d'un facteur ≥ 10 par rapport à la base théorique est une
 * corruption (double conversion ×100) et reste réparable ; un écart plus faible était
 * déjà réputé volontaire par la commande, et devient donc explicitement `manual`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('property_components', function (Blueprint $table) {
            $table->string('base_source', 16)->default(PropertyComponent::BASE_SOURCE_PERCENTAGE);
        });

        Schema::table('property_components', function (Blueprint $table) {
            // 7 chiffres dont 4 décimales : de 0,0001 % à 999,9999 %.
            $table->decimal('percentage', 7, 4)->change();
        });

        $this->classifyExistingComponents();
    }

    public function down(): void
    {
        Schema::table('property_components', function (Blueprint $table) {
            $table->dropColumn('base_source');
        });

        Schema::table('property_components', function (Blueprint $table) {
            // Les décimales d'une ventilation exacte sont perdues au retour arrière.
            $table->integer('percentage')->change();
        });
    }

    /**
     * Marque `manual` les composants dont la base avait déjà été réglée à la main.
     *
     * Écriture en SQL direct, sans passer par le modèle : son hook `saving()` recalcule
     * la dotation depuis la base, ce qui modifierait des montants que cette migration
     * n'a aucune raison de toucher.
     */
    private function classifyExistingComponents(): void
    {
        $properties = Property::withoutGlobalScopes()
            ->with(['components' => fn ($query) => $query->withoutGlobalScopes()])
            ->get();

        foreach ($properties as $property) {
            foreach (DepreciationService::classifyLegacyBaseSource($property) as $id => $source) {
                if ($source === PropertyComponent::BASE_SOURCE_PERCENTAGE) {
                    continue; // c'est déjà la valeur par défaut de la colonne
                }

                DB::table('property_components')->where('id', $id)->update(['base_source' => $source]);
            }
        }
    }
};
