<?php

namespace App\Mcp\Tools;

use App\Services\FiscalYearService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('Compare le régime micro-BIC et le régime réel pour une année fiscale. Calcule le résultat imposable dans chaque régime et indique lequel est le plus avantageux. L\'abattement micro-BIC est de 50 % pour les meublés classés, 30 % pour les non classés (loi Le Meur 2026). Tous les montants sont en euros.')]
#[IsReadOnly]
class CompareMicroBic extends Tool
{
    protected string $name = 'compare_micro_bic';

    public function __construct(private FiscalYearService $fiscalYearService) {}

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'year' => 'required|integer|min:2000|max:2099',
            'abatement_rate' => 'nullable|numeric|min:0|max:100',
        ]);

        $user = $request->user();
        $year = (int) $validated['year'];
        $abatementRate = isset($validated['abatement_rate'])
            ? (string) $validated['abatement_rate']
            : '50';

        $comparison = $this->fiscalYearService->compareMicroBicVsReal($user, $year, $abatementRate);

        // Montants intermédiaires utiles pour l'affichage
        $grossIncomeEur   = bcdiv($comparison['gross_income'], '100', 2);
        $abatementAmtEur  = bcdiv(
            bcmul($comparison['gross_income'], bcdiv($abatementRate, '100', 10), 0),
            '100',
            2
        );
        $microBicResultEur = bcdiv($comparison['micro_bic_result'], '100', 2);
        $realResultEur     = bcdiv($comparison['real_result'], '100', 2);
        $advantageEur      = bcdiv($comparison['advantage'], '100', 2);

        // Récupérer le détail du régime réel pour enrichir la réponse
        $fiscalYear = \App\Models\FiscalYear::where('year', $year)->first();

        return Response::json([
            'year'            => $year,
            'abatement_rate'  => (float) $abatementRate,

            'micro_bic' => [
                'gross_income_eur'  => $grossIncomeEur,
                'abatement_eur'     => $abatementAmtEur,
                'taxable_result_eur' => $microBicResultEur,
                'note'              => 'CA brut (sans déduction commissions plateforme) × (1 - abattement)',
            ],

            'real_regime' => [
                'total_income_eur'          => $fiscalYear ? bcdiv((string) $fiscalYear->total_income, '100', 2) : null,
                'total_expenses_eur'        => $fiscalYear ? bcdiv((string) $fiscalYear->total_expenses, '100', 2) : null,
                'capped_depreciation_eur'   => $fiscalYear ? bcdiv((string) $fiscalYear->capped_depreciation, '100', 2) : null,
                'deferred_depreciation_eur' => $fiscalYear ? bcdiv((string) $fiscalYear->deferred_depreciation, '100', 2) : null,
                'taxable_result_eur'        => $realResultEur,
            ],

            // Avantage positif = le micro-BIC est plus imposant → le réel est préférable
            'advantage_real_vs_micro_eur' => $advantageEur,
            'recommended'                 => $comparison['recommended'],
            'recommendation_label'        => $comparison['recommended'] === 'real'
                ? 'Régime réel recommandé (résultat fiscal plus faible)'
                : 'Micro-BIC recommandé (abattement forfaitaire plus avantageux)',
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'year'           => $schema->integer('Année fiscale (ex : 2025)')->required(),
            'abatement_rate' => $schema->number('Taux d\'abattement micro-BIC en % (50 pour classé, 30 pour non classé). Défaut : 50'),
        ];
    }
}
