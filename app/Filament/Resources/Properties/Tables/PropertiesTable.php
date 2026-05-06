<?php

namespace App\Filament\Resources\Properties\Tables;

use App\Models\Property;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class PropertiesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Nom')
                    ->searchable(),
                TextColumn::make('city')
                    ->label('Ville')
                    ->searchable(),
                TextColumn::make('type')
                    ->label('Type')
                    ->formatStateUsing(fn ($state) => Property::typeLabels()[$state] ?? $state),
                TextColumn::make('total_area')
                    ->label('Surface totale')
                    ->suffix(' m²')
                    ->sortable(),
                TextColumn::make('rented_area')
                    ->label('Surface louée')
                    ->suffix(' m²')
                    ->sortable(),
                TextColumn::make('acquisition_price')
                    ->label('Prix d\'achat')
                    ->formatStateUsing(fn ($state) => number_format($state / 100, 0, ',', ' ') . ' €')
                    ->sortable(),
                TextColumn::make('market_value')
                    ->label('Valeur vénale')
                    ->formatStateUsing(fn ($state) => $state ? number_format($state / 100, 0, ',', ' ') . ' €' : '—')
                    ->sortable(),
                TextColumn::make('rental_type')
                    ->label('Location')
                    ->formatStateUsing(fn ($state) => Property::rentalTypeLabels()[$state] ?? $state),
                TextColumn::make('tva_regime')
                    ->label('TVA')
                    ->formatStateUsing(fn ($state) => $state === 'liable' ? 'Assujetti' : 'Exempt')
                    ->badge()
                    ->color(fn ($state) => $state === 'liable' ? 'warning' : 'gray')
                    ->visible(fn () => Property::where('tva_regime', 'liable')->exists()),
                IconColumn::make('is_primary_residence')
                    ->label('RP')
                    ->boolean(),
            ])
            ->reorderableColumns()
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
