<?php

namespace App\Filament\Pages;

use App\Models\Property;
use App\Services\BadgeService;
use App\Services\Csv\CsvImportService;
use App\Services\Csv\CsvProfile;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\WithFileUploads;
use UnitEnum;

/**
 * Import CSV générique : recettes, charges, mobilier, travaux.
 *
 * Dépôt → aperçu de dix lignes → mappage des colonnes → doublons → import. Le mappage
 * est PROPOSÉ et non appliqué : un tableur de cabinet ne porte jamais deux fois les
 * mêmes intitulés, et deviner en silence transforme un import réussi en comptabilité
 * fausse que personne ne relit.
 *
 * ⚠️ `ImportAirbnb` reste le chemin recommandé pour un export Airbnb : lui seul
 * reconstitue le montant brut depuis le net quand l'export ne détaille pas la
 * commission. Cette page ne le remplace pas, elle couvre tout le reste.
 */
class ImportCsv extends Page implements HasForms
{
    use InteractsWithForms, WithFileUploads;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTableCells;
    protected static string | UnitEnum | null $navigationGroup = 'Comptabilité';
    protected static ?string $navigationLabel = 'Import CSV';
    protected static ?string $title = 'Import CSV (recettes, charges, mobilier, travaux)';
    protected static ?int $navigationSort = 2;
    protected static bool $shouldRegisterNavigation = false;

    protected string $view = 'filament.pages.import-csv';

    public ?array $data = [];

    /** Aperçu en cours : en-tête du fichier, mappage proposé, dix lignes converties. */
    public ?array $previewData = null;

    /** Mappage courant, champ => index de colonne (ou '' pour « ignorer »). */
    public array $mapping = [];

    public ?array $lastResult = null;
    public ?string $previewFilePath = null;
    public ?int $previewPropertyId = null;
    public ?string $previewTarget = null;

