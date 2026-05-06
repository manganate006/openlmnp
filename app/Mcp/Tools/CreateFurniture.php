<?php

namespace App\Mcp\Tools;

use App\Models\Furniture;
use App\Models\Property;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[Description('Crée un élément de mobilier amortissable pour un bien LMNP. Le montant est en euros. La durée d\'amortissement est de 5 ans par défaut (3 ans pour le mobilier d\'occasion). L\'amortissement annuel est calculé automatiquement.')]
#[IsDestructive]
class CreateFurniture extends Tool
{
    protected string $name = 'create_furniture';

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'property_id'    => 'required|integer',
            'description'    => 'required|string|max:500',
            'amount'         => 'required|numeric|min:0',
            'purchase_date'  => 'required|date_format:Y-m-d',
            'duration_years' => 'nullable|integer|min:1|max:50',
            'is_dedicated'   => 'nullable|boolean',
            'is_second_hand' => 'nullable|boolean',
            'tva_rate'       => 'nullable|numeric|min:0|max:100',
        ]);

        // Verify property belongs to the authenticated user (BelongsToUserScope active)
        $property = Property::findOrFail($validated['property_id']);

        $isSecondHand = $validated['is_second_hand'] ?? false;

        // Default duration: 3 years for second-hand, 5 years for new
        $durationYears = $validated['duration_years']
            ?? ($isSecondHand ? Furniture::DURATION_SECOND_HAND : Furniture::DURATION_NEW);

        $amountCents = (int) bcmul((string) $validated['amount'], '100', 0);

        // Convert percentage to basis points (20% → 2000)
        $tvaRate = isset($validated['tva_rate'])
            ? (int) bcmul((string) $validated['tva_rate'], '100', 0)
            : 0;

        $furniture = Furniture::create([
            'property_id'    => $property->id,
            'description'    => $validated['description'],
            'amount'         => $amountCents,
            'tva_rate'       => $tvaRate,
            'purchase_date'  => $validated['purchase_date'],
            'duration_years' => $durationYears,
            'is_dedicated'   => $validated['is_dedicated'] ?? false,
            'is_second_hand' => $isSecondHand,
        ]);

        return Response::json([
            'success'                   => true,
            'furniture_id'              => $furniture->id,
            'property_id'               => $furniture->property_id,
            'description'               => $furniture->description,
            'purchase_date'             => $furniture->purchase_date->toDateString(),
            'amount_eur'                => $furniture->amount_euros,
            'amount_ht_eur'             => $furniture->amount_ht_euros,
            'amount_tva_eur'            => $furniture->amount_tva_euros,
            'duration_years'            => $furniture->duration_years,
            'annual_depreciation_eur'   => $furniture->annual_depreciation_euros,
            'is_dedicated'              => $furniture->is_dedicated,
            'is_second_hand'            => $furniture->is_second_hand,
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'property_id'    => $schema->integer('Identifiant du bien immobilier')->required(),
            'description'    => $schema->string('Description du mobilier (ex: Canapé convertible, Lave-vaisselle)')->required(),
            'amount'         => $schema->number('Montant TTC en euros (ex: 850.00)')->required(),
            'purchase_date'  => $schema->string('Date d\'achat au format Y-m-d (ex: 2025-03-15)')->required(),
            'duration_years' => $schema->integer('Durée d\'amortissement en années (défaut : 5 ans neuf, 3 ans occasion)')->nullable(),
            'is_dedicated'   => $schema->boolean('Mobilier 100% dédié à la location (true) ou au prorata de la quote-part (false, défaut)')->nullable(),
            'is_second_hand' => $schema->boolean('Mobilier d\'occasion (true) — durée réduite à 3 ans par défaut')->nullable(),
            'tva_rate'       => $schema->number('Taux de TVA en pourcentage (ex: 20 pour 20%, 0 pour exonéré)')->nullable(),
        ];
    }
}
