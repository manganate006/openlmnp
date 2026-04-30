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
                            ->maxLength(255)
                            ->hint('Un nom pour identifier facilement ce bien')
                            ->hintIcon('heroicon-o-question-mark-circle'),
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
                                ->default('seasonal')
                                ->hint('Saisonnier = Airbnb/Booking. Longue durée = bail classique.')
                                ->hintIcon('heroicon-o-question-mark-circle'),
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
                            ->helperText('Cochez si le bien loué est une partie de votre résidence principale. La quote-part (surface louée ÷ surface totale) sera appliquée automatiquement aux charges et amortissements.')
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
                                ->minValue(1)
                                ->hint('Surface totale du logement telle que déclarée aux impôts')
                                ->hintIcon('heroicon-o-question-mark-circle'),
                            TextInput::make('rented_area')
                                ->label('Surface louée')
                                ->suffix('m²')
                                ->required()
                                ->numeric()
                                ->minValue(1)
                                ->hint('Surface de la partie effectivement louée. Quote-part = louée ÷ totale.')
                                ->hintIcon('heroicon-o-question-mark-circle'),
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
                                ->helperText('Saisissez en euros (ex : 575000)')
                                ->hint('Prix figurant sur l\'acte de vente, hors frais de notaire')
                                ->hintIcon('heroicon-o-question-mark-circle'),
                            TextInput::make('notary_fees')
                                ->label('Frais de notaire')
                                ->suffix('€')
                                ->numeric()
                                ->step(1)
                                ->default(0)
                                ->formatStateUsing(fn ($state) => $state ? number_format($state / 100, 0, '.', '') : '0')
                                ->dehydrateStateUsing(fn ($state) => (int) round(((float) $state) * 100))
                                ->hint('Peuvent être amortis ou déduits en charges la 1ère année')
                                ->hintIcon('heroicon-o-question-mark-circle'),
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
                                ->hint('Valeur du bien au moment de la mise en location. Utilisée comme base d\'amortissement à la place du prix d\'achat.')
                                ->hintIcon('heroicon-o-question-mark-circle')
                                ->helperText('Sources : DVF, MeilleursAgents, notaire, agent immobilier'),
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
                            ->hint('Le terrain n\'est pas amortissable. Typiquement 15% en ville, 20% en zone rurale.')
                            ->hintIcon('heroicon-o-question-mark-circle'),
                    ]),

                Section::make('Location')
                    ->icon('heroicon-o-calendar')
                    ->schema([
                        DatePicker::make('rental_start_date')
                            ->label('Date de début de location')
                            ->required()
                            ->displayFormat('d/m/Y')
                            ->hint('Date de la 1ère mise en location. Les amortissements démarrent à cette date (prorata temporis la 1ère année).')
                            ->hintIcon('heroicon-o-question-mark-circle'),
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
