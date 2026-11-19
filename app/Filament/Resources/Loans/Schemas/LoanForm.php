<?php

namespace App\Filament\Resources\Loans\Schemas;

use App\Models\Loan;
use App\Models\Property;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Wizard;
use Filament\Schemas\Components\Wizard\Step;
use Filament\Schemas\Schema;

class LoanForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Wizard::make([
                    Step::make('Bien & Banque')
                        ->icon('heroicon-o-building-library')
                        ->schema([
                            Select::make('property_id')
                                ->label('Bien')
                                ->relationship('property', 'name')
                                ->required()
                                ->preload()
                                ->default(fn () => ($ids = Property::where('user_id', auth()->id())->pluck('id'))->count() === 1 ? $ids->first() : null),
                            TextInput::make('bank_name')
                                ->label('Banque')
                                ->placeholder('Ex : BNP, Crédit Agricole...'),
                        ]),

                    Step::make('Conditions de l\'emprunt')
                        ->icon('heroicon-o-banknotes')
                        ->schema([
                            Grid::make(2)->schema([
                                TextInput::make('amount')
                                    ->label('Montant emprunté')
                                    ->suffix('€')
                                    ->required()
                                    ->numeric()
                                    ->step(1)
                                    ->formatStateUsing(fn ($state) => $state ? number_format($state / 100, 0, '.', '') : null)
                                    ->dehydrateStateUsing(fn ($state) => (int) round(((float) $state) * 100))
                                    ->hintIcon('heroicon-o-question-mark-circle', tooltip: 'Capital emprunté en euros'),
                                TextInput::make('annual_rate')
                                    ->label('Taux annuel')
                                    ->suffix('%')
                                    ->required()
                                    ->numeric()
                                    ->step(0.001)
                                    ->hintIcon('heroicon-o-question-mark-circle', tooltip: 'Taux nominal annuel (ex : 1.5 pour 1,5%)'),
                            ]),
                            Grid::make(2)->schema([
                                TextInput::make('duration_months')
                                    ->label('Durée')
                                    ->suffix('mois')
                                    ->required()
                                    ->numeric()
                                    ->hintIcon('heroicon-o-question-mark-circle', tooltip: 'Durée totale en mois (ex : 240 = 20 ans)'),
                                DatePicker::make('start_date')
                                    ->label('Date de début')
                                    ->required()
                                    ->displayFormat('d/m/Y')
                                    ->hintIcon('heroicon-o-question-mark-circle', tooltip: 'Date de la 1ère échéance'),
                            ]),
                            TextInput::make('monthly_payment')
                                ->label('Mensualité (hors assurance)')
                                ->suffix('€')
                                ->numeric()
                                ->step(0.01)
                                ->default(0)
                                ->formatStateUsing(fn ($state) => $state ? number_format($state / 100, 2, '.', '') : '0')
                                ->dehydrateStateUsing(fn ($state) => (int) round(((float) $state) * 100))
                                ->hintIcon('heroicon-o-question-mark-circle', tooltip: 'Laissez à 0 pour un calcul automatique. Si vous saisissez un montant, il sera utilisé tel quel.'),
                            Toggle::make('is_dedicated')
                                ->label('Emprunt dédié à ce logement')
                                ->helperText('Cochez si cet emprunt finance exclusivement le logement loué (ex : prêt travaux du studio indépendant). Les intérêts et l\'assurance sont alors déduits à 100 %, sans quote-part. Sinon (achat de toute la propriété, résidence principale comprise), la quote-part surface s\'applique.')
                                ->default(false),
                            Grid::make(2)->schema([
                                TextInput::make('fees_amount')
                                    ->label('Frais d\'emprunt')
                                    ->suffix('€')
                                    ->numeric()
                                    ->step(0.01)
                                    ->default(0)
                                    ->live()
                                    ->formatStateUsing(fn ($state) => $state ? number_format($state / 100, 2, '.', '') : '0')
                                    ->dehydrateStateUsing(fn ($state) => (int) round(((float) $state) * 100))
                                    ->hintIcon('heroicon-o-question-mark-circle', tooltip: 'Frais de dossier, courtage, part non restituable d\'une caution. Déductibles, en une fois ou étalés selon votre option de dossier.'),
                                DatePicker::make('fees_paid_on')
                                    ->label('Date de paiement des frais')
                                    ->displayFormat('d/m/Y')
                                    ->hintIcon('heroicon-o-question-mark-circle', tooltip: 'Laissez vide pour reprendre la date de début de l\'emprunt.'),
                            ]),
                            Select::make('fees_spread_method')
                                ->label('Méthode d\'étalement des frais')
                                ->options(Loan::feesSpreadMethodLabels())
                                ->default(Loan::FEES_METHOD_LINEAR)
                                ->required()
                                ->visible(fn (callable $get) => (float) ($get('fees_amount') ?? 0) > 0)
                                ->helperText(
                                    'Ne joue QUE si vous avez choisi l\'étalement depuis votre profil (section Frais '
                                    . 'd\'emprunt). Fractions égales : même montant chaque année (défaut, BOI-BIC-'
                                    . 'CHG-20-30-40 § 70). Au prorata des intérêts : fraction plus grande les premières '
                                    . 'années, qui portent plus d\'intérêts (§ 100-110, nécessite un tableau '
                                    . 'd\'amortissement, sinon repli automatique sur les fractions égales).'
                                )
                                ->hintIcon('heroicon-o-question-mark-circle', tooltip: 'Méthode propre à CET emprunt : deux emprunts du même dossier peuvent en choisir des différentes.'),
                            Grid::make(2)->schema([
                                DatePicker::make('early_repayment_date')
                                    ->label('Date d\'un remboursement anticipé')
                                    ->displayFormat('d/m/Y')
                                    ->live()
                                    ->hintIcon('heroicon-o-question-mark-circle', tooltip: 'Laissez vide si l\'emprunt suit son échéancier normal.'),
                                TextInput::make('early_repayment_amount')
                                    ->label('Capital remboursé par anticipation')
                                    ->suffix('€')
                                    ->numeric()
                                    ->step(0.01)
                                    ->formatStateUsing(fn ($state) => $state ? number_format($state / 100, 2, '.', '') : null)
                                    ->dehydrateStateUsing(fn ($state) => filled($state) ? (int) round(((float) $state) * 100) : null)
                                    ->visible(fn (callable $get) => filled($get('early_repayment_date')))
                                    ->required(fn (callable $get) => filled($get('early_repayment_date')))
                                    ->hintIcon('heroicon-o-question-mark-circle', tooltip: 'Total du capital restant dû pour un remboursement intégral, ou le seul montant versé pour un remboursement partiel.'),
                            ]),
                        ]),

                    Step::make('Assurance emprunteur')
                        ->icon('heroicon-o-shield-check')
                        ->schema([
                            Placeholder::make('insurance_info')
                                ->label('')
                                ->content('L\'assurance emprunteur est déductible au prorata de la quote-part du bien loué.'),
                            Select::make('insurance_type')
                                ->label('Type d\'assurance')
                                ->options(Loan::insuranceTypeLabels())
                                ->default('fixed')
                                ->required()
                                ->live()
                                ->hintIcon('heroicon-o-question-mark-circle', tooltip: 'Fixe = même montant chaque mois. Variable = basé sur le capital restant dû.'),
                            TextInput::make('insurance_monthly')
                                ->label('Montant mensuel')
                                ->suffix('€/mois')
                                ->numeric()
                                ->step(0.01)
                                ->default(0)
                                ->formatStateUsing(fn ($state) => $state ? number_format($state / 100, 2, '.', '') : '0')
                                ->dehydrateStateUsing(fn ($state) => (int) round(((float) $state) * 100))
                                ->visible(fn (callable $get) => ($get('insurance_type') ?? 'fixed') === 'fixed')
                                ->hintIcon('heroicon-o-question-mark-circle', tooltip: 'Montant fixe prélevé chaque mois (ex : 75 pour 75€/mois)'),
                            TextInput::make('insurance_rate')
                                ->label('Taux annuel assurance')
                                ->suffix('%')
                                ->numeric()
                                ->step(0.001)
                                ->default(0)
                                ->visible(fn (callable $get) => ($get('insurance_type') ?? 'fixed') === 'variable')
                                ->hintIcon('heroicon-o-question-mark-circle', tooltip: 'Taux appliqué au capital restant dû (ex : 0.36 pour 0,36%/an). Le montant baisse avec le capital.'),
                        ]),

                    Step::make('Récapitulatif')
                        ->icon('heroicon-o-check-circle')
                        ->schema([
                            Placeholder::make('summary_property')
                                ->label('Bien immobilier')
                                ->content(fn (callable $get) => $get('property_id')
                                    ? 'Bien sélectionné (ID : ' . $get('property_id') . ')'
                                    : '—'),
                            Placeholder::make('summary_bank')
                                ->label('Banque')
                                ->content(fn (callable $get) => $get('bank_name') ?: '—'),
                            Grid::make(2)->schema([
                                Placeholder::make('summary_amount')
                                    ->label('Montant emprunté')
                                    ->content(fn (callable $get) => $get('amount')
                                        ? number_format((float) $get('amount'), 0, ',', ' ') . ' €'
                                        : '—'),
                                Placeholder::make('summary_annual_rate')
                                    ->label('Taux annuel')
                                    ->content(fn (callable $get) => $get('annual_rate')
                                        ? $get('annual_rate') . ' %'
                                        : '—'),
                            ]),
                            Grid::make(2)->schema([
                                Placeholder::make('summary_duration')
                                    ->label('Durée')
                                    ->content(fn (callable $get) => $get('duration_months')
                                        ? $get('duration_months') . ' mois'
                                        : '—'),
                                Placeholder::make('summary_start_date')
                                    ->label('Date de début')
                                    ->content(fn (callable $get) => $get('start_date')
                                        ? \Carbon\Carbon::parse($get('start_date'))->format('d/m/Y')
                                        : '—'),
                            ]),
                            Placeholder::make('summary_monthly_payment')
                                ->label('Mensualité (hors assurance)')
                                ->content(fn (callable $get) => ($get('monthly_payment') && (float) $get('monthly_payment') > 0)
                                    ? number_format((float) $get('monthly_payment'), 2, ',', ' ') . ' €'
                                    : 'Calcul automatique'),
                            Placeholder::make('summary_dedicated')
                                ->label('Quote-part')
                                ->content(fn (callable $get) => $get('is_dedicated')
                                    ? 'Emprunt dédié — déduit à 100 %, sans quote-part'
                                    : 'Quote-part surface appliquée'),
                            Placeholder::make('summary_insurance')
                                ->label('Assurance emprunteur')
                                ->content(function (callable $get) {
                                    $type = $get('insurance_type') ?? 'fixed';
                                    $labels = Loan::insuranceTypeLabels();
                                    $typeLabel = $labels[$type] ?? $type;

                                    if ($type === 'fixed') {
                                        $monthly = $get('insurance_monthly');
                                        $amount = ($monthly && (float) $monthly > 0)
                                            ? number_format((float) $monthly, 2, ',', ' ') . ' €/mois'
                                            : '0 €/mois';

                                        return $typeLabel . ' — ' . $amount;
                                    }

                                    $rate = $get('insurance_rate');
                                    $rateDisplay = ($rate && (float) $rate > 0)
                                        ? $rate . ' %/an'
                                        : '0 %/an';

                                    return $typeLabel . ' — ' . $rateDisplay;
                                }),
                            Placeholder::make('summary_fees')
                                ->label('Frais d\'emprunt')
                                ->content(function (callable $get) {
                                    if (! $get('fees_amount') || (float) $get('fees_amount') <= 0) {
                                        return 'Aucun';
                                    }

                                    $labels = Loan::feesSpreadMethodLabels();
                                    $method = $labels[$get('fees_spread_method') ?? Loan::FEES_METHOD_LINEAR] ?? '-';

                                    return number_format((float) $get('fees_amount'), 2, ',', ' ')
                                        . ' € : déduction immédiate ou étalée selon l\'option choisie dans votre profil'
                                        . ' (méthode si étalement : ' . $method . ')';
                                }),
                            Placeholder::make('summary_early_repayment')
                                ->label('Remboursement anticipé')
                                ->content(fn (callable $get) => filled($get('early_repayment_date'))
                                    ? \Carbon\Carbon::parse($get('early_repayment_date'))->format('d/m/Y')
                                        . ($get('early_repayment_amount') ? ' (' . number_format((float) $get('early_repayment_amount'), 2, ',', ' ') . ' €)' : '')
                                    : 'Aucun'),
                        ]),
                ])
                ->columnSpanFull()
                ->skippable(),
            ]);
    }
}
