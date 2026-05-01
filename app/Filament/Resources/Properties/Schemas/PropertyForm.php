<?php

namespace App\Filament\Resources\Properties\Schemas;

use App\Models\Property;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Repeater;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Wizard;
use Filament\Schemas\Components\Wizard\Step;
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
                Wizard::make([

                    Step::make('Informations générales')
                        ->icon('heroicon-o-home-modern')
                        ->description('Nom, type et adresse du bien')
                        ->schema([
                            FileUpload::make('photo_path')
                                ->label('Photo du bien')
                                ->image()
                                ->directory('properties')
                                ->maxSize(5120)
                                ->imagePreviewHeight('150')
                                ->columnSpanFull(),
                            TextInput::make('name')
                                ->label('Nom du bien')
                                ->placeholder('Ex : La Bastide')
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
                                    ->default('seasonal')
                                    ->hint('Saisonnier = Airbnb. Longue durée = bail.')
                                    ->hintIcon('heroicon-o-question-mark-circle'),
                            ]),
                            Grid::make(3)->schema([
                                TextInput::make('address')
                                    ->label('Adresse')
                                    ->required(),
                                TextInput::make('city')
                                    ->label('Ville')
                                    ->required(),
                                TextInput::make('postal_code')
                                    ->label('Code postal')
                                    ->required()
                                    ->maxLength(10),
                            ]),
                        ]),

                    Step::make('Surfaces & Résidence')
                        ->icon('heroicon-o-square-3-stack-3d')
                        ->description('Surfaces pour le calcul de la quote-part')
                        ->schema([
                            Toggle::make('is_primary_residence')
                                ->label('Ce bien fait partie de ma résidence principale')
                                ->helperText('Si oui, seule la quote-part (surface louée ÷ totale) sera appliquée aux charges et amortissements.')
                                ->live(),
                            Grid::make(2)->schema([
                                TextInput::make('total_area')
                                    ->label('Surface totale déclarée')
                                    ->suffix('m²')
                                    ->required()
                                    ->numeric()
                                    ->minValue(1)
                                    ->live()
                                    ->hint('Telle que déclarée aux impôts')
                                    ->hintIcon('heroicon-o-question-mark-circle'),
                                TextInput::make('rented_area')
                                    ->label('Surface louée')
                                    ->suffix('m²')
                                    ->required()
                                    ->numeric()
                                    ->minValue(1)
                                    ->live()
                                    ->hint('Quote-part = louée ÷ totale')
                                    ->hintIcon('heroicon-o-question-mark-circle'),
                            ]),
                        ]),

                    Step::make('Acquisition & Valeur')
                        ->icon('heroicon-o-banknotes')
                        ->description('Prix d\'achat et valeur vénale pour l\'amortissement')
                        ->schema([
                            Grid::make(3)->schema([
                                TextInput::make('acquisition_price')
                                    ->label('Prix d\'achat')
                                    ->suffix('€')
                                    ->required()
                                    ->numeric()
                                    ->step(1)
                                    ->formatStateUsing(fn ($state) => $state ? number_format($state / 100, 0, '.', '') : null)
                                    ->dehydrateStateUsing(fn ($state) => (int) round(((float) $state) * 100))
                                    ->hint('Hors frais de notaire')
                                    ->hintIcon('heroicon-o-question-mark-circle'),
                                TextInput::make('notary_fees')
                                    ->label('Frais de notaire')
                                    ->suffix('€')
                                    ->numeric()
                                    ->step(1)
                                    ->default(0)
                                    ->formatStateUsing(fn ($state) => $state ? number_format($state / 100, 0, '.', '') : '0')
                                    ->dehydrateStateUsing(fn ($state) => (int) round(((float) $state) * 100)),
                                DatePicker::make('acquisition_date')
                                    ->label('Date d\'achat')
                                    ->required()
                                    ->displayFormat('d/m/Y'),
                            ]),
                            Grid::make(3)->schema([
                                TextInput::make('market_value')
                                    ->label('Valeur vénale')
                                    ->suffix('€')
                                    ->numeric()
                                    ->step(1)
                                    ->formatStateUsing(fn ($state) => $state ? number_format($state / 100, 0, '.', '') : null)
                                    ->dehydrateStateUsing(fn ($state) => $state ? (int) round(((float) $state) * 100) : null)
                                    ->hint('Si bien déjà possédé avant la location')
                                    ->hintIcon('heroicon-o-question-mark-circle'),
                                DatePicker::make('market_value_date')
                                    ->label('Date estimation')
                                    ->displayFormat('d/m/Y'),
                                TextInput::make('land_percentage')
                                    ->label('Terrain non amortissable')
                                    ->suffix('%')
                                    ->required()
                                    ->numeric()
                                    ->default(15)
                                    ->minValue(0)
                                    ->maxValue(50)
                                    ->hint('Généralement 15-20%')
                                    ->hintIcon('heroicon-o-question-mark-circle'),
                            ]),
                        ]),

                    Step::make('Location & Annonces')
                        ->icon('heroicon-o-calendar')
                        ->description('Début de location et liens vers vos annonces')
                        ->schema([
                            DatePicker::make('rental_start_date')
                                ->label('Date de début de location')
                                ->required()
                                ->displayFormat('d/m/Y')
                                ->hint('Les amortissements démarrent à cette date.')
                                ->hintIcon('heroicon-o-question-mark-circle'),
                            Repeater::make('listing_urls')
                                ->label('Liens d\'annonces')
                                ->schema([
                                    TextInput::make('label')
                                        ->label('Plateforme')
                                        ->placeholder('Airbnb, Booking...')
                                        ->required(),
                                    TextInput::make('url')
                                        ->label('URL')
                                        ->url()
                                        ->placeholder('https://...')
                                        ->required(),
                                ])
                                ->columns(2)
                                ->addActionLabel('Ajouter un lien')
                                ->defaultItems(0),
                            Textarea::make('notes')
                                ->label('Notes')
                                ->rows(3),
                        ]),

                ])
                ->columnSpanFull()
                ->skippable(),
            ]);
    }
}
