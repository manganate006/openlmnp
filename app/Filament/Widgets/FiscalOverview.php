<?php

namespace App\Filament\Widgets;

use App\Models\Expense;
use App\Models\Income;
use App\Models\Property;
use App\Services\DepreciationService;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class FiscalOverview extends StatsOverviewWidget
{
    protected static ?int $sort = 1;

    protected function getStats(): array
    {
        $year = (int) date('Y');
        $properties = Property::all();

        // Guide de démarrage si aucun bien
        if ($properties->isEmpty()) {
            return [
                Stat::make('Étape 1', 'Ajoutez votre bien immobilier')
                    ->description('Menu Mes biens → Biens immobiliers → Nouveau')
                    ->icon('heroicon-o-home-modern')
                    ->color('primary')
                    ->url('/app/properties/create'),
                Stat::make('Étape 2', 'Saisissez vos recettes')
                    ->description('Après avoir ajouté un bien')
                    ->icon('heroicon-o-banknotes')
                    ->color('gray'),
                Stat::make('Étape 3', 'Consultez le simulateur')
                    ->description('Comparez micro-BIC vs régime réel')
                    ->icon('heroicon-o-calculator')
                    ->color('gray'),
            ];
        }

        // KPIs fiscaux
        $totalIncome = 0;
        $totalExpenses = 0;
        $totalDepreciation = 0;
        $incomeCount = 0;
        $expenseCount = 0;

        foreach ($properties as $property) {
            $totalIncome += $property->incomes()->whereYear('income_date', $year)->sum('amount');
            $incomeCount += $property->incomes()->whereYear('income_date', $year)->count();

            $dedicated = $property->expenses()->whereYear('expense_date', $year)->where('is_dedicated', true)->sum('amount');
            $shared = $property->expenses()->whereYear('expense_date', $year)->where('is_dedicated', false)->sum('amount');
            $totalExpenses += $dedicated + (int) bcmul((string) $shared, $property->quota_share, 0);
            $expenseCount += $property->expenses()->whereYear('expense_date', $year)->count();

            $depreciation = app(DepreciationService::class)->calculateAnnualDepreciation($property, $year);
            $totalDepreciation += (int) $depreciation['total'];
        }

        $incomeEuros = number_format($totalIncome / 100, 0, ',', ' ');
        $expenseEuros = number_format($totalExpenses / 100, 0, ',', ' ');
        $depreciationEuros = number_format($totalDepreciation / 100, 0, ',', ' ');

        $resultBefore = $totalIncome - $totalExpenses;
        $cappedDepreciation = min($totalDepreciation, max(0, $resultBefore));
        $fiscalResult = max(0, $resultBefore - $cappedDepreciation);
        $resultEuros = number_format($fiscalResult / 100, 0, ',', ' ');

        $microBicResult = (int) bcmul((string) $totalIncome, '0.50', 0);
        $microBicEuros = number_format($microBicResult / 100, 0, ',', ' ');

        $stats = [
            Stat::make("Recettes {$year}", "{$incomeEuros} €")
                ->description("{$incomeCount} recette(s) · {$properties->count()} bien(s)")
                ->icon('heroicon-o-banknotes')
                ->color('success')
                ->url('/app/incomes'),

            Stat::make("Charges {$year}", "{$expenseEuros} €")
                ->description("{$expenseCount} charge(s) · Après quote-part")
                ->icon('heroicon-o-receipt-percent')
                ->color('warning')
                ->url('/app/expenses'),

            Stat::make("Amortissements {$year}", "{$depreciationEuros} €")
                ->description('Immeuble + travaux + mobilier')
                ->icon('heroicon-o-building-library')
                ->color('info'),

            Stat::make("Résultat fiscal (réel)", "{$resultEuros} €")
                ->description("Micro-BIC : {$microBicEuros} € · " . ($fiscalResult < $microBicResult ? '✓ Réel avantageux' : '⚠ Micro-BIC avantageux'))
                ->icon('heroicon-o-calculator')
                ->color($fiscalResult < $microBicResult ? 'success' : 'danger')
                ->url('/app/simulator'),
        ];

        // Alerte si pas de recettes
        if ($totalIncome === 0) {
            $stats[] = Stat::make('Action requise', 'Ajoutez vos recettes ' . $year)
                ->description('Saisie manuelle ou import CSV Airbnb')
                ->icon('heroicon-o-exclamation-triangle')
                ->color('danger')
                ->url('/app/incomes/create');
        }

        return $stats;
    }
}
