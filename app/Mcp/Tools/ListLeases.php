<?php

namespace App\Mcp\Tools;

use App\Models\Lease;
use App\Models\Property;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('Liste les baux de location à l\'année de l\'utilisateur, avec filtre optionnel par bien. Retourne pour chaque bail : locataire, dates de début et de fin, loyer hors charges, charges et leur mode (forfait ou provisions), jour d\'échéance et nombre de loyers générés. Les loyers eux-mêmes se lisent avec list_incomes, filtre lease_id.')]
#[IsReadOnly]
class ListLeases extends Tool
{
    protected string $name = 'list_leases';

    public function handle(Request $request): Response
    {
        $propertyId = $request->get('property_id');

        if ($propertyId !== null) {
            $property    = Property::findOrFail($propertyId);
            $propertyIds = collect([$property->id]);
        } else {
            $propertyIds = Property::pluck('id');
        }

        $leases = Lease::whereIn('property_id', $propertyIds)
            ->with('property')
            ->withCount('incomes')
            ->orderBy('start_date', 'desc')
            ->get();

        $data = $leases->map(fn (Lease $lease) => [
            'id'                 => $lease->id,
            'property_id'        => $lease->property_id,
            'property_name'      => $lease->property?->name,
            'tenant_name'        => $lease->tenant_name,
            'start_date'         => $lease->start_date?->toDateString(),
            'end_date'           => $lease->end_date?->toDateString(),
            'is_active'          => $lease->isActiveOn(now()),
            'rent_amount_eur'    => bcdiv((string) $lease->rent_amount, '100', 2),
            'charges_amount_eur' => bcdiv((string) $lease->charges_amount, '100', 2),
            'monthly_total_eur'  => bcdiv((string) $lease->monthly_total, '100', 2),
            'charges_mode'       => $lease->charges_mode,
            'charges_mode_label' => Lease::chargesModeLabels()[$lease->charges_mode] ?? $lease->charges_mode,
            'due_day'            => $lease->due_day,
            'rents_count'        => $lease->incomes_count,
            'notes'              => $lease->notes,
        ]);

        return Response::json([
            'count'  => $leases->count(),
            'leases' => $data,
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'property_id' => $schema->integer()->description('Filtrer par identifiant de bien (optionnel)'),
        ];
    }
}
