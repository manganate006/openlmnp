<?php

namespace App\Filament\Schemas;

use App\Models\Property;
use App\Services\Ai\DocumentAnalysisService;
use App\Services\Ai\Exceptions\AiException;
use App\Support\AiAccess;
use App\Support\DocumentStorage;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Illuminate\Support\Str;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use RuntimeException;

class DocumentsSection
{
    /**
     * Champs pré-remplissables par l'analyse IA, par type de formulaire hôte.
     *
     * Sert à deux choses : savoir quoi écrire, et savoir quoi NE PAS écraser.
     */
    private const PREFILLABLE = [
        'expense' => ['expense_date', 'amount', 'tva_rate', 'category', 'description', 'recurring_type'],
        'furniture' => ['purchase_date', 'amount', 'tva_rate', 'description', 'duration_years', 'is_second_hand'],
        'work' => ['work_date', 'amount', 'tva_rate', 'description', 'duration_years'],
    ];

    /** Libellés français des champs, pour la notification « champs non modifiés ». */
    private const LABELS = [
        'expense_date' => 'date', 'purchase_date' => 'date', 'work_date' => 'date',
        'amount' => 'montant', 'tva_rate' => 'taux de TVA', 'category' => 'catégorie',
        'description' => 'description', 'recurring_type' => 'récurrence',
        'duration_years' => 'durée d\'amortissement', 'is_second_hand' => 'occasion',
    ];

    /**
     * @param  string|null  $aiType  expense | furniture | work — active le
     *                               pré-remplissage IA depuis le formulaire hôte.
     */
    public static function make(?string $aiType = null): Section
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
                        // `protect()` (coffre-fort) enveloppe le champ : il chiffre à la
                        // persistance et sert un aperçu déchiffré. `openable()` /
                        // `downloadable()` (v1.6.1) restent à l'intérieur — ce sont eux qui
                        // rendent la pièce CONSULTABLE, et rien d'autre dans l'application ne
                        // le permet. Les deux sont nécessaires : l'un protège, l'autre donne
                        // accès.
                        DocumentStorage::protect(
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
                                ->preventFilePathTampering(allowFilePathUsing: DocumentStorage::belongsToCurrentUser())
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
                                ->hintIcon('heroicon-o-question-mark-circle', tooltip: 'PDF, image ou ZIP. Conservation obligatoire : 6 ans minimum.')
                        ),
                    ])
                    ->defaultItems(0)
                    ->addActionLabel('Ajouter un document')
                    ->reorderableWithButtons()
                    ->collapsible()
                    ->itemLabel(fn (array $state): string => $state['label'] ?? 'Document')
                    ->extraItemActions($aiType === null ? [] : [static::prefillAction($aiType)]),
            ]);
    }

    /**
     * « Pré-remplir depuis ce document » — analyse la pièce jointe et remplit
     * les champs VIDES du formulaire hôte.
     *
     * Ne jamais écraser une saisie sans le dire : les champs déjà renseignés et
     * différents sont laissés tels quels et listés dans la notification. Aucune
     * création ici — c'est le bouton « Créer » du formulaire hôte qui enregistre.
     */
    private static function prefillAction(string $aiType): Action
    {
        return Action::make('aiPrefill')
            ->label('Pré-remplir depuis ce document')
            ->icon('heroicon-o-sparkles')
            ->color('gray')
            ->visible(fn () => config('ai.enabled') && auth()->check() && AiAccess::for(auth()->user())->available())
            ->action(function (array $arguments, Repeater $component, callable $get, callable $set) use ($aiType) {
                // ⚠️ `getRawItemState()` et JAMAIS `getItemState()`. Le second
                // DÉSHYDRATE : il déclenche `saveUploadedFiles()`, donc
                // `DocumentStorage::encryptOnSave()`, qui scelle le fichier puis
                // supprime le temporaire Livewire — le tout AVANT que cette action
                // ait vu le moindre octet. Coffre actif, l'action provoquait ainsi
                // son propre échec, et le finfo sur des octets chiffrés rendait
                // « Format non pris en charge » : un message faux sur un fichier
                // parfait. Un refactor bien intentionné le réintroduira ; ce
                // commentaire est là pour l'en dissuader.
                $item = $component->getRawItemState($arguments['item']);
                $file = is_array($item['file_path'] ?? null) ? reset($item['file_path']) : ($item['file_path'] ?? null);
                $property = Property::find($get('property_id'));

                if (blank($file) || $property === null) {
                    Notification::make()
                        ->title('Sélectionnez d\'abord un bien et déposez un fichier.')
                        ->warning()
                        ->send();

                    return;
                }

                try {
                    $analysis = app(DocumentAnalysisService::class)
                        ->analyze(
                            // Dépôt en cours : le temporaire Livewire, en clair, est
                            // le SEUL moment où le serveur peut encore lire la pièce
                            // d'un porteur de coffre. Pièce déjà enregistrée : le
                            // chemin, et `DocumentFile` refusera en nommant le coffre
                            // si elle est scellée.
                            $file instanceof TemporaryUploadedFile ? $file : (string) $file,
                            $property,
                            static::documentTypeFor($aiType),
                        );
                } catch (AiException|RuntimeException $e) {
                    Notification::make()->title($e->getMessage())->danger()->send();

                    return;
                }

                [$filled, $skipped] = static::applyToHostForm($analysis->values, $aiType, $get, $set);

                static::notify($filled, $skipped);
            });
    }

    /**
     * Écrit les valeurs proposées dans les champs vides du formulaire hôte.
     *
     * @return array{0: int, 1: array<int, string>} Champs remplis, champs laissés tels quels.
     */
    private static function applyToHostForm(array $values, string $aiType, callable $get, callable $set): array
    {
        $filled = 0;
        $skipped = [];

        foreach (self::PREFILLABLE[$aiType] ?? [] as $field) {
            $proposed = $values[$field] ?? null;

            if ($proposed === null || $proposed === '') {
                continue;
            }

            // Les montants du formulaire hôte sont en euros (formatStateUsing) ;
            // le service raisonne en centimes.
            if ($field === 'amount') {
                $proposed = number_format($proposed / 100, 2, '.', '');
            }

            $current = $get($field);

            if (blank($current)) {
                $set($field, $proposed);
                $filled++;

                continue;
            }

            if ((string) $current !== (string) $proposed) {
                $skipped[] = self::LABELS[$field] ?? $field;
            }
        }

        return [$filled, $skipped];
    }

    private static function notify(int $filled, array $skipped): void
    {
        if ($filled === 0 && $skipped === []) {
            Notification::make()
                ->title('Rien n\'a pu être extrait de ce document.')
                ->warning()
                ->send();

            return;
        }

        Notification::make()
            ->title($filled . ' champ' . ($filled > 1 ? 's' : '') . ' pré-rempli' . ($filled > 1 ? 's' : ''))
            ->body($skipped === []
                ? 'Relisez les valeurs avant d\'enregistrer.'
                : count($skipped) . ' champ' . (count($skipped) > 1 ? 's' : '') . ' non modifié'
                    . (count($skipped) > 1 ? 's' : '') . ' : ' . implode(', ', $skipped) . '.')
            ->success()
            ->send();
    }

    /** Type de document imposé par le formulaire hôte : on sait déjà ce qu'on saisit. */
    private static function documentTypeFor(string $aiType): string
    {
        return match ($aiType) {
            'furniture' => 'furniture_invoice',
            'work' => 'works_invoice',
            default => 'expense_invoice',
        };
    }
}
