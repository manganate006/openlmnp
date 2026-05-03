<?php

namespace App\Filament\Resources\Expenses\Pages;

use App\Filament\Resources\Expenses\ExpenseResource;
use App\Filament\Widgets\ExpenseCompletionGrid;
use App\Models\Expense;
use App\Services\CsvExportService;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListExpenses extends ListRecords
{
    protected static string $resource = ExpenseResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('export_csv')
                ->label('Exporter CSV')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->action(function () {
                    $expenses = Expense::with('property')->orderBy('expense_date', 'desc')->get();
                    return CsvExportService::export(
                        'charges_' . date('Y') . '.csv',
                        ['Date', 'Bien', 'Montant (€)', 'Catégorie', 'Description', '100% dédié', 'Récurrence'],
                        $expenses,
                        fn ($e) => [
                            $e->expense_date->format('d/m/Y'),
                            $e->property->name ?? '',
                            number_format($e->amount / 100, 2, ',', ''),
                            Expense::categoryLabels()[$e->category] ?? $e->category,
                            $e->description,
                            $e->is_dedicated ? 'Oui' : 'Non',
                            Expense::recurringLabels()[$e->recurring_type] ?? $e->recurring_type,
                        ]
                    );
                }),
            CreateAction::make(),
        ];
    }

    protected function getFooterWidgets(): array
    {
        return [
            ExpenseCompletionGrid::class,
        ];
    }
}
