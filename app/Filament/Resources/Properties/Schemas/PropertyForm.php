<?php

namespace App\Filament\Resources\Properties\Schemas;

use App\Models\Property;
use Filament\Forms\Components\DatePicker;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class PropertyForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Informations générales')
                    ->icon('heroicon-o-home-modern')
                    ->schema([
                        TextInput::make('name')
                            ->label('Nom du bien')
                            ->placeholder('Ex : Appartement Airbnb')
                            ->required()
                            ->maxLength(255),
                        Grid::make(2)->schema([
                            Select::make('type')
                                ->label('Type de bien')
                                ->options(Property::typeLabels())
                                ->required()
                                ->default('apartment'),
                            Select::make('rental_type')
                                ->label('Type de location')
                                ->options(Property::rentalTypeLabels())
                                ->required()
                                ->default('seasonal'),
                        ]),
                        TextInput::make('address')
                            ->label('Adresse')
                            ->required(),
                        Grid::make(2)->schema([
                            TextInput::make('city')
                                ->label('Ville')
                                ->required(),
                            TextInput::make('postal_code')
                                ->label('Code postal')
                                ->required()
                                ->maxLength(10),
                        ]),
                        Toggle::make('is_primary_residence')
                            ->label('Fait partie de la résidence principale')
                            ->helperText('Cochez si le bien loué est une partie de votre résidence principale (quote-part appliquée)')
                            ->default(false),
                    ]),

                Section::make('Surfaces')
                    ->icon('heroicon-o-square-3-stack-3d')
                    ->description('Les surfaces servent à calculer la quote-part pour le prorata des charges et amortissements.')
                    ->schema([
                        Grid::make(2)->schema([
                            TextInput::make('total_area')
                                ->label('Surface totale déclarée')
                                ->suffix('m²')
                                ->required()
                                ->numeric()
                                ->minValue(1),
                            TextInput::make('rented_area')
                                ->label('Surface louée')
                                ->suffix('m²')
                                ->required()
                                ->numeric()
                                ->minValue(1),
                        ]),
                    ]),

                Section::make('Acquisition')
                    ->icon('heroicon-o-banknotes')
                    ->schema([
                        DatePicker::make('acquisition_date')
                            ->label('Date d\'achat')
                            ->required()
                            ->displayFormat('d/m/Y'),
                        Grid::make(2)->schema([
                            TextInput::make('acquisition_price')
                                ->label('Prix d\'achat (hors frais)')
                                ->suffix('€')
                                ->required()
                                ->numeric()
                                ->step(1)
                                ->minValue(0)
                                ->formatStateUsing(fn ($state) => $state ? number_format($state / 100, 0, '.', '') : null)
                                ->dehydrateStateUsing(fn ($state) => (int) round(((float) $state) * 100))
                                ->helperText('Saisissez en euros (ex : 575000)'),
                            TextInput::make('notary_fees')
                                ->label('Frais de notaire')
                                ->suffix('€')
                                ->numeric()
                                ->step(1)
                                ->default(0)
                                ->formatStateUsing(fn ($state) => $state ? number_format($state / 100, 0, '.', '') : '0')
                                ->dehydrateStateUsing(fn ($state) => (int) round(((float) $state) * 100))
                                ->helperText('Saisissez en euros'),
                        ]),
                    ]),

                Section::make('Valeur vénale')
                    ->icon('heroicon-o-chart-bar')
                    ->description('Si le bien était déjà possédé avant la mise en location, indiquez sa valeur au début de l\'activité.')
                    ->collapsed()
                    ->schema([
                        Grid::make(2)->schema([
                            TextInput::make('market_value')
                                ->label('Valeur vénale estimée')
                                ->suffix('€')
                                ->numeric()
                                ->step(1)
                                ->formatStateUsing(fn ($state) => $state ? number_format($state / 100, 0, '.', '') : null)
                                ->dehydrateStateUsing(fn ($state) => $state ? (int) round(((float) $state) * 100) : null)
                                ->helperText('Saisissez en euros. Base de l\'amortissement si renseignée.'),
                            DatePicker::make('market_value_date')
                                ->label('Date de l\'estimation')
                                ->displayFormat('d/m/Y'),
                        ]),
                        TextInput::make('land_percentage')
                            ->label('Part du terrain')
                            ->suffix('%')
                            ->required()
                            ->numeric()
                            ->default(15)
                            ->minValue(0)
                            ->maxValue(50)
                            ->helperText('Le terrain n\'est pas amortissable. Généralement 15-20%.'),
                    ]),

                Section::make('Location')
                    ->icon('heroicon-o-calendar')
                    ->schema([
                        DatePicker::make('rental_start_date')
                            ->label('Date de début de location')
                            ->required()
                            ->displayFormat('d/m/Y')
                            ->helperText('Date à partir de laquelle les amortissements commencent.'),
                    ]),

                Section::make('Notes')
                    ->collapsed()
                    ->schema([
                        Textarea::make('notes')
                            ->label('Notes')
                            ->rows(3)
                            ->columnSpanFull(),
                    ]),
            ]);
    }
}
