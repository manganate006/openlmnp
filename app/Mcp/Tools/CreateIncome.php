<?php

namespace App\Mcp\Tools;

use App\Models\Income;
use App\Models\Property;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[Description('Crée un revenu locatif pour un bien LMNP. Le montant est en euros (ex: 125.50). La source peut être : airbnb, booking, abritel, direct, other.')]
#[IsDestructive]
class CreateIncome extends Tool
{
    protected string $name = 'create_income';

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'property_id'     => 'required|integer',
            'income_date'     => 'required|date_format:Y-m-d',
            'amount'          => 'required|numeric|min:0',
            'source'          => 'required|in:airbnb,booking,abritel,direct,other',
            'guest_name'      => 'nullable|string|max:255',
            'reservation_ref' => 'nullable|string|max:255',
            'checkin_date'    => 'nullable|date_format:Y-m-d',
            'checkout_date'   => 'nullable|date_format:Y-m-d',
            'platform_fee'    => 'nullable|numeric|min:0',
            'tourist_tax'     => 'nullable|numeric|min:0',
            'tva_rate'        => 'nullable|numeric|min:0|max:100',
            'notes'           => 'nullable|string',
        ]);

        // Verify property belongs to the authenticated user (BelongsToUserScope active)
        $property = Property::findOrFail($validated['property_id']);

        // Convert euros to centimes via bcmul for precision
        $amountCents = (int) bcmul((string) $validated['amount'], '100', 0);

        $platformFeeCents = isset($validated['platform_fee'])
            ? (int) bcmul((string) $validated['platform_fee'], '100', 0)
            : 0;

        // tva_rate accepted as percentage (e.g. 20) → stored as basis points (e.g. 2000)
        $tvaRate = isset($validated['tva_rate'])
            ? (int) bcmul((string) $validated['tva_rate'], '100', 0)
            : 0;

        $income = Income::create([
            'property_id'     => $property->id,
            'income_date'     => $validated['income_date'],
            'amount'          => $amountCents,
            'tva_rate'        => $tvaRate,
            'platform_fee'    => $platformFeeCents,
            'tourist_tax'     => isset($validated['tourist_tax'])
                ? (int) bcmul((string) $validated['tourist_tax'], '100', 0)
                : 0,
            'source'          => $validated['source'],
            'reservation_ref' => $validated['reservation_ref'] ?? null,
            'guest_name'      => $validated['guest_name'] ?? null,
            'checkin_date'    => $validated['checkin_date'] ?? null,
            'checkout_date'   => $validated['checkout_date'] ?? null,
            'notes'           => $validated['notes'] ?? null,
        ]);

        return Response::json([
            'success'          => true,
            'income_id'        => $income->id,
            'property_id'      => $income->property_id,
            'income_date'      => $income->income_date->toDateString(),
            'amount_eur'       => $income->amount_euros,
            'amount_ht_eur'    => $income->amount_ht_euros,
            'tva_collected_eur'=> $income->tva_collected_euros,
            'platform_fee_eur' => bcdiv((string) $income->platform_fee, '100', 2),
            'tourist_tax_cts'  => $income->tourist_tax,
            'source'           => $income->source,
            'guest_name'       => $income->guest_name,
            'reservation_ref'  => $income->reservation_ref,
            'checkin_date'     => $income->checkin_date?->toDateString(),
            'checkout_date'    => $income->checkout_date?->toDateString(),
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'property_id'     => $schema->integer('Identifiant du bien immobilier')->required(),
            'income_date'     => $schema->string('Date du revenu au format Y-m-d (ex: 2025-03-15)')->required(),
            'amount'          => $schema->number('Montant TTC en euros (ex: 125.50)')->required(),
            'source'          => $schema->string('Plateforme source : airbnb, booking, abritel, direct, other')->required(),
            'guest_name'      => $schema->string('Nom du voyageur')->nullable(),
            'reservation_ref' => $schema->string('Référence de réservation')->nullable(),
            'checkin_date'    => $schema->string('Date d\'arrivée au format Y-m-d')->nullable(),
            'checkout_date'   => $schema->string('Date de départ au format Y-m-d')->nullable(),
            'platform_fee'    => $schema->number('Commission plateforme en euros')->nullable(),
            'tourist_tax'     => $schema->number('Taxe de séjour en euros (ex: 2.50)')->nullable(),
            'tva_rate'        => $schema->number('Taux de TVA en pourcentage (ex: 20 pour 20%, 0 pour exonéré)')->nullable(),
            'notes'           => $schema->string('Notes libres')->nullable(),
        ];
    }
}
