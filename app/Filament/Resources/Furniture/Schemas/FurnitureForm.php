<?php

namespace App\Filament\Resources\Furniture\Schemas;

use App\Support\DocumentStorage;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class FurnitureForm
{
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
                            ->preload(),
                        TextInput::make('description')
                            ->label('Description')
                            ->required()
                            ->placeholder('Ex : Télévision, Lave-vaisselle...'),
                        Grid::make(2)->schema([
                            TextInput::make('amount')
                                ->label('Montant')
                                ->suffix('€')
                                ->required()
                                ->numeric()
                                ->step(0.01)
                                ->formatStateUsing(fn ($state) => $state ? number_format($state / 100, 2, '.', '') : null)
                                ->dehydrateStateUsing(fn ($state) => (int) round(((float) $state) * 100))
                                ->hintIcon('heroicon-o-question-mark-circle', tooltip: 'Prix d\'achat TTC sur la facture'),
                            DatePicker::make('purchase_date')
                                ->label('Date d\'achat')
                                ->required()
                                ->displayFormat('d/m/Y'),
                        ]),
                        Grid::make(2)->schema([
                            TextInput::make('duration_years')
                                ->label('Durée d\'amortissement')
                                ->suffix('ans')
                                ->required()
                                ->numeric()
                                ->default(5)
                                ->hintIcon('heroicon-o-question-mark-circle', tooltip: 'Standards : mobilier 5-7 ans, électroménager 7-10 ans'),
                            TextInput::make('annual_depreciation')
                                ->label('Amortissement annuel')
                                ->suffix('€')
                                ->numeric()
                                ->formatStateUsing(fn ($state) => $state ? number_format($state / 100, 2, '.', '') : null)
                                ->dehydrateStateUsing(fn ($state) => (int) round(((float) $state) * 100))
                                ->hintIcon('heroicon-o-question-mark-circle', tooltip: 'Calculé automatiquement si laissé vide'),
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
                Section::make(fn (callable $get) => ($get('is_second_hand') ?? false) ? 'Justificatifs' : 'Facture')
                    ->icon('heroicon-o-paper-clip')
                    ->collapsed()
                    ->schema([
                        FileUpload::make('invoice_path')
                            ->label(fn (callable $get) => ($get('is_second_hand') ?? false)
                                ? 'Justificatifs (ZIP, PDF ou photo)'
                                : 'Facture d\'achat')
                            ->acceptedFileTypes(['application/pdf', 'image/*', 'application/zip', 'application/x-zip-compressed'])
                            ->disk('public')
                            ->directory(DocumentStorage::directory('factures-mobilier'))
                            ->getUploadedFileNameForStorageUsing(
                                DocumentStorage::filename('purchase_date', 'description')
                            )
                            ->maxSize(10240)
                            ->hintIcon('heroicon-o-question-mark-circle', tooltip: fn (callable $get) => ($get('is_second_hand') ?? false)
                                ? 'Regroupez dans un ZIP : capture de l\'annonce, preuve de paiement (virement), attestation vendeur, photo en situation. Conservation : 6 ans.'
                                : 'PDF ou photo de la facture. Conservation obligatoire : 6 ans minimum.'),
                    ]),
            ]);
    }
}
