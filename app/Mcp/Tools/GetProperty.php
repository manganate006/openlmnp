<?php

namespace App\Mcp\Tools;

use App\Models\Property;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('Retourne le détail complet d\'un bien immobilier : caractéristiques, montants financiers, base amortissable, et comptage des composants, travaux, mobilier et emprunts associés.')]
#[IsReadOnly]
class GetProperty extends Tool
{
    protected string $name = 'get_property';

    public function handle(Request $request): Response
    {
        // BelongsToUserScope garantit que seul le bien de l'utilisateur est accessible
        $property = Property::withCount(['components', 'works', 'furniture', 'loans', 'incomes', 'expenses'])
            ->findOrFail($request->get('property_id'));

        return Response::json([
            'id'                    => $property->id,
            'name'                  => $property->name,
            'address'               => $property->address,
            'city'                  => $property->city,
            'postal_code'           => $property->postal_code,
            'type'                  => $property->type,
            'type_label'            => $property->getTypeLabel(),
            'total_area_m2'         => $property->total_area,
            'rented_area_m2'        => $property->rented_area,
            'quota_share'           => $property->quota_share,
            'acquisition_date'      => $property->acquisition_date?->toDateString(),
            'acquisition_price_eur' => $property->acquisition_price_euros,
            'notary_fees_eur'       => $property->notary_fees_euros,
            'agency_fees_eur'       => $property->agency_fees_euros,
            'market_value_eur'      => $property->market_value_euros,
            'market_value_date'     => $property->market_value_date?->toDateString(),
            'land_percentage'       => $property->land_percentage,
            'depreciable_base_eur'  => $property->depreciable_base_euros,
            'rental_start_date'     => $property->rental_start_date?->toDateString(),
            'rental_type'           => $property->rental_type,
            'rental_type_label'     => $property->getRentalTypeLabel(),
            'tva_regime'            => $property->tva_regime,
            'tva_regime_label'      => (Property::tvaRegimeLabels()[$property->tva_regime] ?? $property->tva_regime),
            'is_primary_residence'  => $property->is_primary_residence,
            'notes'                 => $property->notes,
            'listing_urls'          => $property->listing_urls ?? [],
            'counts'                => [
                'components' => $property->components_count,
                'works'      => $property->works_count,
                'furniture'  => $property->furniture_count,
                'loans'      => $property->loans_count,
                'incomes'    => $property->incomes_count,
                'expenses'   => $property->expenses_count,
            ],
            'created_at' => $property->created_at?->toDateTimeString(),
            'updated_at' => $property->updated_at?->toDateTimeString(),
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'property_id' => $schema->integer('Identifiant du bien immobilier')->required(),
        ];
    }
}
