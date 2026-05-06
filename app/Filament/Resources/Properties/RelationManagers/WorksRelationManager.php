<?php

namespace App\Filament\Resources\Properties\RelationManagers;

use App\Filament\Resources\PropertyWorks\Schemas\PropertyWorkForm;
use App\Filament\Tables\Filters\YearFilter;
use App\Models\PropertyWork;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class WorksRelationManager extends RelationManager
{
    protected static string $relationship = 'works';
    protected static ?string $title = 'Travaux';
    protected static ?string $modelLabel = 'travail';

    public function form(Schema $schema): Schema
    {
        return PropertyWorkForm::configure($schema);
    }

    public function table(Table $table): Table
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
                TextColumn::make('documents_count')
                    ->label('Docs')
                    ->counts('documents')
                    ->icon('heroicon-o-paper-clip')
                    ->default(0),
            ])
            ->reorderableColumns()
            ->defaultSort('work_date', 'desc')
            ->filters([YearFilter::make('work_date', PropertyWork::class)])
            ->persistFiltersInSession()
            ->recordActions([EditAction::make(), DeleteAction::make()])
            ->headerActions([CreateAction::make()]);
    }
}
