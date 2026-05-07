<?php

namespace App\Filament\Resources\PropertyWorks\Tables;

use App\Enums\TvaRate;
use App\Filament\Tables\Filters\YearFilter;
use App\Models\Property;
use App\Models\PropertyWork;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;

class PropertyWorksTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('description')
                    ->label('Description')
                    ->searchable()
                    ->limit(50),
                TextColumn::make('work_date')
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
            ->defaultSort('work_date', 'desc')
            ->filters([
                YearFilter::make('work_date', PropertyWork::class),
                Filter::make('no_documents')
                    ->label('Sans justificatif')
                    ->query(fn ($query) => $query->whereDoesntHave('documents')),
            ])
            ->persistFiltersInSession()
            ->recordActions([EditAction::make()])
            ->toolbarActions([BulkActionGroup::make([DeleteBulkAction::make()])]);
    }
}
