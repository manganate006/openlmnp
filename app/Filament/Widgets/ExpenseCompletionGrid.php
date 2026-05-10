<?php

namespace App\Filament\Widgets;

use App\Models\Expense;
use App\Models\Income;
use Filament\Widgets\Widget;

class ExpenseCompletionGrid extends Widget
{
    protected string $view = 'filament.widgets.expense-completion-grid';
    protected int | string | array $columnSpan = [
        'default' => 1,
        'sm' => 2,
        'lg' => 4,
    ];
    protected static ?int $sort = 2;

    public function getGrid(): array
    {
        $categories = array_keys(Expense::categoryEmojis());
        $emojis = Expense::categoryEmojis();
        $shortLabels = Expense::categoryShortLabels();
        $fullLabels = array_map(fn ($v) => preg_replace('/^.+?\s/', '', $v, 1), Expense::categoryLabels());

        // Trouver les années avec activité (recettes ou charges)
        $incomeYears = Income::selectRaw("DISTINCT strftime('%Y', income_date) as y")->pluck('y')->toArray();
        $expenseYears = Expense::selectRaw("DISTINCT strftime('%Y', expense_date) as y")->pluck('y')->toArray();
        $years = array_unique(array_merge($incomeYears, $expenseYears, [(string) date('Y')]));
        rsort($years);

        $grid = [];
        foreach ($years as $year) {
            $hasIncome = Income::whereYear('income_date', $year)->exists();
            $filledCategories = Expense::whereYear('expense_date', $year)->pluck('category')->unique()->toArray();

            $row = ['year' => $year, 'categories' => []];

            // Colonne Recettes en premier
            $row['categories'][] = [
                'emoji' => '💰',
                'label' => 'Recettes',
                'tooltip' => 'Recettes',
                'filled' => $hasIncome,
            ];

            foreach ($categories as $cat) {
                $row['categories'][] = [
                    'emoji' => $emojis[$cat],
                    'label' => $shortLabels[$cat],
                    'tooltip' => $fullLabels[$cat],
                    'filled' => in_array($cat, $filledCategories),
                ];
            }
            $grid[] = $row;
        }

        return $grid;
    }
}
