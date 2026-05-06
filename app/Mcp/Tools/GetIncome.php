<?php

namespace App\Mcp\Tools;

use App\Models\Income;
use App\Models\Property;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('Retourne le détail complet d\'une recette locative par son identifiant : montants TTC/HT, TVA, frais de plateforme, taxe de séjour, informations de réservation et du voyageur.')]
#[IsReadOnly]
class GetIncome extends Tool
{
    protected string $name = 'get_income';

    public function handle(Request $request): Response
    {
        $income = Income::findOrFail($request->get('income_id'));

        // Vérifie que le bien parent appartient à l'utilisateur (via BelongsToUserScope)
        $property = Property::findOrFail($income->property_id);

        return Response::json([
            'id'                => $income->id,
            'property_id'       => $income->property_id,
            'property_name'     => $property->name,
            'income_date'       => $income->income_date?->toDateString(),
            'source'            => $income->source,
            'source_label'      => (Income::sourceLabels()[$income->source] ?? $income->source),
            'amount_eur'        => $income->amount_euros,
            'amount_ht_eur'     => $income->amount_ht_euros,
            'tva_rate'          => $income->tva_rate,
            'tva_collected_eur' => $income->tva_collected_euros,
            'platform_fee_eur'  => bcdiv((string) $income->platform_fee, '100', 2),
            'tourist_tax_eur'   => bcdiv((string) $income->tourist_tax, '100', 2),
            'net_amount_eur'    => bcdiv((string) $income->net_amount, '100', 2),
            'reservation_ref'   => $income->reservation_ref,
            'guest_name'        => $income->guest_name,
            'checkin_date'      => $income->checkin_date?->toDateString(),
            'checkout_date'     => $income->checkout_date?->toDateString(),
            'notes'             => $income->notes ?? null,
            'created_at'        => $income->created_at?->toDateTimeString(),
            'updated_at'        => $income->updated_at?->toDateTimeString(),
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'income_id' => $schema->integer('Identifiant de la recette')->required(),
        ];
    }
}
