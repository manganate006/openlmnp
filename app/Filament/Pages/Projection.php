<?php

namespace App\Filament\Pages;

use App\Models\Property;
use App\Services\DepreciationService;
use App\Services\FiscalYearService;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Livewire\Attributes\Computed;
use UnitEnum;

class Projection extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;
    protected static string | UnitEnum | null $navigationGroup = 'Fiscal';
    protected static ?string $navigationLabel = 'Projection';
    protected static ?string $title = 'Projection pluriannuelle';
    protected static ?int $navigationSort = 3;
    protected string $view = 'filament.pages.projection';

    public int $startYear = 2026;
    public int $projectionYears = 10;

    public function mount(): void
    {
        $this->startYear = (int) date('Y');
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

        return ['empty' => false, 'rows' => $rows];
    }

    private function fmt(int $cents): string
    {
        return number_format($cents / 100, 0, ',', ' ');
    }
}
