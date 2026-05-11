<?php

namespace App\Mcp\Tools;

use App\Models\Property;
use App\Models\PropertyWork;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[Description('Met à jour un poste de travaux immobiliers existant. Seuls les champs fournis sont modifiés (mise à jour partielle). Le montant est en euros. L\'amortissement annuel est recalculé automatiquement si le montant ou la durée changent.')]
#[IsIdempotent]
class UpdatePropertyWork extends Tool
{
    protected string $name = 'update_property_work';

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'work_id'        => 'required|integer',
            'description'    => 'nullable|string|max:500',
            'amount'         => 'nullable|numeric|min:0',
            'work_date'      => 'nullable|date_format:Y-m-d',
            'duration_years' => 'nullable|integer|min:1|max:50',
            'is_dedicated'   => 'nullable|boolean',
            'tva_rate'       => 'nullable|numeric|min:0|max:100',
        ]);

        $work = PropertyWork::findOrFail($validated['work_id']);

        // Verify ownership via scoped property
        Property::findOrFail($work->property_id);

        $updates = [];

        if (array_key_exists('description', $validated) && $validated['description'] !== null) {
            $updates['description'] = $validated['description'];
        }

        if (array_key_exists('amount', $validated) && $validated['amount'] !== null) {
            $updates['amount'] = (int) bcmul((string) $validated['amount'], '100', 0);
        }

        if (array_key_exists('work_date', $validated) && $validated['work_date'] !== null) {
            $updates['work_date'] = $validated['work_date'];
        }

        if (array_key_exists('duration_years', $validated) && $validated['duration_years'] !== null) {
            $updates['duration_years'] = $validated['duration_years'];
        }

        if (array_key_exists('is_dedicated', $validated) && $validated['is_dedicated'] !== null) {
            $updates['is_dedicated'] = $validated['is_dedicated'];
        }

        if (array_key_exists('tva_rate', $validated) && $validated['tva_rate'] !== null) {
            $updates['tva_rate'] = (int) bcmul((string) $validated['tva_rate'], '100', 0);
        }

        $work->update($updates);
        $work->refresh();

        return Response::json([
            'success'                 => true,
            'work_id'                 => $work->id,
            'property_id'             => $work->property_id,
            'description'             => $work->description,
            'work_date'               => $work->work_date->toDateString(),
            'amount_eur'              => $work->amount_euros,
            'amount_ht_eur'           => $work->amount_ht_euros,
            'amount_tva_eur'          => $work->amount_tva_euros,
            'duration_years'          => $work->duration_years,
            'annual_depreciation_eur' => $work->annual_depreciation_euros,
            'is_dedicated'            => $work->is_dedicated,
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'work_id'        => $schema->integer('Identifiant des travaux à mettre à jour')->required(),
            'description'    => $schema->string('Nouvelle description')->nullable(),
            'amount'         => $schema->number('Montant TTC en euros')->nullable(),
            'work_date'      => $schema->string('Date des travaux au format Y-m-d')->nullable(),
            'duration_years' => $schema->integer('Durée d\'amortissement en années')->nullable(),
            'is_dedicated'   => $schema->boolean('Travaux 100% dédiés à la location')->nullable(),
            'tva_rate'       => $schema->number('Taux de TVA en pourcentage (ex: 20 pour 20%)')->nullable(),
        ];
    }
}
