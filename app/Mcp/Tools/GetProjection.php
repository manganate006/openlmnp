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

#[Description('Calcule une projection pluriannuelle du résultat fiscal LMNP sur plusieurs années. Pour chaque année : revenus estimés, charges, amortissements (bâti + travaux + mobilier), plafonnement, report cumulé, résultat fiscal net, comparaison micro-BIC. Tous les montants sont en euros.')]
#[IsReadOnly]
class GetProjection extends Tool
{
    protected string $name = 'get_projection';

    public function __construct(private DepreciationService $depreciationService) {}

    public function handle(Request $request): Response
    {
        $startYear     = (int) ($request->get('start_year') ?? date('Y'));
        $years         = min((int) ($request->get('years') ?? 5), 15);
        $incomeGrowth  = (float) ($request->get('income_growth') ?? 0);
        $expenseGrowth = (float) ($request->get('expense_growth') ?? 0);

        if ($startYear < 2000 || $startYear > 2099) {
            return Response::error('start_year doit être entre 2000 et 2099.');
        }
        if ($years < 1) {
            return Response::error('years doit être ≥ 1.');
        }

        $properties = Property::all();
        if ($properties->isEmpty()) {
            return Response::json(['empty' => true, 'message' => 'Aucun bien enregistré.']);
        }

        $rows = [];
        $cumulativeDeferred = 0;

        for ($i = 0; $i < $years; $i++) {
            $year = $startYear + $i;

            $totalDepreciation = 0;
            $depDetails = ['building' => 0, 'works' => 0, 'furniture' => 0];

            foreach ($properties as $property) {
                $dep = $this->depreciationService->calculateAnnualDepreciation($property, $year);
                $totalDepreciation += (int) $dep['total'];
                $depDetails['building'] += (int) $dep['building'];
                $depDetails['works'] += (int) $dep['works'];
                $depDetails['furniture'] += (int) $dep['furniture'];
            }

            $totalIncome   = 0;
            $totalExpenses = 0;

            foreach ($properties as $property) {
                $income = $property->incomes()->whereYear('income_date', $year)->sum('amount');
                if ($income == 0 && $i > 0) {
                    $income = $property->incomes()->sum('amount');
                    $incomeYears = $property->incomes()->selectRaw('COUNT(DISTINCT strftime("%Y", income_date)) as y')->value('y') ?: 1;
                    $income = (int) ($income / $incomeYears);
                }
                $totalIncome += $income;

                $dedicated = $property->expenses()->whereYear('expense_date', $year)->where('is_dedicated', true)->sum('amount');
                $shared    = $property->expenses()->whereYear('expense_date', $year)->where('is_dedicated', false)->sum('amount');
                if ($dedicated == 0 && $shared == 0 && $i > 0) {
                    $dedicated = $property->expenses()->where('is_dedicated', true)->sum('amount');
                    $shared    = $property->expenses()->where('is_dedicated', false)->sum('amount');
                    $expYears  = $property->expenses()->selectRaw('COUNT(DISTINCT strftime("%Y", expense_date)) as y')->value('y') ?: 1;
                    $dedicated = (int) ($dedicated / $expYears);
                    $shared    = (int) ($shared / $expYears);
                }
                $totalExpenses += $dedicated + (int) bcmul((string) $shared, $property->quota_share, 0);
            }

            if ($i > 0 && $incomeGrowth != 0) {
                $totalIncome = (int) ($totalIncome * pow(1 + $incomeGrowth / 100, $i));
            }
            if ($i > 0 && $expenseGrowth != 0) {
                $totalExpenses = (int) ($totalExpenses * pow(1 + $expenseGrowth / 100, $i));
            }

            $resultBefore = $totalIncome - $totalExpenses;
            $available    = $totalDepreciation + $cumulativeDeferred;

            if ($resultBefore <= 0) {
                $capped = 0;
                $cumulativeDeferred = $available;
            } elseif ($available <= $resultBefore) {
                $capped = $available;
                $cumulativeDeferred = 0;
            } else {
                $capped = $resultBefore;
                $cumulativeDeferred = $available - $capped;
            }

            $fiscalResult = max(0, $resultBefore - $capped);
            $microBic50   = (int) ($totalIncome * 0.50);
            $microBic30   = (int) ($totalIncome * 0.70);

            $rows[] = [
                'year'            => $year,
                'income_euros'    => round($totalIncome / 100, 2),
                'expenses_euros'  => round($totalExpenses / 100, 2),
                'dep_building_euros'  => round($depDetails['building'] / 100, 2),
                'dep_works_euros'     => round($depDetails['works'] / 100, 2),
                'dep_furniture_euros' => round($depDetails['furniture'] / 100, 2),
                'dep_total_euros'     => round($totalDepreciation / 100, 2),
                'capped_euros'    => round($capped / 100, 2),
                'deferred_euros'  => round($cumulativeDeferred / 100, 2),
                'fiscal_result_euros' => round($fiscalResult / 100, 2),
                'micro_bic_50_euros'  => round($microBic50 / 100, 2),
                'micro_bic_30_euros'  => round($microBic30 / 100, 2),
                'recommended'     => $fiscalResult < $microBic50 ? 'real' : 'micro_bic',
            ];
        }

        return Response::json([
            'empty'         => false,
            'start_year'    => $startYear,
            'years'         => $years,
            'income_growth' => $incomeGrowth,
            'expense_growth' => $expenseGrowth,
            'properties_count' => $properties->count(),
            'rows'          => $rows,
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            $schema->integer('start_year')->description('Année de départ de la projection (défaut : année courante)'),
            $schema->integer('years')->description('Nombre d\'années à projeter, entre 1 et 15 (défaut : 5)')->default(5),
            $schema->number('income_growth')->description('Taux de croissance annuel des revenus en % (ex : 2 pour +2%/an)')->default(0),
            $schema->number('expense_growth')->description('Taux de croissance annuel des charges en % (ex : 3 pour +3%/an)')->default(0),
        ];
    }
}
