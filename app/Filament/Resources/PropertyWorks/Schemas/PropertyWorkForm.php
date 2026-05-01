<?php

namespace App\Filament\Resources\PropertyWorks\Schemas;

use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class PropertyWorkForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Travaux')
                    ->icon('heroicon-o-wrench')
                    ->schema([
                        Select::make('property_id')
                            ->label('Bien')
                            ->relationship('property', 'name')
                            ->required()
                            ->preload(),
                        TextInput::make('description')
                            ->label('Description')
                            ->required()
                            ->placeholder('Ex : Travaux aménagement, Piscine...'),
                        Grid::make(2)->schema([
                            TextInput::make('amount')
                                ->label('Montant')
                                ->suffix('€')
                                ->required()
                                ->numeric()
                                ->step(0.01)
                                ->formatStateUsing(fn ($state) => $state ? number_format($state / 100, 2, '.', '') : null)
                                ->dehydrateStateUsing(fn ($state) => (int) round(((float) $state) * 100))
                                ->hint('Coût total TTC des travaux')
                                ->hintIcon('heroicon-o-question-mark-circle'),
                            DatePicker::make('work_date')
                                ->label('Date des travaux')
                                ->required()
                                ->displayFormat('d/m/Y'),
                        ]),
                        Grid::make(2)->schema([
                            TextInput::make('duration_years')
                                ->label('Durée d\'amortissement')
                                ->suffix('ans')
                                ->required()
                                ->numeric()
                                ->default(10)
                                ->hint('Standards : travaux amélioration 10-15 ans, piscine 15-20 ans')
                                ->hintIcon('heroicon-o-question-mark-circle'),
                            TextInput::make('annual_depreciation')
                                ->label('Amortissement annuel')
                                ->suffix('€')
                                ->numeric()
                                ->formatStateUsing(fn ($state) => $state ? number_format($state / 100, 2, '.', '') : null)
                                ->dehydrateStateUsing(fn ($state) => (int) round(((float) $state) * 100))
                                ->hint('Calculé automatiquement si laissé vide')
                                ->hintIcon('heroicon-o-question-mark-circle'),
                        ]),
                        Toggle::make('is_dedicated')
                            ->label('100% dédié au bien loué')
                            ->helperText('Cochez si les travaux concernent uniquement la partie louée. Sinon, la quote-part surface sera appliquée (ex : piscine commune).')
                            ->default(true),
                    ]),
            ]);
    }
}
