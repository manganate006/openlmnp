<?php

namespace App\Mcp\Tools;

use App\Models\Loan;
use App\Models\Property;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('Liste les emprunts immobiliers de l\'utilisateur avec filtre optionnel par bien. Retourne pour chaque emprunt : banque, capital, taux, durée, mensualité, assurance, dates de début/fin et coût total estimé.')]
#[IsReadOnly]
class ListLoans extends Tool
{
    protected string $name = 'list_loans';

    public function handle(Request $request): Response
    {
        $propertyId = $request->get('property_id');

        if ($propertyId !== null) {
            $property    = Property::findOrFail($propertyId);
            $propertyIds = collect([$property->id]);
        } else {
            $propertyIds = Property::pluck('id');
        }

        $loans = Loan::whereIn('property_id', $propertyIds)
            ->with('property')
            ->orderBy('start_date', 'desc')
            ->get();

        $data = $loans->map(function (Loan $loan) {
            return [
                'id'                    => $loan->id,
                'property_id'           => $loan->property_id,
                'property_name'         => $loan->property?->name,
                'bank_name'             => $loan->bank_name,
                'amount_eur'            => $loan->amount_euros,
                'annual_rate'           => $loan->annual_rate,
                'duration_months'       => $loan->duration_months,
                'duration_years'        => $loan->duration_years,
                'start_date'            => $loan->start_date?->toDateString(),
                'end_date'              => $loan->end_date?->toDateString(),
                'monthly_payment_eur'   => $loan->monthly_payment_euros,
                'insurance_monthly_eur' => $loan->insurance_monthly_euros,
                'total_cost_eur'        => bcdiv((string) $loan->total_cost, '100', 2),
                'has_schedule'          => $loan->payments()->exists(),
                'payments_count'        => $loan->payments()->count(),
            ];
        });

        return Response::json([
            'count' => $loans->count(),
            'loans' => $data,
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'property_id' => $schema->integer('Filtrer par identifiant de bien (optionnel)'),
        ];
    }
}
