<?php

namespace App\Filament\Resources\Properties\RelationManagers;

use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
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
        return $schema->components([
            TextInput::make('description')->label('Description')->required(),
            TextInput::make('amount')->label('Montant (€)')->suffix('€')->required()->numeric()
                ->formatStateUsing(fn ($state) => $state ? number_format($state / 100, 0, '.', '') : null)
                ->dehydrateStateUsing(fn ($state) => (int) round(((float) $state) * 100)),
            DatePicker::make('work_date')->label('Date')->required()->displayFormat('d/m/Y'),
            TextInput::make('duration_years')->label('Durée amortissement')->suffix('ans')->required()->numeric()->default(10),
            Toggle::make('is_dedicated')->label('100% dédié au bien loué')->default(true),
            TextInput::make('annual_depreciation')->label('Amort. annuel (€)')->suffix('€')->numeric()
                ->formatStateUsing(fn ($state) => $state ? number_format($state / 100, 0, '.', '') : null)
                ->dehydrateStateUsing(fn ($state) => (int) round(((float) $state) * 100)),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('description')->label('Description')->limit(30),
                TextColumn::make('work_date')->label('Date')->date('d/m/Y'),
                TextColumn::make('amount')->label('Montant')
                    ->formatStateUsing(fn ($state) => number_format($state / 100, 0, ',', ' ') . ' €'),
                TextColumn::make('duration_years')->label('Durée')->suffix(' ans'),
                IconColumn::make('is_dedicated')->label('100%')->boolean(),
                TextColumn::make('annual_depreciation')->label('Amort./an')
                    ->formatStateUsing(fn ($state) => number_format($state / 100, 0, ',', ' ') . ' €'),
            ])
            ->defaultSort('work_date', 'desc')
            ->recordActions([EditAction::make(), DeleteAction::make()])
            ->headerActions([CreateAction::make()]);
    }
}
