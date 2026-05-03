<?php

namespace App\Filament\Resources\PropertyWorks\Schemas;

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
                            ->default(fn () => ($ids = Property::where('user_id', auth()->id())->pluck('id'))->count() === 1 ? $ids->first() : null),
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
                                ->live(onBlur: true)
                                ->formatStateUsing(fn ($state) => $state ? number_format($state / 100, 2, '.', '') : null)
                                ->dehydrateStateUsing(fn ($state) => (int) round(((float) $state) * 100))
                                ->hintIcon('heroicon-o-question-mark-circle', tooltip: 'Coût total TTC des travaux'),
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
            ]);
    }
}
