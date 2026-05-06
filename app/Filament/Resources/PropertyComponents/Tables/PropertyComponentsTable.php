<?php

namespace App\Filament\Resources\PropertyComponents\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class PropertyComponentsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('property.name')
                    ->label('Bien')
                    ->searchable(),
                TextColumn::make('name')
                    ->label('Composant')
                    ->searchable(),
                TextColumn::make('percentage')
                    ->label('%')
                    ->suffix(' %')
                    ->sortable(),
                TextColumn::make('duration_years')
                    ->label('Durée')
                    ->suffix(' ans')
                    ->sortable(),
                TextColumn::make('base_amount')
                    ->label('Base')
                    ->formatStateUsing(fn ($state) => number_format($state / 100, 0, ',', ' ') . ' €')
                    ->sortable(),
                TextColumn::make('annual_depreciation')
                    ->label('Amort./an')
                    ->formatStateUsing(fn ($state) => number_format($state / 100, 0, ',', ' ') . ' €')
                    ->sortable(),
            ])
            ->reorderableColumns()
            ->defaultSort('sort_order')
            ->recordActions([EditAction::make()])
            ->toolbarActions([BulkActionGroup::make([DeleteBulkAction::make()])]);
    }
}
