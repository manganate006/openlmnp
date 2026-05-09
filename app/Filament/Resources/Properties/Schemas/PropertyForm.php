<?php

namespace App\Filament\Resources\Properties\Schemas;

use App\Models\Property;
use App\Support\DocumentStorage;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Repeater;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
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
        $isEdit = $schema->getOperation() === 'edit';

        if ($isEdit) {
            return static::tabsLayout($schema);
        }

        return static::wizardLayout($schema);
    }

    // -------------------------------------------------------------------------
    // Wizard (création)
    // -------------------------------------------------------------------------

    private static function wizardLayout(Schema $schema): Schema
    {
        return $schema
            ->components([
                Wizard::make([
                    Step::make('Informations générales')
                        ->icon('heroicon-o-home-modern')
                        ->description('Nom, type et adresse du bien')
                        ->schema(static::generalFields()),

                    Step::make('Surfaces & Résidence')
                        ->icon('heroicon-o-square-3-stack-3d')
                        ->description('Surfaces pour le calcul de la quote-part')
                        ->schema(static::surfaceFields()),

                    Step::make('Acquisition & Valeur')
                        ->icon('heroicon-o-banknotes')
                        ->description('Prix d\'achat et valeur vénale pour l\'amortissement')
                        ->schema(static::acquisitionFields()),

                    Step::make('Location & Annonces')
                        ->icon('heroicon-o-calendar')
                        ->description('Début de location et liens vers vos annonces')
                        ->schema(static::rentalFields()),
                ])
                ->columnSpanFull()
                ->skippable(),
            ]);
    }

    // -------------------------------------------------------------------------
    // Tabs (édition)
    // -------------------------------------------------------------------------

    private static function tabsLayout(Schema $schema): Schema
    {
        return $schema
            ->components([
                Tabs::make('Bien immobilier')
                    ->tabs([
                        Tab::make('Identité')
                            ->icon('heroicon-o-home-modern')
                            ->schema([
                                ...static::generalFields(),
                                Textarea::make('notes')
                                    ->label('Notes')
                                    ->rows(3)
                                    ->columnSpanFull(),
                            ]),

                        Tab::make('Surfaces & Valeur')
                            ->icon('heroicon-o-square-3-stack-3d')
                            ->schema([
                                ...static::surfaceFields(),
                                ...static::acquisitionFields(),
                            ]),

                        Tab::make('Location')
                            ->icon('heroicon-o-calendar')
                            ->schema(static::rentalFields()),
                    ])
                    ->columnSpanFull(),
            ]);
    }

    // -------------------------------------------------------------------------
    // Champs partagés
    // -------------------------------------------------------------------------

    public static function generalFields(): array
    {
        return [
            FileUpload::make('photo_path')
                ->label('Photo du bien')
                ->image()
                ->directory(DocumentStorage::directory('photos-biens'))
                ->getUploadedFileNameForStorageUsing(
                    DocumentStorage::filename('acquisition_date', 'name')
                )
                ->automaticallyResizeImagesMode('cover')
                ->automaticallyResizeImagesToWidth('1280')
                ->automaticallyResizeImagesToHeight('1280')
                ->maxSize(10240)
                ->imagePreviewHeight('250')
                ->panelLayout('integrated')
                ->openable()
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
                    ->hintIcon('heroicon-o-question-mark-circle', tooltip: 'Saisonnier = Airbnb. Longue durée = bail.'),
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
        ];
    }

    public static function surfaceFields(): array
    {
        return [
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
                    ->hintIcon('heroicon-o-question-mark-circle', tooltip: 'Telle que déclarée aux impôts'),
                TextInput::make('rented_area')
                    ->label('Surface louée')
                    ->suffix('m²')
                    ->required()
                    ->numeric()
                    ->minValue(1)
                    ->live()
                    ->hintIcon('heroicon-o-question-mark-circle', tooltip: 'Quote-part = louée ÷ totale'),
            ]),
        ];
    }

    public static function acquisitionFields(): array
    {
        return [
            Grid::make(3)->schema([
                TextInput::make('acquisition_price')
                    ->label('Prix d\'achat')
                    ->suffix('€')
                    ->required()
                    ->numeric()
                    ->step(1)
                    ->formatStateUsing(fn ($state) => $state ? number_format($state / 100, 0, '.', '') : null)
                    ->dehydrateStateUsing(fn ($state) => (int) round(((float) $state) * 100))
                    ->hintIcon('heroicon-o-question-mark-circle', tooltip: 'Hors frais de notaire'),
                TextInput::make('notary_fees')
                    ->label('Frais de notaire')
                    ->suffix('€')
                    ->numeric()
                    ->step(1)
                    ->default(0)
                    ->formatStateUsing(fn ($state) => $state ? number_format($state / 100, 0, '.', '') : '0')
                    ->dehydrateStateUsing(fn ($state) => (int) round(((float) $state) * 100))
                    ->hintIcon('heroicon-o-question-mark-circle', tooltip: 'Amortis sur 25 ans, avec quote-part si résidence principale'),
                TextInput::make('agency_fees')
                    ->label('Honoraires agence')
                    ->suffix('€')
                    ->numeric()
                    ->step(1)
                    ->default(0)
                    ->formatStateUsing(fn ($state) => $state ? number_format($state / 100, 0, '.', '') : '0')
                    ->dehydrateStateUsing(fn ($state) => (int) round(((float) $state) * 100))
                    ->hintIcon('heroicon-o-question-mark-circle', tooltip: 'Amortis sur 25 ans, avec quote-part si résidence principale'),
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
                    ->hintIcon('heroicon-o-question-mark-circle', tooltip: 'Si bien déjà possédé avant la location'),
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
                    ->hintIcon('heroicon-o-question-mark-circle', tooltip: 'Généralement 15-20%'),
            ]),
        ];
    }

    public static function rentalFields(): array
    {
        return [
            DatePicker::make('rental_start_date')
                ->label('Date de début de location')
                ->required()
                ->displayFormat('d/m/Y')
                ->hintIcon('heroicon-o-question-mark-circle', tooltip: 'Date de démarrage du LMNP au régime réel. Sert de point de départ pour le calcul des amortissements (prorata temporis).')
                ->hintIcon('heroicon-o-question-mark-circle', tooltip: 'Les amortissements démarrent à cette date.'),
            Select::make('tva_regime')
                ->label('Régime de TVA')
                ->options(Property::tvaRegimeLabels())
                ->required()
                ->default(Property::TVA_EXEMPT)
                ->hintIcon('heroicon-o-question-mark-circle', tooltip: 'Sélectionnez « Assujetti TVA » si vous fournissez au moins 3 services para-hôteliers sur 4 (petit-déjeuner, linge, ménage, accueil). Vous collecterez alors la TVA à 10 % sur les loyers et pourrez déduire la TVA sur vos charges.'),
            TextInput::make('airbnb_commission_rate')
                ->label('Taux commission Airbnb (hôte)')
                ->suffix('%')
                ->numeric()
                ->step(0.01)
                ->minValue(0)
                ->maxValue(30)
                ->default(3.6)
                ->placeholder('3.6')
                ->hintIcon('heroicon-o-question-mark-circle', tooltip: 'Frais de service hôte + TVA. Modèle split fee = 3% + TVA (20%) = 3,6%. Utilisé pour recalculer le brut lors de l\'import CSV Réservations.'),
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
        ];
    }
}
