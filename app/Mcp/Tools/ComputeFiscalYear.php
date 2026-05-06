<?php

namespace App\Mcp\Tools;

use App\Models\FiscalYear;
use App\Services\FiscalYearService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[Description('Calcule le résultat fiscal complet d\'un exercice LMNP pour une année donnée : recettes, charges, amortissements (avec plafonnement et report), résultat fiscal net, et solde TVA. Crée l\'exercice s\'il n\'existe pas encore. Tous les montants sont retournés en euros.')]
#[IsIdempotent]
class ComputeFiscalYear extends Tool
{
    protected string $name = 'compute_fiscal_year';

    public function __construct(private FiscalYearService $fiscalYearService) {}

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'year' => 'required|integer|min:2000|max:2099',
        ]);

        $user = $request->user();
        $year = (int) $validated['year'];

        // Crée ou récupère l'exercice et (re)calcule tous les totaux
        $fiscalYear = $this->fiscalYearService->getOrCreate($user, $year);

        return Response::json([
            'year'   => $fiscalYear->year,
            'status' => $fiscalYear->status,

            // Recettes
            'total_income_eur' => bcdiv((string) $fiscalYear->total_income, '100', 2),

            // Charges (hors amortissements)
            'total_expenses_eur' => bcdiv((string) $fiscalYear->total_expenses, '100', 2),

            // Résultat avant amortissement
            'result_before_depreciation_eur' => bcdiv(
                bcsub((string) $fiscalYear->total_income, (string) $fiscalYear->total_expenses, 0),
                '100',
                2
            ),

            // Amortissements
            'total_depreciation_eur'   => bcdiv((string) $fiscalYear->total_depreciation, '100', 2),
            'previous_deferred_eur'    => bcdiv((string) ($fiscalYear->previous_deferred ?? 0), '100', 2),
            'capped_depreciation_eur'  => bcdiv((string) $fiscalYear->capped_depreciation, '100', 2),
            'deferred_depreciation_eur' => bcdiv((string) $fiscalYear->deferred_depreciation, '100', 2),

            // Résultat fiscal (0 au minimum grâce au plafonnement)
            'fiscal_result_eur' => bcdiv((string) $fiscalYear->fiscal_result, '100', 2),

            // TVA (biens para-hôteliers uniquement)
            'tva' => [
                'collected_eur'  => bcdiv((string) ($fiscalYear->total_tva_collected ?? 0), '100', 2),
                'deductible_eur' => bcdiv((string) ($fiscalYear->total_tva_deductible ?? 0), '100', 2),
                'balance_eur'    => bcdiv((string) ($fiscalYear->tva_balance ?? 0), '100', 2),
            ],

            'updated_at' => $fiscalYear->updated_at?->toDateTimeString(),
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'year' => $schema->integer('Année fiscale à calculer (ex : 2025)')->required(),
        ];
    }
}
