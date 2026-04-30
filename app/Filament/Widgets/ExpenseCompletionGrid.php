<?php

namespace App\Filament\Widgets;

use App\Models\Expense;
use App\Models\Income;
use Filament\Widgets\Widget;

class ExpenseCompletionGrid extends Widget
{
    protected static string $view = 'filament.widgets.expense-completion-grid';
    protected int | string | array $columnSpan = 'full';

    public function getGrid(): array
    {
        $categories = array_keys(Expense::categoryEmojis());
        $emojis = Expense::categoryEmojis();
        $shortLabels = Expense::categoryShortLabels();

        // Trouver les années avec activité (recettes ou charges)
        $incomeYears = Income::selectRaw("DISTINCT strftime('%Y', income_date) as y")->pluck('y')->toArray();
        $expenseYears = Expense::selectRaw("DISTINCT strftime('%Y', expense_date) as y")->pluck('y')->toArray();
        $years = array_unique(array_merge($incomeYears, $expenseYears, [(string) date('Y')]));
        rsort($years);

        $grid = [];
        foreach ($years as $year) {
            $filledCategories = Expense::whereYear('expense_date', $year)->pluck('category')->unique()->toArray();

            $row = ['year' => $year, 'categories' => []];
            foreach ($categories as $cat) {
                $row['categories'][] = [
                    'emoji' => $emojis[$cat],
                    'label' => $shortLabels[$cat],
                    'filled' => in_array($cat, $filledCategories),
                ];
            }
            $grid[] = $row;
        }

        return $grid;
    }
}
