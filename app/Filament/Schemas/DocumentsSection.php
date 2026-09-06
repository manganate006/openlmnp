<?php

namespace App\Filament\Schemas;

use App\Support\DocumentStorage;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Illuminate\Support\Str;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

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
                        // ⚠️ `label` est `required()` ET la section est repliée : déposer un
                        // fichier sans taper de libellé fait échouer l'enregistrement, et le
                        // message d'erreur tombe HORS DU CHAMP DE VISION. L'utilisateur croit
                        // avoir joint sa pièce ; en réalité rien n'est écrit en base et le
                        // fichier reste dans `storage/app/private/livewire-tmp/`, où il n'est
                        // ramassé qu'au prochain téléversement et après 24 h. C'est le
                        // symptôme rapporté par cocool97 (issue #12).
                        // Le libellé est donc PRÉ-REMPLI depuis le nom du fichier déposé (voir
                        // `afterStateUpdated` du champ Fichier) : on supprime le mode d'échec
                        // au lieu de rendre son message plus visible.
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
                            // ⚠️ `openable()` et `downloadable()` sont ce qui rend le justificatif
                            // CONSULTABLE. Sans eux, la tuile est une vignette morte : rien d'autre
                            // dans l'application ne permet d'ouvrir une pièce jointe à une charge —
                            // il n'existe ni page de consultation, ni action dédiée, et
                            // `Document::$file_url` n'est référencé nulle part. Le seul chemin qui
                            // sortait un fichier était l'export ZIP des exercices. Signalé par
                            // cocool97 (issue #12) : « je n'arrive pas à visualiser les documents ».
                            // Le FileUpload des photos de bien, lui, appelle `openable()` depuis
                            // toujours — c'est l'écart qui a rendu le défaut invisible en relecture.
                            ->openable()
                            ->downloadable()
                            // Pré-remplit le libellé avec le nom du fichier déposé, s'il est
                            // vide. Sans quoi un dépôt suivi d'un enregistrement échoue en
                            // silence pour l'utilisateur (voir le commentaire du champ Libellé).
                            // On ne remplace jamais une saisie : `filled()` d'abord.
                            ->afterStateUpdated(function ($state, Get $get, Set $set): void {
                                if (filled($get('label'))) {
                                    return;
                                }

                                $fichier = is_array($state) ? reset($state) : $state;

                                if (! $fichier instanceof TemporaryUploadedFile) {
                                    return;
                                }

                                $nom = pathinfo($fichier->getClientOriginalName(), PATHINFO_FILENAME);

                                if ($nom !== '') {
                                    $set('label', Str::limit($nom, 120, ''));
                                }
                            })
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
