<?php

namespace App\Filament\Widgets;

use App\Models\Expense;
use App\Models\Income;
use App\Models\Property;
use Filament\Widgets\ChartWidget;

class MonthlyChart extends ChartWidget
{
    protected ?string $heading = 'Recettes vs Charges par mois';
    protected static ?int $sort = 99;
    protected int | string | array $columnSpan = 'full';

    public static function canView(): bool
    {
        return false;
    }

    protected function getData(): array
    {
        $year = (int) date('Y');
        $properties = Property::all();
        $propertyIds = $properties->pluck('id');

        $incomesByMonth = [];
        $expensesByMonth = [];
        $months = ['Jan', 'Fév', 'Mar', 'Avr', 'Mai', 'Jun', 'Jul', 'Aoû', 'Sep', 'Oct', 'Nov', 'Déc'];

        for ($m = 1; $m <= 12; $m++) {
            $income = Income::whereIn('property_id', $propertyIds)
                ->whereYear('income_date', $year)
                ->whereMonth('income_date', $m)
                ->sum('amount');
            $incomesByMonth[] = round($income / 100);

            $totalExpense = 0;
            foreach ($properties as $property) {
                $dedicated = $property->expenses()
                    ->whereYear('expense_date', $year)
                    ->whereMonth('expense_date', $m)
                    ->where('is_dedicated', true)
                    ->sum('amount');

                $shared = $property->expenses()
                    ->whereYear('expense_date', $year)
                    ->whereMonth('expense_date', $m)
                    ->where('is_dedicated', false)
                    ->sum('amount');

                $totalExpense += $dedicated + (int) bcmul((string) $shared, $property->quota_share, 0);
            }
            $expensesByMonth[] = round($totalExpense / 100);
        }

        return [
            'datasets' => [
                [
                    'label' => 'Recettes (€)',
                    'data' => $incomesByMonth,
                    'borderColor' => '#10b981',
                    'backgroundColor' => 'rgba(16, 185, 129, 0.1)',
                    'fill' => true,
                ],
                [
                    'label' => 'Charges (€)',
                    'data' => $expensesByMonth,
                    'borderColor' => '#f59e0b',
                    'backgroundColor' => 'rgba(245, 158, 11, 0.1)',
                    'fill' => true,
                ],
            ],
            'labels' => $months,
        ];
    }

    protected function getType(): string
    {
        return 'line';
    }
}
