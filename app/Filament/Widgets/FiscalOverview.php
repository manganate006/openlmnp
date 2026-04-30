<?php

namespace App\Filament\Widgets;

use App\Models\Expense;
use App\Models\Income;
use App\Models\Property;
use App\Services\DepreciationService;
use App\Services\FiscalYearService;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class FiscalOverview extends StatsOverviewWidget
{
    protected static ?int $sort = 1;

    protected function getStats(): array
    {
        $year = (int) date('Y');
        $user = auth()->user();
        $properties = Property::all();

        if ($properties->isEmpty()) {
            return [
                Stat::make('Aucun bien', 'Ajoutez votre premier bien immobilier')
                    ->icon('heroicon-o-home-modern'),
            ];
        }

        // Recettes de l'année
        $totalIncome = 0;
        $totalExpenses = 0;
        $totalDepreciation = 0;

        foreach ($properties as $property) {
            $totalIncome += $property->incomes()->whereYear('income_date', $year)->sum('amount');

            $dedicated = $property->expenses()->whereYear('expense_date', $year)->where('is_dedicated', true)->sum('amount');
            $shared = $property->expenses()->whereYear('expense_date', $year)->where('is_dedicated', false)->sum('amount');
            $totalExpenses += $dedicated + (int) bcmul((string) $shared, $property->quota_share, 0);

            $depreciation = app(DepreciationService::class)->calculateAnnualDepreciation($property, $year);
            $totalDepreciation += (int) $depreciation['total'];
        }

        $incomeEuros = number_format($totalIncome / 100, 0, ',', ' ');
        $expenseEuros = number_format($totalExpenses / 100, 0, ',', ' ');
        $depreciationEuros = number_format($totalDepreciation / 100, 0, ',', ' ');

        // Résultat fiscal estimé
        $resultBefore = $totalIncome - $totalExpenses;
        $cappedDepreciation = min($totalDepreciation, max(0, $resultBefore));
        $fiscalResult = max(0, $resultBefore - $cappedDepreciation);
        $resultEuros = number_format($fiscalResult / 100, 0, ',', ' ');

        // Micro-BIC comparaison (abattement 50% classé)
        $microBicResult = (int) bcmul((string) $totalIncome, '0.50', 0);
        $microBicEuros = number_format($microBicResult / 100, 0, ',', ' ');

        return [
            Stat::make("Recettes {$year}", "{$incomeEuros} €")
                ->description($properties->count() . ' bien(s)')
                ->icon('heroicon-o-banknotes')
                ->color('success'),

            Stat::make("Charges {$year}", "{$expenseEuros} €")
                ->description('Après quote-part')
                ->icon('heroicon-o-receipt-percent')
                ->color('warning'),

            Stat::make("Amortissements {$year}", "{$depreciationEuros} €")
                ->description('Immeuble + travaux + mobilier')
                ->icon('heroicon-o-building-library')
                ->color('info'),

            Stat::make("Résultat fiscal (réel)", "{$resultEuros} €")
                ->description("Micro-BIC : {$microBicEuros} €")
                ->icon('heroicon-o-calculator')
                ->color($fiscalResult < $microBicResult ? 'success' : 'danger'),
        ];
    }
}
