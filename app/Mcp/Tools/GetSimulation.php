<?php

namespace App\Mcp\Tools;

use App\Models\Property;
use App\Services\DepreciationService;
use App\Services\FiscalYearService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Auth;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('Compare le régime micro-BIC et le régime réel pour une année donnée. Retourne : revenus bruts, résultats micro-BIC (50% ou 30% selon classement), résultat réel (après charges et amortissements), avantage fiscal, recommandation, et économie d\'impôt estimée pour différentes TMI. Tous les montants sont en euros.')]
#[IsReadOnly]
class GetSimulation extends Tool
{
    protected string $name = 'get_simulation';

    public function __construct(
        private FiscalYearService $fiscalYearService,
        private DepreciationService $depreciationService,
    ) {}

    public function handle(Request $request): Response
    {
        $year      = (int) ($request->get('year') ?? date('Y'));
        $abatement = $request->get('abatement', '50');

        if ($year < 2000 || $year > 2099) {
            return Response::error('year doit être entre 2000 et 2099.');
        }
        if (! in_array($abatement, ['50', '30'])) {
            return Response::error('abatement doit être "50" (meublé classé) ou "30" (non classé).');
        }

        $user       = Auth::user();
        $properties = Property::all();

        if ($properties->isEmpty()) {
            return Response::json(['empty' => true, 'message' => 'Aucun bien enregistré.']);
        }

        $comparison = $this->fiscalYearService->compareMicroBicVsReal($user, $year, $abatement);

        $depreciationDetails = [];
        foreach ($properties as $property) {
            $dep = $this->depreciationService->calculateAnnualDepreciation($property, $year);
            $depreciationDetails[$property->name] = [
                'building_euros'  => round((int) $dep['building'] / 100, 2),
                'works_euros'     => round((int) $dep['works'] / 100, 2),
                'furniture_euros' => round((int) $dep['furniture'] / 100, 2),
                'total_euros'     => round((int) $dep['total'] / 100, 2),
            ];
        }

        $totalExpensesDedicated = 0;
        $totalExpensesShared    = 0;
        foreach ($properties as $property) {
            $totalExpensesDedicated += $property->expenses()
                ->whereYear('expense_date', $year)
                ->where('is_dedicated', true)
                ->sum('amount');
            $shared = $property->expenses()
                ->whereYear('expense_date', $year)
                ->where('is_dedicated', false)
                ->sum('amount');
            $totalExpensesShared += (int) bcmul((string) $shared, $property->quota_share, 0);
        }

        $advantage = (int) $comparison['advantage'];

        return Response::json([
            'empty'          => false,
            'year'           => $year,
            'abatement'      => $abatement . '%',
            'gross_income_euros'      => round((int) $comparison['gross_income'] / 100, 2),
            'micro_bic_result_euros'  => round((int) $comparison['micro_bic_result'] / 100, 2),
            'real_result_euros'       => round((int) $comparison['real_result'] / 100, 2),
            'advantage_euros'         => round($advantage / 100, 2),
            'recommended'             => $comparison['recommended'],
            'expenses_dedicated_euros' => round($totalExpensesDedicated / 100, 2),
            'expenses_shared_euros'    => round($totalExpensesShared / 100, 2),
            'depreciation_by_property' => $depreciationDetails,
            'tax_saving_11pct_euros'  => round((int) bcmul((string) $advantage, '0.11', 0) / 100, 2),
            'tax_saving_30pct_euros'  => round((int) bcmul((string) $advantage, '0.30', 0) / 100, 2),
            'tax_saving_41pct_euros'  => round((int) bcmul((string) $advantage, '0.41', 0) / 100, 2),
            'ps_saving_euros'         => round((int) bcmul((string) $advantage, '0.186', 0) / 100, 2),
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'year'      => $schema->integer('Année fiscale à simuler (défaut : année courante)'),
            'abatement' => $schema->string('Abattement micro-BIC : "50" pour meublé classé, "30" pour non classé'),
        ];
    }
}
