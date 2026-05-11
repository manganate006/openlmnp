<?php

namespace App\Mcp\Tools;

use App\Models\Furniture;
use App\Models\Property;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[Description('Met à jour un élément de mobilier existant. Seuls les champs fournis sont modifiés (mise à jour partielle). Le montant est en euros. L\'amortissement annuel est recalculé automatiquement si le montant ou la durée changent.')]
#[IsIdempotent]
class UpdateFurniture extends Tool
{
    protected string $name = 'update_furniture';

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'furniture_id'   => 'required|integer',
            'description'    => 'nullable|string|max:500',
            'amount'         => 'nullable|numeric|min:0',
            'purchase_date'  => 'nullable|date_format:Y-m-d',
            'duration_years' => 'nullable|integer|min:1|max:50',
            'is_dedicated'   => 'nullable|boolean',
            'is_second_hand' => 'nullable|boolean',
            'tva_rate'       => 'nullable|numeric|min:0|max:100',
        ]);

        $furniture = Furniture::findOrFail($validated['furniture_id']);

        // Verify ownership via scoped property
        Property::findOrFail($furniture->property_id);

        $updates = [];

        if (array_key_exists('description', $validated) && $validated['description'] !== null) {
            $updates['description'] = $validated['description'];
        }

        if (array_key_exists('amount', $validated) && $validated['amount'] !== null) {
            $updates['amount'] = (int) bcmul((string) $validated['amount'], '100', 0);
        }

        if (array_key_exists('purchase_date', $validated) && $validated['purchase_date'] !== null) {
            $updates['purchase_date'] = $validated['purchase_date'];
        }

        if (array_key_exists('duration_years', $validated) && $validated['duration_years'] !== null) {
            $updates['duration_years'] = $validated['duration_years'];
        }

        if (array_key_exists('is_dedicated', $validated) && $validated['is_dedicated'] !== null) {
            $updates['is_dedicated'] = $validated['is_dedicated'];
        }

        if (array_key_exists('is_second_hand', $validated) && $validated['is_second_hand'] !== null) {
            $updates['is_second_hand'] = $validated['is_second_hand'];
        }

        if (array_key_exists('tva_rate', $validated) && $validated['tva_rate'] !== null) {
            $updates['tva_rate'] = (int) bcmul((string) $validated['tva_rate'], '100', 0);
        }

        $furniture->update($updates);
        $furniture->refresh();

        return Response::json([
            'success'                 => true,
            'furniture_id'            => $furniture->id,
            'property_id'             => $furniture->property_id,
            'description'             => $furniture->description,
            'purchase_date'           => $furniture->purchase_date->toDateString(),
            'amount_eur'              => $furniture->amount_euros,
            'amount_ht_eur'           => $furniture->amount_ht_euros,
            'amount_tva_eur'          => $furniture->amount_tva_euros,
            'duration_years'          => $furniture->duration_years,
            'annual_depreciation_eur' => $furniture->annual_depreciation_euros,
            'is_dedicated'            => $furniture->is_dedicated,
            'is_second_hand'          => $furniture->is_second_hand,
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'furniture_id'   => $schema->integer('Identifiant du mobilier à mettre à jour')->required(),
            'description'    => $schema->string('Nouvelle description')->nullable(),
            'amount'         => $schema->number('Montant TTC en euros')->nullable(),
            'purchase_date'  => $schema->string('Date d\'achat au format Y-m-d')->nullable(),
            'duration_years' => $schema->integer('Durée d\'amortissement en années')->nullable(),
            'is_dedicated'   => $schema->boolean('Mobilier 100% dédié à la location')->nullable(),
            'is_second_hand' => $schema->boolean('Mobilier d\'occasion')->nullable(),
            'tva_rate'       => $schema->number('Taux de TVA en pourcentage (ex: 20 pour 20%)')->nullable(),
        ];
    }
}
