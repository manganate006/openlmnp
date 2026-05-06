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

#[Description('Liste les recettes locatives de l\'utilisateur avec filtres optionnels par bien, année et plateforme (airbnb, booking, abritel, direct, other). Retourne les montants en euros avec TVA, frais de plateforme et taxe de séjour.')]
#[IsReadOnly]
class ListIncomes extends Tool
{
    protected string $name = 'list_incomes';

    public function handle(Request $request): Response
    {
        $propertyId = $request->get('property_id');
        $year       = $request->get('year');
        $source     = $request->get('source');

        // Restreindre aux biens de l'utilisateur (BelongsToUserScope actif)
        if ($propertyId !== null) {
            // Vérifie l'appartenance via le scope
            $property    = Property::findOrFail($propertyId);
            $propertyIds = collect([$property->id]);
        } else {
            $propertyIds = Property::pluck('id');
        }

        $query = Income::whereIn('property_id', $propertyIds)
            ->orderBy('income_date', 'desc');

        if ($year !== null) {
            $query->whereYear('income_date', $year);
        }

        if ($source !== null) {
            $query->where('source', $source);
        }

        $incomes = $query->get();

        $totalAmount    = $incomes->sum('amount');
        $totalAmountHt  = $incomes->sum('amount_ht');
        $totalPlatformFee = $incomes->sum('platform_fee');
        $totalTouristTax  = $incomes->sum('tourist_tax');
        $totalNetAmount   = $incomes->sum(fn ($i) => (int) $i->net_amount);

        $data = $incomes->map(function (Income $income) {
            return [
                'id'              => $income->id,
                'property_id'     => $income->property_id,
                'income_date'     => $income->income_date?->toDateString(),
                'source'          => $income->source,
                'source_label'    => (Income::sourceLabels()[$income->source] ?? $income->source),
                'amount_eur'      => $income->amount_euros,
                'amount_ht_eur'   => $income->amount_ht_euros,
                'tva_rate'        => $income->tva_rate,
                'tva_collected_eur' => $income->tva_collected_euros,
                'platform_fee_eur'  => bcdiv((string) $income->platform_fee, '100', 2),
                'tourist_tax_eur'   => bcdiv((string) $income->tourist_tax, '100', 2),
                'net_amount_eur'    => bcdiv((string) $income->net_amount, '100', 2),
                'reservation_ref'   => $income->reservation_ref,
                'guest_name'        => $income->guest_name,
                'checkin_date'      => $income->checkin_date?->toDateString(),
                'checkout_date'     => $income->checkout_date?->toDateString(),
            ];
        });

        return Response::json([
            'count'                    => $incomes->count(),
            'total_amount_eur'         => bcdiv((string) $totalAmount, '100', 2),
            'total_amount_ht_eur'      => bcdiv((string) $totalAmountHt, '100', 2),
            'total_platform_fees_eur'  => bcdiv((string) $totalPlatformFee, '100', 2),
            'total_tourist_tax_eur'    => bcdiv((string) $totalTouristTax, '100', 2),
            'total_net_amount_eur'     => bcdiv((string) $totalNetAmount, '100', 2),
            'incomes'                  => $data,
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'property_id' => $schema->integer('Filtrer par identifiant de bien (optionnel)'),
            'year'        => $schema->integer('Filtrer par année fiscale, ex: 2024 (optionnel)'),
            'source'      => $schema->string('Filtrer par plateforme : airbnb, booking, abritel, direct, other (optionnel)'),
        ];
    }
}
