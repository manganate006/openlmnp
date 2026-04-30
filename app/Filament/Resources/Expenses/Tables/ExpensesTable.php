<?php

namespace App\Filament\Resources\Expenses\Tables;

use App\Models\Expense;
use App\Services\CsvExportService;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\Action;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ExpensesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('property.name')
                    ->label('Bien')
                    ->searchable(),
                TextColumn::make('expense_date')
                    ->label('Date')
                    ->date('d/m/Y')
                    ->sortable(),
                TextColumn::make('amount')
                    ->label('Montant')
                    ->formatStateUsing(fn ($state) => number_format($state / 100, 2, ',', ' ') . ' €')
                    ->sortable(),
                TextColumn::make('category')
                    ->label('Catégorie')
                    ->formatStateUsing(fn ($state) => Expense::categoryLabels()[$state] ?? $state)
                    ->searchable(),
                TextColumn::make('description')
                    ->label('Description')
                    ->searchable()
                    ->limit(40),
                IconColumn::make('is_dedicated')
                    ->label('100%')
                    ->boolean(),
                TextColumn::make('recurring_type')
                    ->label('Récurrence')
                    ->formatStateUsing(fn ($state) => Expense::recurringLabels()[$state] ?? $state),
            ])
            ->defaultSort('expense_date', 'desc')
            ->filters([])
            ->recordActions([
                EditAction::make(),
            ])
            ->headerActions([
                Action::make('export_csv')
                    ->label('Exporter CSV')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->color('gray')
                    ->action(function () {
                        $expenses = \App\Models\Expense::with('property')->orderBy('expense_date', 'desc')->get();
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
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
