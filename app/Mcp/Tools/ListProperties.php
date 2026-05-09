<?php

namespace App\Mcp\Tools;

use App\Models\Property;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('Liste tous les biens immobiliers de l\'utilisateur authentifié avec leurs informations clés (surface, prix d\'acquisition, quote-part, type de location).')]
#[IsReadOnly]
class ListProperties extends Tool
{
    protected string $name = 'list_properties';

    public function handle(Request $request): Response
    {
        $properties = Property::withCount(['components', 'works', 'furniture', 'loans'])
            ->orderBy('name')
            ->get();

        $data = $properties->map(function (Property $property) {
            return [
                'id'                     => $property->id,
                'name'                   => $property->name,
                'address'                => $property->address,
                'city'                   => $property->city,
                'postal_code'            => $property->postal_code,
                'type'                   => $property->type,
                'type_label'             => $property->getTypeLabel(),
                'total_area_m2'          => $property->total_area,
                'rented_area_m2'         => $property->rented_area,
                'quota_share'            => $property->quota_share,
                'acquisition_date'       => $property->acquisition_date?->toDateString(),
                'acquisition_price_eur'  => $property->acquisition_price_euros,
                'notary_fees_eur'        => $property->notary_fees_euros,
                'agency_fees_eur'        => $property->agency_fees_euros,
                'market_value_eur'       => $property->market_value_euros,
                'land_percentage'        => $property->land_percentage,
                'depreciable_base_eur'   => $property->depreciable_base_euros,
                'rental_start_date'      => $property->rental_start_date?->toDateString(),
                'rental_type'            => $property->rental_type,
                'rental_type_label'      => $property->getRentalTypeLabel(),
                'tva_regime'             => $property->tva_regime,
                'is_primary_residence'   => $property->is_primary_residence,
                'components_count'       => $property->components_count,
                'works_count'            => $property->works_count,
                'furniture_count'        => $property->furniture_count,
                'loans_count'            => $property->loans_count,
            ];
        });

        return Response::json([
            'count'      => $properties->count(),
            'properties' => $data,
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
