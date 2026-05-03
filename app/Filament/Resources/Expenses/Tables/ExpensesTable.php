<?php

namespace App\Filament\Resources\Expenses\Tables;

use App\Enums\TvaRate;
use App\Models\Expense;
use App\Models\Property;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
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
                TextColumn::make('tva_rate')
                    ->label('TVA')
                    ->formatStateUsing(fn ($state) => $state ? (TvaRate::tryFrom($state)?->label() ?? $state) : '—')
                    ->visible(fn () => Property::where('tva_regime', 'liable')->exists()),
                TextColumn::make('amount_tva')
                    ->label('TVA déductible')
                    ->formatStateUsing(fn ($state) => $state ? number_format($state / 100, 2, ',', ' ') . ' €' : '—')
                    ->visible(fn () => Property::where('tva_regime', 'liable')->exists()),
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
                TextColumn::make('documents_count')
                    ->label('Docs')
                    ->counts('documents')
                    ->icon('heroicon-o-paper-clip')
                    ->default(0),
                TextColumn::make('recurring_type')
                    ->label('Récurrence')
                    ->formatStateUsing(fn ($state) => Expense::recurringLabels()[$state] ?? $state),
            ])
            ->defaultSort('expense_date', 'desc')
            ->filters([])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
