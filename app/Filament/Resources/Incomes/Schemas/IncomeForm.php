<?php

namespace App\Filament\Resources\Incomes\Schemas;

use App\Enums\TvaRate;
use App\Helpers\TvaHelper;
use App\Models\Income;
use App\Models\Property;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class IncomeForm
{
    private static function isPropertyTvaLiable(?int $propertyId): bool
    {
        if (! $propertyId) {
            return false;
        }

        return Property::find($propertyId)?->isTvaLiable() ?? false;
    }

    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Recette')
                    ->icon('heroicon-o-banknotes')
                    ->schema([
                        Select::make('property_id')
                            ->label('Bien')
                            ->relationship('property', 'name')
                            ->required()
                            ->preload()
                            ->live()
                            ->default(fn () => ($ids = Property::where('user_id', auth()->id())->pluck('id'))->count() === 1 ? $ids->first() : null),
                        Grid::make(2)->schema([
                            DatePicker::make('income_date')
                                ->label('Date')
                                ->required()
                                ->displayFormat('d/m/Y')
                                ->default(now()),
                            Select::make('source')
                                ->label('Source')
                                ->options(Income::sourceLabels())
                                ->required()
                                ->default('airbnb')
                                ->hintIcon('heroicon-o-question-mark-circle', tooltip: 'Plateforme ou mode de location utilisé'),
                        ]),
                        Grid::make(3)->schema([
                            TextInput::make('amount')
                                ->label(fn (callable $get) => static::isPropertyTvaLiable($get('property_id')) ? 'Montant loyer TTC' : 'Montant loyer')
                                ->suffix('€')
                                ->required()
                                ->numeric()
                                ->step(0.01)
                                ->live(onBlur: true)
                                ->formatStateUsing(fn ($state) => $state ? number_format($state / 100, 2, '.', '') : null)
                                ->dehydrateStateUsing(fn ($state) => (int) round(((float) $state) * 100))
                                ->hintIcon('heroicon-o-question-mark-circle', tooltip: 'Montant brut perçu du locataire, avant déduction de la commission'),
                            TextInput::make('platform_fee')
                                ->label('Commission plateforme')
                                ->suffix('€')
                                ->numeric()
                                ->step(0.01)
                                ->default(0)
                                ->formatStateUsing(fn ($state) => $state ? number_format($state / 100, 2, '.', '') : '0')
                                ->dehydrateStateUsing(fn ($state) => (int) round(((float) $state) * 100))
                                ->hintIcon('heroicon-o-question-mark-circle', tooltip: 'Commission Airbnb/Booking (~3%). Déduite automatiquement du calcul fiscal.'),
                            TextInput::make('tourist_tax')
                                ->label('Taxe de séjour')
                                ->suffix('€')
                                ->numeric()
                                ->step(0.01)
                                ->default(0)
                                ->formatStateUsing(fn ($state) => $state ? number_format($state / 100, 2, '.', '') : '0')
                                ->dehydrateStateUsing(fn ($state) => (int) round(((float) $state) * 100))
                                ->hintIcon('heroicon-o-question-mark-circle', tooltip: 'Non imposable. Collectée pour la commune, pas incluse dans vos recettes.'),
                        ]),
                        Grid::make(2)
                            ->schema([
                                Select::make('tva_rate')
                                    ->label('Taux de TVA')
                                    ->options(TvaRate::options())
                                    ->required()
                                    ->default(TvaRate::Reduced10->value)
                                    ->live(),
                                Placeholder::make('tva_preview')
                                    ->label('Décomposition TVA')
                                    ->content(function (callable $get) {
                                        $amount = (float) ($get('amount') ?? 0);
                                        $rate = (int) ($get('tva_rate') ?? 0);
                                        if ($amount <= 0 || $rate <= 0) {
                                            return '—';
                                        }
                                        $ttcCents = (int) round($amount * 100);
                                        $result = TvaHelper::fromTtc($ttcCents, $rate);

                                        return 'HT : ' . number_format($result['ht'] / 100, 2, ',', ' ') . ' € · TVA collectée : ' . number_format($result['tva'] / 100, 2, ',', ' ') . ' €';
                                    }),
                            ])
                            ->visible(fn (callable $get) => static::isPropertyTvaLiable($get('property_id'))),
                    ]),

                Section::make('Réservation')
                    ->icon('heroicon-o-calendar')
                    ->collapsed()
                    ->schema([
                        TextInput::make('guest_name')
                            ->label('Nom du client'),
                        TextInput::make('reservation_ref')
                            ->label('Référence réservation')
                            ->hintIcon('heroicon-o-question-mark-circle', tooltip: 'Code de confirmation Airbnb. Sert à éviter les doublons lors de l\'import CSV.'),
                        Grid::make(2)->schema([
                            DatePicker::make('checkin_date')
                                ->label('Arrivée')
                                ->displayFormat('d/m/Y'),
                            DatePicker::make('checkout_date')
                                ->label('Départ')
                                ->displayFormat('d/m/Y'),
                        ]),
                        Textarea::make('notes')
                            ->label('Notes')
                            ->rows(2)
                            ->columnSpanFull(),
                    ]),
            ]);
    }
}
