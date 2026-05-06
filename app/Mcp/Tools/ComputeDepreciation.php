<?php

namespace App\Mcp\Tools;

use App\Models\Property;
use App\Services\DepreciationService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('Calcule le détail des amortissements LMNP pour un bien immobilier et une année donnée. Retourne la ventilation par composant immeuble, travaux et mobilier, ainsi que le total annuel, tous les montants exprimés en euros.')]
#[IsReadOnly]
class ComputeDepreciation extends Tool
{
    protected string $name = 'compute_depreciation';

    public function __construct(private DepreciationService $depreciationService) {}

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'property_id' => 'required|integer',
            'year' => 'required|integer|min:2000|max:2099',
        ]);

        $property = Property::with(['components', 'works', 'furniture'])->findOrFail($validated['property_id']);
        $year = (int) $validated['year'];

        $raw = $this->depreciationService->calculateAnnualDepreciation($property, $year);

        // Conversion centimes → euros pour chaque détail
        $details = array_map(function (array $item) {
            return [
                'type'      => $item['type'],
                'name'      => $item['name'],
                'amount_eur' => bcdiv((string) $item['amount'], '100', 2),
            ];
        }, $raw['details']);

        // Ventilation par catégorie
        $byCategory = [
            'building'  => [],
            'works'     => [],
            'furniture' => [],
        ];
        foreach ($details as $detail) {
            $cat = match ($detail['type']) {
                'work'      => 'works',
                'furniture' => 'furniture',
                default     => 'building',
            };
            $byCategory[$cat][] = $detail;
        }

        return Response::json([
            'property_id'   => $property->id,
            'property_name' => $property->name,
            'year'          => $year,
            'breakdown' => [
                'building' => [
                    'total_eur' => bcdiv((string) $raw['building'], '100', 2),
                    'items'     => $byCategory['building'],
                ],
                'works' => [
                    'total_eur' => bcdiv((string) $raw['works'], '100', 2),
                    'items'     => $byCategory['works'],
                ],
                'furniture' => [
                    'total_eur' => bcdiv((string) $raw['furniture'], '100', 2),
                    'items'     => $byCategory['furniture'],
                ],
            ],
            'total_eur' => bcdiv((string) $raw['total'], '100', 2),
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'property_id' => $schema->integer('Identifiant du bien immobilier')->required(),
            'year'        => $schema->integer('Année fiscale (ex : 2025)')->required(),
        ];
    }
}
