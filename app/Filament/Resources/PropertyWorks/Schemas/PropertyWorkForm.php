<?php

namespace App\Filament\Resources\PropertyWorks\Schemas;

use App\Enums\TvaRate;
use App\Filament\Schemas\DocumentsSection;
use App\Helpers\TvaHelper;
use App\Models\Property;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class PropertyWorkForm
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
                Section::make('Travaux')
                    ->icon('heroicon-o-wrench')
                    ->schema([
                        Select::make('property_id')
                            ->label('Bien')
                            ->relationship('property', 'name')
                            ->required()
                            ->preload()
                            ->live()
                            ->default(fn () => ($ids = Property::where('user_id', auth()->id())->pluck('id'))->count() === 1 ? $ids->first() : null)
                            ->hiddenOn(\Filament\Resources\RelationManagers\RelationManager::class),
                        TextInput::make('description')
                            ->label('Description')
                            ->required()
                            ->placeholder('Ex : Travaux aménagement, Piscine...'),
                        Grid::make(2)->schema([
                            TextInput::make('amount')
                                ->label(fn (callable $get) => static::isPropertyTvaLiable($get('property_id')) ? 'Montant TTC' : 'Montant')
                                ->suffix('€')
                                ->required()
                                ->numeric()
                                ->step(0.01)
                                ->live(onBlur: true)
                                ->formatStateUsing(fn ($state) => $state ? number_format($state / 100, 2, '.', '') : null)
                                ->dehydrateStateUsing(fn ($state) => (int) round(((float) $state) * 100))
                                ->hintIcon('heroicon-o-question-mark-circle', tooltip: 'Coût total TTC des travaux'),
                            DatePicker::make('work_date')
                                ->label('Date des travaux')
                                ->required()
                                ->displayFormat('d/m/Y'),
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

                                        return 'HT : ' . number_format($result['ht'] / 100, 2, ',', ' ') . ' € · TVA : ' . number_format($result['tva'] / 100, 2, ',', ' ') . ' €';
                                    }),
                            ])
                            ->visible(fn (callable $get) => static::isPropertyTvaLiable($get('property_id'))),
                        Grid::make(2)->schema([
                            TextInput::make('duration_years')
                                ->label('Durée d\'amortissement')
                                ->suffix('ans')
                                ->required()
                                ->numeric()
                                ->default(10)
                                ->live(onBlur: true)
                                ->hintIcon('heroicon-o-question-mark-circle', tooltip: 'Aménagement intérieur (peinture, sols, cloisons) → 10 ans · Salle de bain, cuisine → 10-15 ans · Piscine, terrasse, toiture → 15-20 ans · Mise aux normes électrique/plomberie → 15 ans. La durée doit refléter la durée réelle d\'utilisation du bien.'),
                            Placeholder::make('annual_depreciation_preview')
                                ->label('Amortissement annuel')
                                ->content(function (callable $get) {
                                    $amount = (float) ($get('amount') ?? 0);
                                    $duration = (int) ($get('duration_years') ?? 0);
                                    if ($amount <= 0 || $duration <= 0) {
                                        return '—';
                                    }
                                    $annual = $amount / $duration;
                                    return number_format($annual, 2, ',', ' ') . ' €/an';
                                }),
                        ]),
                        Toggle::make('is_dedicated')
                            ->label('100% dédié au bien loué')
                            ->helperText('Cochez si les travaux concernent uniquement la partie louée. Sinon, la quote-part surface sera appliquée (ex : piscine commune).')
                            ->default(true),
                    ]),

                static::repriseSection(),

                DocumentsSection::make(),
            ]);
    }

    /**
     * Reprise d'une comptabilité tenue par un cabinet.
     *
     * Deux réglages, repliés par défaut parce qu'ils ne servent qu'une fois :
     * la dotation recopiée telle quelle (l'arrondi du cabinet n'est pas forcément le
     * nôtre) et le cumul déjà pratiqué sur les exercices non repris.
     */
    public static function repriseSection(): Section
    {
        return Section::make('Reprise d\'une comptabilité existante')
            ->icon('heroicon-o-arrow-uturn-left')
            ->description('À renseigner uniquement si ces travaux figuraient déjà dans le plan d\'amortissement de votre comptable.')
            ->collapsed()
            ->schema([
                Select::make('depreciation_source')
                    ->label('Dotation annuelle')
                    ->options([
                        \App\Models\PropertyWork::DEPRECIATION_SOURCE_COMPUTED => 'Calculée (montant ÷ durée)',
                        \App\Models\PropertyWork::DEPRECIATION_SOURCE_MANUAL   => 'Recopiée de ma liasse',
                    ])
                    ->default(\App\Models\PropertyWork::DEPRECIATION_SOURCE_COMPUTED)
                    ->live(),
                TextInput::make('annual_depreciation')
                    ->label('Dotation annuelle recopiée')
                    ->suffix('€')
                    ->numeric()
                    ->step(0.01)
                    ->visible(fn (callable $get) => $get('depreciation_source') === \App\Models\PropertyWork::DEPRECIATION_SOURCE_MANUAL)
                    ->formatStateUsing(fn ($state) => $state ? number_format($state / 100, 2, '.', '') : null)
                    ->dehydrateStateUsing(fn ($state) => (int) round(((float) $state) * 100))
                    ->helperText('Telle qu\'elle figure sur le tableau 2033-C de votre liasse.'),
                TextInput::make('opening_accumulated_depreciation')
                    ->label('Amortissements déjà pratiqués')
                    ->suffix('€')
                    ->numeric()
                    ->step(0.01)
                    ->default(0)
                    ->formatStateUsing(fn ($state) => $state ? number_format($state / 100, 2, '.', '') : '0')
                    ->dehydrateStateUsing(fn ($state) => (int) round(((float) $state) * 100))
                    ->helperText('Cumul au 31/12 du dernier exercice tenu par votre comptable. Il s\'ajoute au cumul du bilan (2033-A case 030), jamais à la charge de l\'exercice.'),
            ]);
    }
}
