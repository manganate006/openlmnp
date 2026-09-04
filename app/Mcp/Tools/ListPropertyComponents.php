<?php

namespace App\Mcp\Tools;

use App\Models\Property;
use App\Models\PropertyComponent;
use App\Services\DepreciationService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('Liste les composants d\'amortissement par ventilation d\'un bien immobilier (gros œuvre, toiture, électricité, etc.). Retourne chaque composant avec son pourcentage, la base de calcul, la durée et l\'amortissement annuel. La somme des pourcentages doit être 100 %.')]
#[IsReadOnly]
class ListPropertyComponents extends Tool
{
    protected string $name = 'list_property_components';

    public function handle(Request $request): Response
    {
        // BelongsToUserScope vérifie automatiquement que le bien appartient à l'utilisateur
        $property = Property::findOrFail($request->get('property_id'));

        $components = PropertyComponent::where('property_id', $property->id)
            ->orderBy('sort_order')
            ->get();

        $totalBase         = (string) $components->sum('base_amount');
        $totalDepreciation = $components->sum('annual_depreciation');
        $depreciableBase   = $property->depreciable_base;

        // La complétude se mesure en CENTIMES, pas en additionnant des pourcentages :
        // une ventilation 33,33 / 33,33 / 33,34 couvre exactement la base sans que la
        // somme des pourcentages arrondis ne fasse 100.
        $remainder = bcsub($depreciableBase, $totalBase, 0);

        $data = $components->map(function (PropertyComponent $component) {
            return [
                'id'                      => $component->id,
                'property_id'             => $component->property_id,
                'name'                    => $component->name,
                'percentage'              => $component->percentage,
                'duration_years'          => $component->duration_years,
                'base_amount_eur'         => $component->base_amount_euros,
                'annual_depreciation_eur' => $component->annual_depreciation_euros,
                // `manual` = base fixée à la main (reprise d'une comptabilité existante) :
                // elle ne suit plus le prix du bien et n'est pas resynchronisée.
                'base_source'             => $component->base_source,
                // Colonnes de reprise d'antériorité : la ligne du 2033-C, la date de
                // départ propre au composant (null = mise en location du bien) et le
                // cumul déjà pratiqué par un cabinet sur des exercices non repris.
                'cerfa_category'          => $component->cerfaCategory(),
                'depreciation_start_date' => $component->depreciation_start_date?->format('Y-m-d'),
                'opening_accumulated_depreciation_eur' => bcdiv(
                    (string) $component->opening_accumulated_depreciation, '100', 2,
                ),
                'sort_order'              => $component->sort_order,
            ];
        });

        return Response::json([
            'property_id'                   => $property->id,
            'property_name'                 => $property->name,
            'depreciable_base_eur'          => $property->depreciable_base_euros,
            'count'                         => $components->count(),
            'total_percentage'              => (float) DepreciationService::percentageFromBase($depreciableBase, $totalBase),
            'total_base_eur'                => bcdiv($totalBase, '100', 2),
            'total_annual_depreciation_eur' => bcdiv((string) $totalDepreciation, '100', 2),
            'unallocated_base_eur'          => bcdiv($remainder, '100', 2),
            'percentage_complete'           => bccomp($remainder, (string) max(1, $components->count()), 0) <= 0,
            'components'                    => $data,
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'property_id' => $schema->integer('Identifiant du bien immobilier')->required(),
        ];
    }
}
