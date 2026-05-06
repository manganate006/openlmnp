<?php

namespace App\Filament\Resources\Furniture\Tables;

use App\Enums\TvaRate;
use App\Filament\Tables\Filters\YearFilter;
use App\Models\Furniture;
use App\Models\Property;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class FurnitureTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('description')
                    ->label('Description')
                    ->searchable()
                    ->limit(50),
                TextColumn::make('purchase_date')
                    ->label('Date')
                    ->date('d/m/Y')
                    ->sortable(),
                TextColumn::make('amount')
                    ->label('Montant')
                    ->formatStateUsing(fn ($state) => number_format($state / 100, 0, ',', ' ') . ' €')
                    ->sortable(),
                TextColumn::make('duration_years')
                    ->label('Durée')
                    ->suffix(' ans')
                    ->sortable(),
                TextColumn::make('annual_depreciation')
                    ->label('Amort./an')
                    ->formatStateUsing(fn ($state) => number_format($state / 100, 0, ',', ' ') . ' €')
                    ->sortable(),
                IconColumn::make('is_dedicated')
                    ->label('100%')
                    ->boolean(),
                IconColumn::make('is_second_hand')
                    ->label('Occasion')
                    ->boolean(),
                TextColumn::make('tva_rate')
                    ->label('TVA')
                    ->formatStateUsing(fn ($state) => $state ? (TvaRate::tryFrom($state)?->label() ?? $state) : '—')
                    ->visible(fn () => Property::where('tva_regime', 'liable')->exists()),
                TextColumn::make('property.name')
                    ->label('Bien')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('documents_count')
                    ->label('Docs')
                    ->counts('documents')
                    ->icon('heroicon-o-paper-clip')
                    ->default(0)
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->reorderableColumns()
            ->defaultSort('purchase_date', 'desc')
            ->filters([YearFilter::make('purchase_date', Furniture::class)])
            ->persistFiltersInSession()
            ->recordActions([EditAction::make()])
            ->toolbarActions([BulkActionGroup::make([DeleteBulkAction::make()])]);
    }
}
