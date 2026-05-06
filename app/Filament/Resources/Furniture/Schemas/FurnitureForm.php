<?php

namespace App\Filament\Resources\Furniture\Schemas;

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

class FurnitureForm
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
                Section::make('Équipement / Mobilier')
                    ->icon('heroicon-o-cube')
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
                            ->placeholder('Ex : Télévision, Lave-vaisselle...'),
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
                                ->hintIcon('heroicon-o-question-mark-circle', tooltip: 'Prix d\'achat TTC sur la facture'),
                            DatePicker::make('purchase_date')
                                ->label('Date d\'achat')
                                ->required()
                                ->displayFormat('d/m/Y'),
                        ]),
                        Grid::make(2)
                            ->schema([
                                Select::make('tva_rate')
                                    ->label('Taux de TVA')
                                    ->options(TvaRate::options())
                                    ->required()
                                    ->default(TvaRate::Standard20->value)
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
                                ->default(5)
                                ->live(onBlur: true)
                                ->hintIcon('heroicon-o-question-mark-circle', tooltip: 'Durées usuelles : Literie, linge, petits meubles → 5 ans · TV, réfrigérateur, lave-vaisselle → 7 ans · Cuisine équipée, climatisation, jacuzzi, bac à douche → 10 ans · Mobilier d\'occasion → 3 ans. En cas de contrôle, ces durées doivent refléter la durée réelle d\'utilisation.'),
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
                        Grid::make(2)->schema([
                            Toggle::make('is_dedicated')
                                ->label('100% dédié au bien loué')
                                ->helperText('Si non coché, la quote-part surface sera appliquée')
                                ->default(true),
                            Toggle::make('is_second_hand')
                                ->label('Acheté d\'occasion')
                                ->hintIcon('heroicon-o-question-mark-circle', tooltip: 'Occasion : conservez capture d\'annonce + preuve de paiement (virement de préférence) + photo en situation. Une attestation signée du vendeur est un plus.')
                                ->default(false)
                                ->live(),
                        ]),
                    ]),
                DocumentsSection::make(),
            ]);
    }
}
