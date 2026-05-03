<?php

namespace App\Filament\Schemas;

use App\Support\DocumentStorage;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;

class DocumentsSection
{
    public static function make(): Section
    {
        return Section::make('Pièces justificatives')
            ->icon('heroicon-o-paper-clip')
            ->collapsed()
            ->schema([
                Repeater::make('documents')
                    ->relationship()
                    ->label('')
                    ->schema([
                        TextInput::make('label')
                            ->label('Libellé')
                            ->required()
                            ->placeholder('Ex : Acompte 1, Facture finale...'),
                        TextInput::make('amount')
                            ->label('Montant')
                            ->suffix('€')
                            ->numeric()
                            ->step(0.01)
                            ->formatStateUsing(fn ($state) => $state ? number_format($state / 100, 2, '.', '') : null)
                            ->dehydrateStateUsing(fn ($state) => $state !== null ? (int) round(((float) $state) * 100) : null)
                            ->hintIcon('heroicon-o-question-mark-circle', tooltip: 'Montant de cette pièce (optionnel, utile pour les acomptes)'),
                        DatePicker::make('document_date')
                            ->label('Date du document')
                            ->displayFormat('d/m/Y'),
                        FileUpload::make('file_path')
                            ->label('Fichier')
                            ->required()
                            ->acceptedFileTypes(['application/pdf', 'image/*', 'application/zip', 'application/x-zip-compressed'])
                            ->directory(DocumentStorage::directory('pieces-comptables'))
                            ->maxSize(10240)
                            ->hintIcon('heroicon-o-question-mark-circle', tooltip: 'PDF, image ou ZIP. Conservation obligatoire : 6 ans minimum.'),
                    ])
                    ->defaultItems(0)
                    ->addActionLabel('Ajouter un document')
                    ->reorderableWithButtons()
                    ->collapsible()
                    ->itemLabel(fn (array $state): string => $state['label'] ?? 'Document'),
            ]);
    }
}
