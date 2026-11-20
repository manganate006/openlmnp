<?php

namespace App\Filament\Resources\Leases\Schemas;

use App\Filament\Schemas\DocumentsSection;
use App\Helpers\TvaHelper;
use App\Models\Lease;
use App\Models\Property;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class LeaseForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Bail')
                    ->icon('heroicon-o-key')
                    ->schema([
                        Select::make('property_id')
                            ->label('Bien')
                            ->relationship('property', 'name')
                            ->required()
                            ->preload()
                            ->live()
                            ->default(fn () => ($ids = Property::where('user_id', auth()->id())->pluck('id'))->count() === 1 ? $ids->first() : null),
                        TextInput::make('tenant_name')
                            ->label('Locataire')
                            ->required()
                            ->maxLength(255)
                            ->hintIcon('heroicon-o-question-mark-circle', tooltip: 'Le nom tel qu\'il figure au bail. Il est recopié sur chaque loyer généré.'),
                        Grid::make(2)->schema([
                            DatePicker::make('start_date')
                                ->label('Début du bail')
                                ->required()
                                ->displayFormat('d/m/Y')
                                ->live(onBlur: true)
                                // La garde du modèle refuse aussi le chevauchement, mais par
                                // une exception qui finirait en erreur 500 : la règle le dit
                                // ici, sous le champ, avec le même message.
                                ->rule(fn (Get $get, ?Lease $record) => function (string $attribute, $value, \Closure $fail) use ($get, $record) {
                                    $other = static::overlappingLease($get, $record, $value);

                                    if ($other !== null) {
                                        $fail(Lease::overlapMessage($other));
                                    }
                                }),
                            DatePicker::make('end_date')
                                ->label('Fin du bail')
                                ->displayFormat('d/m/Y')
                                ->afterOrEqual('start_date')
                                ->validationMessages(['after_or_equal' => 'La fin du bail ne peut pas précéder son début.'])
                                ->helperText('Laissez vide tant que le bail court. Renseignez-la au départ du locataire, avant de saisir le bail suivant.'),
                        ]),
                    ]),

                Section::make('Loyer')
                    ->icon('heroicon-o-banknotes')
                    ->schema([
                        Grid::make(3)->schema([
                            TextInput::make('rent_amount')
                                ->label('Loyer hors charges')
                                ->suffix('€')
                                ->required()
                                ->numeric()
                                ->step(0.01)
                                ->minValue(0.01)
                                ->live(onBlur: true)
                                ->formatStateUsing(fn ($state) => $state ? number_format($state / 100, 2, '.', '') : null)
                                ->dehydrateStateUsing(fn ($state) => (int) round(((float) $state) * 100)),
                            TextInput::make('charges_amount')
                                ->label('Charges')
                                ->suffix('€')
                                ->numeric()
                                ->step(0.01)
                                ->minValue(0)
                                ->default(0)
                                ->live(onBlur: true)
                                ->formatStateUsing(fn ($state) => $state ? number_format($state / 100, 2, '.', '') : '0')
                                ->dehydrateStateUsing(fn ($state) => (int) round(((float) $state) * 100)),
                            Select::make('charges_mode')
                                ->label('Charges')
                                ->options(Lease::chargesModeLabels())
                                ->default(Lease::CHARGES_PROVISIONS)
                                ->required()
                                ->selectablePlaceholder(false)
                                ->hintIcon('heroicon-o-question-mark-circle', tooltip: 'Au forfait : le montant est acquis, rien à régulariser. En provisions : une régularisation annuelle sur les dépenses réelles, à saisir à part.'),
                        ]),
                        Grid::make(2)->schema([
                            TextInput::make('due_day')
                                ->label('Jour d\'échéance')
                                ->required()
                                ->integer()
                                ->minValue(1)
                                ->maxValue(31)
                                ->default(1)
                                ->helperText('Chaque loyer est daté de ce jour. Un 29, 30 ou 31 tombe le dernier jour des mois plus courts.'),
                            Placeholder::make('monthly_total')
                                ->label('Recette mensuelle')
                                ->content(fn (Get $get): string => static::monthlyTotal($get('rent_amount'), $get('charges_amount')))
                                ->helperText('Loyer et charges : c\'est ce que verse le locataire, donc ce que vous déclarez.'),
                        ]),
                        Textarea::make('notes')
                            ->label('Notes')
                            ->rows(2),
                    ]),

                DocumentsSection::make(),
            ]);
    }

    /**
     * Le bail que le formulaire chevaucherait, sur le bien choisi.
     *
     * Le candidat est une copie du bail édité, pour que `overlappingLeases()` l'écarte de
     * sa propre recherche, ou un bail neuf. Rien n'est enregistré.
     */
    private static function overlappingLease(Get $get, ?Lease $record, mixed $start): ?Lease
    {
        if (blank($get('property_id')) || blank($start)) {
            return null;
        }

        $candidate = $record instanceof Lease ? clone $record : new Lease();
        $candidate->forceFill([
            'property_id' => (int) $get('property_id'),
            'start_date'  => $start,
            'end_date'    => blank($get('end_date')) ? null : $get('end_date'),
        ]);

        return $candidate->overlappingLeases()->first();
    }

    /** Somme affichée en euros, les champs étant en euros tant qu'ils ne sont pas enregistrés. */
    private static function monthlyTotal(mixed $rent, mixed $charges): string
    {
        if (! is_numeric($rent)) {
            return '-';
        }

        $cents = (int) round(((float) $rent) * 100) + (int) round(((float) (is_numeric($charges) ? $charges : 0)) * 100);

        return TvaHelper::formatEuros($cents);
    }
}
