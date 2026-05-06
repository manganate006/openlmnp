<?php

namespace App\Mcp\Tools;

use App\Services\TvaDeclarationService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('Calcule la déclaration de TVA pour les biens para-hôteliers assujettis (régime TVA liable) sur une année donnée. Retourne la TVA collectée, déductible et le solde à payer ou à récupérer, ventilés par bien, par taux et par trimestre. Tous les montants sont en euros.')]
#[IsReadOnly]
class ComputeTva extends Tool
{
    protected string $name = 'compute_tva';

    public function __construct(private TvaDeclarationService $tvaDeclarationService) {}

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'year' => 'required|integer|min:2000|max:2099',
        ]);

        $user = $request->user();
        $year = (int) $validated['year'];

        $raw = $this->tvaDeclarationService->calculate($user, $year);

        // Conversion centimes → euros pour les propriétés
        $properties = array_map(function (array $prop) {
            $convertAmounts = function (array $amounts): array {
                return [
                    'collected_eur'  => bcdiv((string) ($amounts['collected'] ?? 0), '100', 2),
                    'deductible_eur' => bcdiv((string) ($amounts['deductible'] ?? 0), '100', 2),
                    'balance_eur'    => bcdiv(
                        bcsub((string) ($amounts['collected'] ?? 0), (string) ($amounts['deductible'] ?? 0), 0),
                        '100',
                        2
                    ),
                ];
            };

            $byRate = [];
            foreach ($prop['by_rate'] ?? [] as $rate => $amounts) {
                $byRate[(string) $rate] = $convertAmounts($amounts);
            }

            $quarters = [];
            foreach ($prop['quarters'] ?? [] as $q => $amounts) {
                $quarters["Q{$q}"] = $convertAmounts($amounts);
            }

            return [
                'property_id'   => $prop['property_id'] ?? null,
                'property_name' => $prop['property_name'] ?? null,
                'collected_eur'  => bcdiv((string) ($prop['collected'] ?? 0), '100', 2),
                'deductible_eur' => bcdiv((string) ($prop['deductible'] ?? 0), '100', 2),
                'balance_eur'    => bcdiv(
                    bcsub((string) ($prop['collected'] ?? 0), (string) ($prop['deductible'] ?? 0), 0),
                    '100',
                    2
                ),
                'by_rate'   => $byRate,
                'quarters'  => $quarters,
            ];
        }, $raw['properties'] ?? []);

        // Totaux
        $totals = $raw['totals'] ?? [];
        $totalCollected  = (int) ($totals['collected'] ?? 0);
        $totalDeductible = (int) ($totals['deductible'] ?? 0);
        $totalBalance    = (int) ($totals['balance'] ?? bcsub((string) $totalCollected, (string) $totalDeductible, 0));

        // Par taux
        $byRate = [];
        foreach ($raw['by_rate'] ?? [] as $rate => $amounts) {
            $byRate[(string) $rate] = [
                'collected_eur'  => bcdiv((string) ($amounts['collected'] ?? 0), '100', 2),
                'deductible_eur' => bcdiv((string) ($amounts['deductible'] ?? 0), '100', 2),
            ];
        }

        // Par trimestre
        $quarters = [];
        foreach ($raw['quarters'] ?? [] as $q => $amounts) {
            $quarters["Q{$q}"] = [
                'collected_eur'  => bcdiv((string) ($amounts['collected'] ?? 0), '100', 2),
                'deductible_eur' => bcdiv((string) ($amounts['deductible'] ?? 0), '100', 2),
                'balance_eur'    => bcdiv(
                    bcsub((string) ($amounts['collected'] ?? 0), (string) ($amounts['deductible'] ?? 0), 0),
                    '100',
                    2
                ),
            ];
        }

        return Response::json([
            'year'       => $year,
            'properties' => $properties,

            'totals' => [
                'collected_eur'  => bcdiv((string) $totalCollected, '100', 2),
                'deductible_eur' => bcdiv((string) $totalDeductible, '100', 2),
                'balance_eur'    => bcdiv((string) $totalBalance, '100', 2),
                'balance_label'  => $totalBalance >= 0
                    ? 'TVA à reverser au Trésor'
                    : 'Crédit de TVA à récupérer',
            ],

            'by_rate'  => $byRate,
            'quarters' => $quarters,
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'year' => $schema->integer('Année fiscale pour la déclaration TVA (ex : 2025)')->required(),
        ];
    }
}
