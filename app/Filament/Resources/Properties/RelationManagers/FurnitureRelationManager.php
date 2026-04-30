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

class FurnitureRelationManager extends RelationManager
{
    protected static string $relationship = 'furniture';
    protected static ?string $title = 'Mobilier & Équipements';
    protected static ?string $modelLabel = 'mobilier';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('description')->label('Description')->required(),
            TextInput::make('amount')->label('Montant (€)')->suffix('€')->required()->numeric()
                ->formatStateUsing(fn ($state) => $state ? number_format($state / 100, 0, '.', '') : null)
                ->dehydrateStateUsing(fn ($state) => (int) round(((float) $state) * 100)),
            DatePicker::make('purchase_date')->label('Date d\'achat')->required()->displayFormat('d/m/Y'),
            TextInput::make('duration_years')->label('Durée amortissement')->suffix('ans')->required()->numeric()->default(5),
            Toggle::make('is_dedicated')->label('100% dédié')->default(true),
            Toggle::make('is_second_hand')->label('Occasion')->default(false),
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
                TextColumn::make('purchase_date')->label('Date')->date('d/m/Y'),
                TextColumn::make('amount')->label('Montant')
                    ->formatStateUsing(fn ($state) => number_format($state / 100, 0, ',', ' ') . ' €'),
                TextColumn::make('duration_years')->label('Durée')->suffix(' ans'),
                IconColumn::make('is_dedicated')->label('100%')->boolean(),
                IconColumn::make('is_second_hand')->label('Occasion')->boolean(),
                TextColumn::make('annual_depreciation')->label('Amort./an')
                    ->formatStateUsing(fn ($state) => number_format($state / 100, 0, ',', ' ') . ' €'),
            ])
            ->defaultSort('purchase_date', 'desc')
            ->recordActions([EditAction::make(), DeleteAction::make()])
            ->headerActions([CreateAction::make()]);
    }
}
