<?php

namespace App\Filament\Resources\Furniture\Schemas;

use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class FurnitureForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Équipement / Mobilier')
                    ->icon('heroicon-o-cube')
                    ->schema([
                        Select::make('property_id')
                            ->label('Bien')
                            ->relationship('property', 'name')
                            ->required()
                            ->preload(),
                        TextInput::make('description')
                            ->label('Description')
                            ->required()
                            ->placeholder('Ex : Télévision, Lave-vaisselle...'),
                        Grid::make(2)->schema([
                            TextInput::make('amount')
                                ->label('Montant')
                                ->suffix('€')
                                ->required()
                                ->numeric()
                                ->step(0.01)
                                ->formatStateUsing(fn ($state) => $state ? number_format($state / 100, 2, '.', '') : null)
                                ->dehydrateStateUsing(fn ($state) => (int) round(((float) $state) * 100))
                                ->hint('Prix d\'achat TTC sur la facture')
                                ->hintIcon('heroicon-o-question-mark-circle'),
                            DatePicker::make('purchase_date')
                                ->label('Date d\'achat')
                                ->required()
                                ->displayFormat('d/m/Y'),
                        ]),
                        Grid::make(2)->schema([
                            TextInput::make('duration_years')
                                ->label('Durée d\'amortissement')
                                ->suffix('ans')
                                ->required()
                                ->numeric()
                                ->default(5)
                                ->hint('Standards : mobilier 5-7 ans, électroménager 7-10 ans')
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
                        Grid::make(2)->schema([
                            Toggle::make('is_dedicated')
                                ->label('100% dédié au bien loué')
                                ->helperText('Si non coché, la quote-part surface sera appliquée')
                                ->default(true),
                            Toggle::make('is_second_hand')
                                ->label('Acheté d\'occasion')
                                ->default(false),
                        ]),
                    ]),
            ]);
    }
}
