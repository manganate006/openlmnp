<?php

namespace App\Filament\Resources\Furniture\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use App\Support\DocumentStorage;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class FurnitureTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('property.name')
                    ->label('Bien')
                    ->searchable(),
                TextColumn::make('description')
                    ->label('Description')
                    ->searchable()
                    ->limit(30),
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
                IconColumn::make('is_dedicated')
                    ->label('100%')
                    ->boolean(),
                IconColumn::make('is_second_hand')
                    ->label('Occasion')
                    ->boolean(),
                IconColumn::make('invoice_path')
                    ->label('Facture')
                    ->icon(fn ($record) => filled($record->invoice_path) ? 'heroicon-o-paper-clip' : null)
                    ->color(fn ($record) => filled($record->invoice_path) ? 'success' : null)
                    ->url(fn ($record) => DocumentStorage::temporaryUrl($record->invoice_path))
                    ->openUrlInNewTab(),
                TextColumn::make('annual_depreciation')
                    ->label('Amort./an')
                    ->formatStateUsing(fn ($state) => number_format($state / 100, 0, ',', ' ') . ' €')
                    ->sortable(),
            ])
            ->defaultSort('purchase_date', 'desc')
            ->recordActions([EditAction::make()])
            ->toolbarActions([BulkActionGroup::make([DeleteBulkAction::make()])]);
    }
}
