<?php

namespace App\Filament\Resources\Properties\Schemas;

use App\Models\Property;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Repeater;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
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
                Tabs::make('Bien immobilier')
                    ->tabs([

                        Tab::make('Général')
                            ->icon('heroicon-o-home-modern')
                            ->schema([
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
                                        ->required()
                                        ->columnSpan(1),
                                    TextInput::make('city')
                                        ->label('Ville')
                                        ->required(),
                                    TextInput::make('postal_code')
                                        ->label('Code postal')
                                        ->required()
                                        ->maxLength(10),
                                ]),
                                Toggle::make('is_primary_residence')
                                    ->label('Résidence principale')
                                    ->helperText('Quote-part (surface louée ÷ totale) appliquée automatiquement aux charges et amortissements.'),
                            ]),

                        Tab::make('Surfaces & Valeur')
                            ->icon('heroicon-o-square-3-stack-3d')
                            ->schema([
                                Grid::make(2)->schema([
                                    TextInput::make('total_area')
                                        ->label('Surface totale déclarée')
                                        ->suffix('m²')
                                        ->required()
                                        ->numeric()
                                        ->minValue(1)
                                        ->hint('Telle que déclarée aux impôts')
                                        ->hintIcon('heroicon-o-question-mark-circle'),
                                    TextInput::make('rented_area')
                                        ->label('Surface louée')
                                        ->suffix('m²')
                                        ->required()
                                        ->numeric()
                                        ->minValue(1)
                                        ->hint('Quote-part = louée ÷ totale')
                                        ->hintIcon('heroicon-o-question-mark-circle'),
                                ]),
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
                                        ->hint('Base d\'amortissement si renseignée')
                                        ->hintIcon('heroicon-o-question-mark-circle'),
                                    DatePicker::make('market_value_date')
                                        ->label('Date estimation')
                                        ->displayFormat('d/m/Y'),
                                    TextInput::make('land_percentage')
                                        ->label('Terrain')
                                        ->suffix('%')
                                        ->required()
                                        ->numeric()
                                        ->default(15)
                                        ->minValue(0)
                                        ->maxValue(50)
                                        ->hint('Non amortissable. 15-20%.')
                                        ->hintIcon('heroicon-o-question-mark-circle'),
                                ]),
                            ]),

                        Tab::make('Location')
                            ->icon('heroicon-o-calendar')
                            ->schema([
                                DatePicker::make('rental_start_date')
                                    ->label('Date de début de location')
                                    ->required()
                                    ->displayFormat('d/m/Y')
                                    ->hint('Les amortissements démarrent à cette date.')
                                    ->hintIcon('heroicon-o-question-mark-circle'),
                                FileUpload::make('photo_path')
                                    ->label('Photo du bien')
                                    ->image()
                                    ->directory('properties')
                                    ->maxSize(5120),
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
                            ]),

                        Tab::make('Notes')
                            ->icon('heroicon-o-pencil-square')
                            ->schema([
                                Textarea::make('notes')
                                    ->label('Notes')
                                    ->rows(5)
                                    ->columnSpanFull(),
                            ]),

                    ])
                    ->columnSpanFull(),
            ]);
    }
}
