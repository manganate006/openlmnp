<?php

namespace App\Filament\Resources\Expenses\Schemas;

use App\Enums\TvaRate;
use App\Filament\Schemas\DocumentsSection;
use App\Helpers\TvaHelper;
use App\Models\Expense;
use App\Models\Property;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class ExpenseForm
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
                Section::make('Charge')
                    ->icon('heroicon-o-receipt-percent')
                    ->schema([
                        Select::make('property_id')
                            ->label('Bien')
                            ->relationship('property', 'name')
                            ->required()
                            ->preload()
                            ->live()
                            ->default(fn () => ($ids = Property::where('user_id', auth()->id())->pluck('id'))->count() === 1 ? $ids->first() : null),
                        ...static::fields(),
                    ]),

                DocumentsSection::make('expense'),

                Section::make('Notes')
                    ->icon('heroicon-o-chat-bubble-left')
                    ->collapsed()
                    ->schema([
                        Textarea::make('notes')
                            ->label('Notes')
                            ->rows(2)
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    /**
     * Champs métier d'une charge, sans le sélecteur de bien ni les pièces
     * jointes.
     *
     * Extrait pour que l'écran de relecture de l'analyse IA
     * (App\Filament\Pages\ImportDocument) affiche EXACTEMENT les mêmes champs,
     * avec les mêmes libellés, options et conversions centimes/euros : une
     * seule source de vérité, sinon les deux écrans divergent au premier
     * changement. Ce formulaire-ci garde le select de bien (lié par relation),
     * l'écran de relecture porte le bien dans un champ caché.
     *
     * @return array<int, \Filament\Schemas\Components\Component>
     */
    public static function fields(): array
    {
        return [
            Grid::make(2)->schema([
                DatePicker::make('expense_date')
                    ->label('Date')
                    ->required()
                    ->displayFormat('d/m/Y')
                    ->default(now()),
                Select::make('category')
                    ->label('Catégorie')
                    ->options(Expense::categoryLabels())
                    ->required()
                    ->searchable()
                    ->hintIcon('heroicon-o-question-mark-circle', tooltip: 'Détermine le compte comptable utilisé (plan comptable LMNP)'),
            ]),
            TextInput::make('description')
                ->label('Description')
                ->required()
                ->placeholder('Ex : Taxe foncière 2026'),
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
                    ->hintIcon('heroicon-o-question-mark-circle', tooltip: 'Montant total de la charge. Si partagée, la quote-part sera calculée automatiquement.'),
                // Le montant saisi est celui d'UNE échéance, jamais un total annuel.
                // L'avertissement était un tooltip caché derrière une icône « ? »,
                // introuvable sur mobile : d'où l'issue #9, où une charge mensuelle
                // n'était comptée qu'une fois. Il est désormais toujours visible.
                Select::make('recurring_type')
                    ->label('Récurrence')
                    ->options(Expense::recurringLabels())
                    ->required()
                    ->default('once')
                    ->helperText('Le montant est celui d\'UNE échéance. Mensuel ou trimestriel : l\'action « Générer les échéances », dans la liste, crée les lignes de l\'année.'),
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
            Toggle::make('is_dedicated')
                ->label('Charge 100% dédiée au bien loué')
                ->helperText('Cochez si cette charge concerne UNIQUEMENT le bien loué (ex : ménage Airbnb, commission plateforme). Si non coché, la quote-part surface sera appliquée (ex : taxe foncière, électricité).')
                ->default(false),
            // Charge mixte, privée et location (#17) : la part d'usage s'applique AVANT la
            // quote-part surface, qui ne mesure que la part du logement louée.
            TextInput::make('business_use_bp')
                ->label("Part d'usage pour la location")
                ->suffix('%')
                ->numeric()
                ->minValue(0)
                ->maxValue(100)
                ->step(0.01)
                ->placeholder('100')
                ->formatStateUsing(fn ($state) => Expense::formatUsagePercent($state === null ? null : (int) $state, '.'))
                ->dehydrateStateUsing(fn ($state) => Expense::percentToBp($state))
                ->helperText("Laissez vide si la charge sert entièrement à la location. Pour un abonnement téléphone, internet ou un logiciel qui sert aussi à titre personnel, indiquez la part réservée à la location (ex. 30 %) : seule cette part est déduite. Cochez alors « 100% dédiée », sinon la quote-part surface s'applique en plus."),
        ];
    }
}
