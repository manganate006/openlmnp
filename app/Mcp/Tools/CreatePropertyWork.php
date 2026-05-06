<?php

namespace App\Mcp\Tools;

use App\Models\Property;
use App\Models\PropertyWork;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[Description('Crée un poste de travaux immobiliers amortissables pour un bien LMNP. Le montant est en euros. La durée d\'amortissement est de 10 ans par défaut. L\'amortissement annuel est calculé automatiquement en tenant compte de la quote-part si le poste n\'est pas dédié.')]
#[IsDestructive]
class CreatePropertyWork extends Tool
{
    protected string $name = 'create_property_work';

    /** Durée d'amortissement par défaut des travaux immobiliers (années) */
    private const DEFAULT_DURATION_YEARS = 10;

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'property_id'    => 'required|integer',
            'description'    => 'required|string|max:500',
            'amount'         => 'required|numeric|min:0',
            'work_date'      => 'required|date_format:Y-m-d',
            'duration_years' => 'nullable|integer|min:1|max:50',
            'is_dedicated'   => 'nullable|boolean',
            'tva_rate'       => 'nullable|numeric|min:0|max:100',
        ]);

        // Verify property belongs to the authenticated user (BelongsToUserScope active)
        $property = Property::findOrFail($validated['property_id']);

        $amountCents = (int) bcmul((string) $validated['amount'], '100', 0);

        // Convert percentage to basis points (20% → 2000)
        $tvaRate = isset($validated['tva_rate'])
            ? (int) bcmul((string) $validated['tva_rate'], '100', 0)
            : 0;

        $work = PropertyWork::create([
            'property_id'    => $property->id,
            'description'    => $validated['description'],
            'amount'         => $amountCents,
            'tva_rate'       => $tvaRate,
            'work_date'      => $validated['work_date'],
            'duration_years' => $validated['duration_years'] ?? self::DEFAULT_DURATION_YEARS,
            'is_dedicated'   => $validated['is_dedicated'] ?? false,
        ]);

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
            'property_id'    => $schema->integer('Identifiant du bien immobilier')->required(),
            'description'    => $schema->string('Description des travaux (ex: Réfection de la salle de bain, Installation cuisine)')->required(),
            'amount'         => $schema->number('Montant TTC en euros (ex: 12500.00)')->required(),
            'work_date'      => $schema->string('Date des travaux au format Y-m-d (ex: 2025-06-01)')->required(),
            'duration_years' => $schema->integer('Durée d\'amortissement en années (défaut : 10 ans)')->nullable(),
            'is_dedicated'   => $schema->boolean('Travaux 100% dédiés à la location (true) ou au prorata de la quote-part (false, défaut)')->nullable(),
            'tva_rate'       => $schema->number('Taux de TVA en pourcentage (ex: 20 pour 20%, 10 pour taux réduit travaux, 0 pour exonéré)')->nullable(),
        ];
    }
}
