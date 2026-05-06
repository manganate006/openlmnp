<?php

namespace App\Mcp\Tools;

use App\Models\Property;
use App\Models\PropertyComponent;
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

        $totalPercentage   = $components->sum('percentage');
        $totalBase         = $components->sum('base_amount');
        $totalDepreciation = $components->sum('annual_depreciation');

        $data = $components->map(function (PropertyComponent $component) {
            return [
                'id'                      => $component->id,
                'property_id'             => $component->property_id,
                'name'                    => $component->name,
                'percentage'              => $component->percentage,
                'duration_years'          => $component->duration_years,
                'base_amount_eur'         => $component->base_amount_euros,
                'annual_depreciation_eur' => $component->annual_depreciation_euros,
                'sort_order'              => $component->sort_order,
            ];
        });

        return Response::json([
            'property_id'                   => $property->id,
            'property_name'                 => $property->name,
            'depreciable_base_eur'          => $property->depreciable_base_euros,
            'count'                         => $components->count(),
            'total_percentage'              => $totalPercentage,
            'total_base_eur'                => bcdiv((string) $totalBase, '100', 2),
            'total_annual_depreciation_eur' => bcdiv((string) $totalDepreciation, '100', 2),
            'percentage_complete'           => $totalPercentage >= 100,
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
