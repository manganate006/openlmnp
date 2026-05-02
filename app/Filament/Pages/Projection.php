<?php

namespace App\Filament\Pages;

use App\Filament\Pages\Concerns\NavigationAware;
use App\Models\Property;
use App\Services\BadgeService;
use App\Services\DepreciationService;
use App\Services\FiscalYearService;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Livewire\Attributes\Computed;
use UnitEnum;

class Projection extends Page
{
    use NavigationAware;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;
    protected static string | UnitEnum | null $navigationGroup = 'Fiscal';
    protected static ?string $navigationLabel = 'Projection';
    protected static ?string $title = 'Projection pluriannuelle';
    protected static ?int $navigationSort = 3;

    protected static function isHiddenInSimpleMode(): bool
    {
        return true;
    }

    protected static function getGuidedNavigationGroup(): string
    {
        return 'Déclaration annuelle';
    }
    protected string $view = 'filament.pages.projection';

    public int $startYear = 2026;
    public int $projectionYears = 10;

    public function mount(): void
    {
        $this->startYear = (int) date('Y');

        app(BadgeService::class)->evaluate(auth()->user(), 'projection_used');
    }

    #[Computed]
    public function projectionData(): array
    {
        $properties = Property::all();

        if ($properties->isEmpty()) {
            return ['empty' => true];
        }

        $depService = app(DepreciationService::class);
        $rows = [];
        $cumulativeDeferred = 0;

        for ($i = 0; $i < $this->projectionYears; $i++) {
            $year = $this->startYear + $i;

            $totalDepreciation = 0;
            $depDetails = ['building' => 0, 'works' => 0, 'furniture' => 0];

            foreach ($properties as $property) {
                $dep = $depService->calculateAnnualDepreciation($property, $year);
                $totalDepreciation += (int) $dep['total'];
                $depDetails['building'] += (int) $dep['building'];
                $depDetails['works'] += (int) $dep['works'];
                $depDetails['furniture'] += (int) $dep['furniture'];
            }

            // Estimation recettes et charges (basées sur l'année courante si pas de données)
            $totalIncome = 0;
            $totalExpenses = 0;
            foreach ($properties as $property) {
                $income = $property->incomes()->whereYear('income_date', $year)->sum('amount');
                if ($income == 0 && $i > 0) {
                    // Reprendre les données de la dernière année connue
                    $income = $property->incomes()->sum('amount');
                    if ($property->incomes()->count() > 0) {
                        $years = $property->incomes()->selectRaw('COUNT(DISTINCT strftime("%Y", income_date)) as y')->value('y') ?: 1;
                        $income = (int) ($income / $years);
                    }
                }
                $totalIncome += $income;

                $dedicated = $property->expenses()->whereYear('expense_date', $year)->where('is_dedicated', true)->sum('amount');
                $shared = $property->expenses()->whereYear('expense_date', $year)->where('is_dedicated', false)->sum('amount');
                if ($dedicated == 0 && $shared == 0 && $i > 0) {
                    $dedicated = $property->expenses()->where('is_dedicated', true)->sum('amount');
                    $shared = $property->expenses()->where('is_dedicated', false)->sum('amount');
                    $expYears = $property->expenses()->selectRaw('COUNT(DISTINCT strftime("%Y", expense_date)) as y')->value('y') ?: 1;
                    $dedicated = (int) ($dedicated / $expYears);
                    $shared = (int) ($shared / $expYears);
                }
                $totalExpenses += $dedicated + (int) bcmul((string) $shared, $property->quota_share, 0);
            }

            // Plafonnement
            $resultBefore = $totalIncome - $totalExpenses;
            $available = $totalDepreciation + $cumulativeDeferred;

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

            // Micro-BIC comparaison
            $microBic50 = (int) ($totalIncome * 0.50);
            $microBic30 = (int) ($totalIncome * 0.70); // abattement 30% = 70% imposable

            $rows[] = [
                'year' => $year,
                'income' => $totalIncome,
                'expenses' => $totalExpenses,
                'dep_building' => $depDetails['building'],
                'dep_works' => $depDetails['works'],
                'dep_furniture' => $depDetails['furniture'],
                'dep_total' => $totalDepreciation,
                'capped' => $capped,
                'deferred' => $cumulativeDeferred,
                'fiscal_result' => $fiscalResult,
                'micro_bic_50' => $microBic50,
                'micro_bic_30' => $microBic30,
                'recommended' => $fiscalResult < $microBic50 ? 'real' : 'micro_bic',
            ];
        }

        // Hypothèses de projection
        $assumptions = $this->buildAssumptions($properties);

        return ['empty' => false, 'rows' => $rows, 'assumptions' => $assumptions];
    }

