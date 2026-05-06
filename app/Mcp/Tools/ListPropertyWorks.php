<?php

namespace App\Mcp\Tools;

use App\Models\Property;
use App\Models\PropertyWork;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('Liste les travaux immobiliers amortissables de l\'utilisateur avec filtre optionnel par bien. Retourne pour chaque travail : description, montant TTC/HT, date, durée d\'amortissement, amortissement annuel et flag dédié.')]
#[IsReadOnly]
class ListPropertyWorks extends Tool
{
    protected string $name = 'list_property_works';

    public function handle(Request $request): Response
    {
        $propertyId = $request->get('property_id');

        if ($propertyId !== null) {
            $property    = Property::findOrFail($propertyId);
            $propertyIds = collect([$property->id]);
        } else {
            $propertyIds = Property::pluck('id');
        }

        $works = PropertyWork::whereIn('property_id', $propertyIds)
            ->with('property')
            ->orderBy('work_date', 'desc')
            ->get();

        $totalAmount       = $works->sum('amount');
        $totalDepreciation = $works->sum('annual_depreciation');

        $data = $works->map(function (PropertyWork $work) {
            return [
                'id'                      => $work->id,
                'property_id'             => $work->property_id,
                'property_name'           => $work->property?->name,
                'description'             => $work->description,
                'amount_eur'              => $work->amount_euros,
                'amount_ht_eur'           => $work->amount_ht_euros,
                'tva_rate'                => $work->tva_rate ?? 0,
                'amount_tva_eur'          => $work->amount_tva_euros,
                'work_date'               => $work->work_date?->toDateString(),
                'duration_years'          => $work->duration_years,
                'annual_depreciation_eur' => $work->annual_depreciation_euros,
                'is_dedicated'            => $work->is_dedicated,
            ];
        });

        return Response::json([
            'count'                         => $works->count(),
            'total_amount_eur'              => bcdiv((string) $totalAmount, '100', 2),
            'total_annual_depreciation_eur' => bcdiv((string) $totalDepreciation, '100', 2),
            'works'                         => $data,
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'property_id' => $schema->integer('Filtrer par identifiant de bien (optionnel)'),
        ];
    }
}
