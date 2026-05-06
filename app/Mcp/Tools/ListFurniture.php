<?php

namespace App\Mcp\Tools;

use App\Models\Furniture;
use App\Models\Property;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('Liste le mobilier et équipements amortissables de l\'utilisateur avec filtre optionnel par bien. Retourne pour chaque élément : description, montant d\'achat, date, durée d\'amortissement, amortissement annuel, flag dédié et occasion.')]
#[IsReadOnly]
class ListFurniture extends Tool
{
    protected string $name = 'list_furniture';

    public function handle(Request $request): Response
    {
        $propertyId = $request->get('property_id');

        if ($propertyId !== null) {
            $property    = Property::findOrFail($propertyId);
            $propertyIds = collect([$property->id]);
        } else {
            $propertyIds = Property::pluck('id');
        }

        $furniture = Furniture::whereIn('property_id', $propertyIds)
            ->with('property')
            ->orderBy('purchase_date', 'desc')
            ->get();

        $totalAmount       = $furniture->sum('amount');
        $totalDepreciation = $furniture->sum('annual_depreciation');

        $data = $furniture->map(function (Furniture $item) {
            return [
                'id'                     => $item->id,
                'property_id'            => $item->property_id,
                'property_name'          => $item->property?->name,
                'description'            => $item->description,
                'amount_eur'             => $item->amount_euros,
                'amount_ht_eur'          => $item->amount_ht_euros,
                'tva_rate'               => $item->tva_rate ?? 0,
                'purchase_date'          => $item->purchase_date?->toDateString(),
                'duration_years'         => $item->duration_years,
                'annual_depreciation_eur'=> $item->annual_depreciation_euros,
                'is_dedicated'           => $item->is_dedicated,
                'is_second_hand'         => $item->is_second_hand,
            ];
        });

        return Response::json([
            'count'                      => $furniture->count(),
            'total_amount_eur'           => bcdiv((string) $totalAmount, '100', 2),
            'total_annual_depreciation_eur' => bcdiv((string) $totalDepreciation, '100', 2),
            'furniture'                  => $data,
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'property_id' => $schema->integer('Filtrer par identifiant de bien (optionnel)'),
        ];
    }
}