    private function buildAssumptions($properties): array
    {
        $incomeByYear = [];
        $totalDedicated = 0;
        $totalShared = 0;
        $expYears = 1;
        $componentsDetail = [];
        $worksDetail = [];
        $furnitureDetail = [];
        $propertyInfo = [];

        foreach ($properties as $property) {
            // Revenus par année
            $incomes = $property->incomes()
                ->selectRaw('strftime("%Y", income_date) as year, SUM(amount) as total')
                ->groupByRaw('strftime("%Y", income_date)')
                ->pluck('total', 'year')
                ->toArray();
            foreach ($incomes as $y => $amt) {
                $incomeByYear[$y] = ($incomeByYear[$y] ?? 0) + $amt;
            }

            // Charges moyennes
            $ded = $property->expenses()->where('is_dedicated', true)->sum('amount');
            $sha = $property->expenses()->where('is_dedicated', false)->sum('amount');
            $ey = $property->expenses()->selectRaw('COUNT(DISTINCT strftime("%Y", expense_date)) as y')->value('y') ?: 1;
            $totalDedicated += (int) ($ded / $ey);
            $totalShared += (int) ($sha / $ey);
            $expYears = max($expYears, $ey);

            // Composants immeuble
            foreach ($property->components as $comp) {
                $componentsDetail[] = [
                    'name' => $comp->name,
                    'percentage' => $comp->percentage,
                    'duration' => $comp->duration_years,
                    'annual' => $comp->annual_depreciation,
                ];
            }

            // Travaux
            foreach ($property->works as $work) {
                $annual = $work->annual_depreciation;
                $afterQuota = $work->is_dedicated ? $annual : (int) bcmul((string) $annual, $property->quota_share, 0);
                $worksDetail[] = [
                    'description' => $work->description,
                    'amount' => $work->amount,
                    'duration' => $work->duration_years,
                    'annual' => $annual,
                    'is_dedicated' => $work->is_dedicated,
                    'after_quota' => $afterQuota,
                ];
            }

            // Mobilier
            foreach ($property->furniture as $item) {
                $annual = $item->annual_depreciation;
                $afterQuota = $item->is_dedicated ? $annual : (int) bcmul((string) $annual, $property->quota_share, 0);
                $furnitureDetail[] = [
                    'description' => $item->description,
                    'amount' => $item->amount,
                    'duration' => $item->duration_years,
                    'annual' => $annual,
                    'is_dedicated' => $item->is_dedicated,
                    'after_quota' => $afterQuota,
                ];
            }

            // Info bien
            $propertyInfo[] = [
                'name' => $property->name,
                'market_value' => $property->market_value ?? $property->acquisition_price,
                'land_percentage' => $property->land_percentage,
                'rented_area' => $property->rented_area,
                'total_area' => $property->total_area,
                'quota_share' => $property->quota_share,
                'depreciable_base' => $property->depreciable_base,
            ];
        }

        ksort($incomeByYear);
        $nbYears = count($incomeByYear) ?: 1;
        $totalIncome = array_sum($incomeByYear);
        $avgIncome = (int) ($totalIncome / $nbYears);

        $quotaShare = $properties->first()?->quota_share ?? '0';
        $sharedAfterQuota = (int) bcmul((string) $totalShared, $quotaShare, 0);
        $totalExpenses = $totalDedicated + $sharedAfterQuota;

        $depBuilding = array_sum(array_column($componentsDetail, 'annual'));
        $depWorks = array_sum(array_column($worksDetail, 'after_quota'));
        $depFurniture = array_sum(array_column($furnitureDetail, 'after_quota'));

        return [
            'income' => [
                'by_year' => $incomeByYear,
                'average' => $avgIncome,
            ],
            'expenses' => [
                'dedicated' => $totalDedicated,
                'shared' => $totalShared,
                'shared_after_quota' => $sharedAfterQuota,
                'total' => $totalExpenses,
            ],
            'depreciation' => [
                'components' => $componentsDetail,
                'works' => $worksDetail,
                'furniture' => $furnitureDetail,
                'total_building' => $depBuilding,
                'total_works' => $depWorks,
                'total_furniture' => $depFurniture,
                'grand_total' => $depBuilding + $depWorks + $depFurniture,
            ],
            'properties' => $propertyInfo,
        ];
    }

    private function fmt(int $cents): string
    {
        return number_format($cents / 100, 0, ',', ' ');
    }
}