    public function mount(): void
    {
        $this->form->fill();
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Section::make('Fichier à importer')
                    ->description('Un tableur exporté en CSV. Le séparateur (virgule, point-virgule, tabulation) est détecté automatiquement, tout comme les montants au format français.')
                    ->schema([
                        Select::make('property_id')
                            ->label('Bien concerné')
                            ->options(Property::where('user_id', auth()->id())->pluck('name', 'id'))
                            ->required()
                            ->preload()
                            ->default(fn () => ($ids = Property::where('user_id', auth()->id())->pluck('id'))->count() === 1 ? $ids->first() : null),
                        Select::make('target')
                            ->label('Nature des lignes')
                            ->options(CsvProfile::targetLabels())
                            ->required()
                            ->default(CsvProfile::TARGET_EXPENSE)
                            ->helperText('Un fichier, une nature. Un inventaire de cabinet mélange rarement le mobilier et les charges.'),
                        FileUpload::make('csv_file')
                            ->label('Fichier CSV')
                            ->acceptedFileTypes(['text/csv', 'text/plain', 'application/vnd.ms-excel', '.csv'])
                            ->maxSize(10240),
                    ])
                    ->footerActions([
                        Action::make('preview')
                            ->label('Aperçu et mappage')
                            ->action('preview')
                            ->color('primary'),
                    ]),
            ]);
    }

    /** Champs attendus par la cible en cours, pour l'écran de mappage. */
    public function targetFields(): array
    {
        return $this->previewTarget ? CsvProfile::fields($this->previewTarget) : [];
    }

    public function preview(): void
    {
        $state = $this->form->getState();
        $propertyId = $state['property_id'] ?? null;
        $target = $state['target'] ?? null;
        $csvFile = $state['csv_file'] ?? null;

        if (! $propertyId || ! $target || ! $csvFile) {
            Notification::make()->danger()
                ->title('Sélectionnez un bien, une nature de lignes et un fichier')->send();

            return;
        }

        $property = Property::where('user_id', auth()->id())->findOrFail($propertyId);
        $file = $this->resolveUploadedFile($csvFile);

        if (! $file) {
            Notification::make()->danger()->title('Fichier introuvable')->send();

            return;
        }

        try {
            $result = app(CsvImportService::class)->preview($file, $property, $target);
        } catch (\RuntimeException $e) {
            Notification::make()->danger()->title('Fichier illisible')->body($e->getMessage())->send();

            return;
        }

        $this->previewData = $result;
        $this->previewTarget = $target;
        $this->previewPropertyId = (int) $propertyId;
        $this->previewFilePath = is_string($csvFile) ? $csvFile : null;
        $this->lastResult = null;

        // Le mappage vit dans une propriété à part : c'est lui que l'écran modifie, et
        // le relire depuis `previewData` le figerait au premier aperçu.
        $this->mapping = [];
        foreach (array_keys(CsvProfile::fields($target)) as $field) {
            $this->mapping[$field] = isset($result['mapping'][$field])
                ? (string) $result['mapping'][$field]
                : '';
        }
    }

    /** Rejoue l'aperçu avec le mappage corrigé à la main. */
    public function refreshPreview(): void
    {
        if (! $this->previewPropertyId || ! $this->previewFilePath || ! $this->previewTarget) {
            return;
        }

        $property = Property::where('user_id', auth()->id())->findOrFail($this->previewPropertyId);
        $file = $this->resolveUploadedFile($this->previewFilePath);

        if (! $file) {
            Notification::make()->danger()->title('Fichier introuvable — veuillez le redéposer')->send();
            $this->cancelPreview();

            return;
        }

        try {
            $this->previewData = app(CsvImportService::class)
                ->preview($file, $property, $this->previewTarget, $this->currentMapping());
        } catch (\RuntimeException $e) {
            Notification::make()->danger()->title('Fichier illisible')->body($e->getMessage())->send();
        }
    }

    public function confirmImport(): void
    {
        if (! $this->previewPropertyId || ! $this->previewFilePath || ! $this->previewTarget) {
            Notification::make()->danger()->title('Aucun aperçu en cours')->send();

            return;
        }

        $property = Property::where('user_id', auth()->id())->findOrFail($this->previewPropertyId);
        $file = $this->resolveUploadedFile($this->previewFilePath);

        if (! $file) {
            Notification::make()->danger()->title('Fichier introuvable — veuillez le redéposer')->send();
            $this->cancelPreview();

            return;
        }

        try {
            $result = app(CsvImportService::class)
                ->import($file, $property, $this->previewTarget, $this->currentMapping());
        } catch (\RuntimeException $e) {
            Notification::make()->danger()->title('Import impossible')->body($e->getMessage())->send();

            return;
        }

        // Événement navigateur relayé vers le dataLayer GTM (partials/gtm-head).
        // RGPD : tranche de lignes et nature, jamais les montants.
        $this->dispatch('analytics', [
            'event' => 'csv_import',
            'status' => $result['imported'] > 0 ? 'success' : 'error',
            'rows_bucket' => \App\Support\Analytics::rowsBucket((int) $result['imported']),
        ]);

        $this->lastResult = $result + ['target' => $this->previewTarget];
        $this->cancelPreview();
        $this->form->fill();

        if ($result['imported'] > 0) {
            app(BadgeService::class)->evaluate(auth()->user(), 'csv_imported');

            Notification::make()->success()
                ->title("{$result['imported']} ligne(s) importée(s)")
                ->body(trim(
                    ($result['duplicates'] > 0 ? "{$result['duplicates']} doublon(s) ignoré(s). " : '')
                    . ($result['skipped'] > 0 ? "{$result['skipped']} ligne(s) illisible(s)." : '')
                ))
                ->send();
        } else {
            Notification::make()->warning()
                ->title('Aucune ligne importée')
                ->body(trim(
                    ($result['duplicates'] > 0 ? "{$result['duplicates']} doublon(s). " : '')
                    . implode(' ', array_slice($result['errors'], 0, 3))
                ))
                ->send();
        }
    }

    public function cancelPreview(): void
    {
        $this->previewData = null;
        $this->previewFilePath = null;
        $this->previewPropertyId = null;
        $this->previewTarget = null;
        $this->mapping = [];
    }

    /**
     * Mappage courant, débarrassé des champs laissés à « ignorer ».
     *
     * @return array<string, int>
     */
    private function currentMapping(): array
    {
        $mapping = [];

        foreach ($this->mapping as $field => $index) {
            if ($index !== '' && $index !== null) {
                $mapping[$field] = (int) $index;
            }
        }

        return $mapping;
    }

    private function resolveUploadedFile(mixed $csvFile): ?UploadedFile
    {
        if (is_string($csvFile)) {
            $disk = Storage::disk();

            if (! $disk->exists($csvFile)) {
                $disk = Storage::disk('public');

                if (! $disk->exists($csvFile)) {
                    return null;
                }
            }

            return new UploadedFile($disk->path($csvFile), basename($csvFile));
        }

        return new UploadedFile($csvFile->getRealPath(), $csvFile->getClientOriginalName());
    }
}
