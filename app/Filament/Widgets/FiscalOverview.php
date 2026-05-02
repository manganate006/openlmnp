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

    protected function getColumns(): int
    {
        return 4;
    }

    protected ?string $heading = null;

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
                    ->url('/properties/create'),
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

        $current = $this->calculateYear($properties, $year);
        $previous = $this->calculateYear($properties, $year - 1);

        $stats = [];

        // Ligne N (année en cours)
        $stats = array_merge($stats, $this->buildYearStats($current, $year, $properties->count(), true));

        // Ligne N-1 (année précédente) — seulement si des données existent
        if ($previous['totalIncome'] > 0 || $previous['totalExpenses'] > 0) {
            $stats = array_merge($stats, $this->buildYearStats($previous, $year - 1, $properties->count(), false));
        }

        return $stats;
    }

    private function calculateYear($properties, int $year): array
    {
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

        $resultBefore = $totalIncome - $totalExpenses;
        $cappedDepreciation = min($totalDepreciation, max(0, $resultBefore));
        $fiscalResult = max(0, $resultBefore - $cappedDepreciation);
        $microBicResult = (int) bcmul((string) $totalIncome, '0.50', 0);

        return compact(
            'totalIncome', 'totalExpenses', 'totalDepreciation',
            'incomeCount', 'expenseCount',
            'fiscalResult', 'microBicResult',
        );
    }

    private function buildYearStats(array $data, int $year, int $propertyCount, bool $isCurrent): array
    {
        $fmt = fn (int $cents) => number_format($cents / 100, 0, ',', ' ');
        $color = fn (string $c) => $isCurrent ? $c : 'gray';

        return [
            Stat::make("Recettes {$year}", "{$fmt($data['totalIncome'])} €")
                ->description("{$data['incomeCount']} recette(s) · {$propertyCount} bien(s)")
                ->icon('heroicon-o-banknotes')
                ->color($color('success'))
                ->url($isCurrent ? '/incomes' : null),

            Stat::make("Charges {$year}", "{$fmt($data['totalExpenses'])} €")
                ->description("{$data['expenseCount']} charge(s) · Après quote-part")
                ->icon('heroicon-o-receipt-percent')
                ->color($color('warning'))
                ->url($isCurrent ? '/expenses' : null),

            Stat::make("Amortissements {$year}", "{$fmt($data['totalDepreciation'])} €")
                ->description('Immeuble + travaux + mobilier')
                ->icon('heroicon-o-building-library')
                ->color($color('info')),

            Stat::make("Résultat {$year} (réel)", "{$fmt($data['fiscalResult'])} €")
                ->description("Micro-BIC : {$fmt($data['microBicResult'])} € · " . ($data['fiscalResult'] < $data['microBicResult'] ? '✓ Réel avantageux' : '⚠ Micro-BIC avantageux'))
                ->icon('heroicon-o-calculator')
                ->color($data['fiscalResult'] < $data['microBicResult'] ? ($isCurrent ? 'success' : 'gray') : ($isCurrent ? 'danger' : 'gray'))
                ->url($isCurrent ? '/simulator' : null),
        ];
    }
}
