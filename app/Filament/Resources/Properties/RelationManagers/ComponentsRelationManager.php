<?php

namespace App\Filament\Resources\Properties\RelationManagers;

use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ComponentsRelationManager extends RelationManager
{
    protected static string $relationship = 'components';
    protected static ?string $title = 'Composants d\'amortissement';
    protected static ?string $modelLabel = 'composant';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->label('Composant')->required(),
            TextInput::make('percentage')->label('Pourcentage')->suffix('%')->required()->numeric(),
            TextInput::make('duration_years')->label('Durée')->suffix('ans')->required()->numeric(),
            TextInput::make('base_amount')->label('Base (€)')
                ->suffix('€')->numeric()
                ->formatStateUsing(fn ($state) => $state ? number_format($state / 100, 0, '.', '') : null)
                ->dehydrateStateUsing(fn ($state) => (int) round(((float) $state) * 100)),
            TextInput::make('annual_depreciation')->label('Amort. annuel (€)')
                ->suffix('€')->numeric()
                ->formatStateUsing(fn ($state) => $state ? number_format($state / 100, 0, '.', '') : null)
                ->dehydrateStateUsing(fn ($state) => (int) round(((float) $state) * 100)),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label('Composant'),
                TextColumn::make('percentage')->label('%')->suffix(' %'),
                TextColumn::make('duration_years')->label('Durée')->suffix(' ans'),
                TextColumn::make('base_amount')->label('Base')
                    ->formatStateUsing(fn ($state) => number_format($state / 100, 0, ',', ' ') . ' €'),
                TextColumn::make('annual_depreciation')->label('Amort./an')
                    ->formatStateUsing(fn ($state) => number_format($state / 100, 0, ',', ' ') . ' €'),
            ])
            ->defaultSort('sort_order')
            ->recordActions([EditAction::make(), DeleteAction::make()])
            ->headerActions([CreateAction::make()]);
    }
}
