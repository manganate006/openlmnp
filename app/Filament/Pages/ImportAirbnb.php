<?php

namespace App\Filament\Pages;

use App\Models\Property;
use App\Services\AirbnbImportService;
use App\Services\BadgeService;
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

class ImportAirbnb extends Page implements HasForms
{
    use InteractsWithForms, WithFileUploads;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowDownTray;
    protected static string | UnitEnum | null $navigationGroup = 'Comptabilité';
    protected static ?string $navigationLabel = 'Import Airbnb';
    protected static ?string $title = 'Import des revenus Airbnb';
    protected static ?int $navigationSort = 1;
    protected static bool $shouldRegisterNavigation = false;

    protected string $view = 'filament.pages.import-airbnb';

    public ?array $data = [];
    public ?array $previewData = null;
    public ?array $lastResult = null;
    public ?string $previewFilePath = null;
    public ?int $previewPropertyId = null;

    public function mount(): void
    {
        $this->form->fill();
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Section::make('Import CSV Airbnb')
                    ->description('Exportez vos revenus depuis Airbnb (Historique des transactions → Exporter en CSV) puis importez le fichier ici. Les doublons sont détectés automatiquement via le code de confirmation.')
                    ->schema([
                        Select::make('property_id')
                            ->label('Bien concerné')
                            ->options(
                                Property::where('user_id', auth()->id())
                                    ->pluck('name', 'id')
                            )
                            ->required()
                            ->placeholder('Sélectionner un bien...')
                            ->preload()
                            ->default(fn () => ($ids = Property::where('user_id', auth()->id())->pluck('id'))->count() === 1 ? $ids->first() : null),
                        FileUpload::make('csv_file')
                            ->label('Fichier CSV')
                            ->acceptedFileTypes(['text/csv', 'application/vnd.ms-excel', '.csv'])
                            ->maxSize(10240),
                    ])
                    ->footerActions([
                        Action::make('preview')
                            ->label('Aperçu avant import')
                            ->action('preview')
                            ->color('primary'),
                    ]),
            ]);
    }

    private function resolveUploadedFile(mixed $csvFile): ?UploadedFile
    {
        if (is_string($csvFile)) {
            // Filament FileUpload stores to the default disk (local → storage/app/private/)
            $disk = Storage::disk();
            if (! $disk->exists($csvFile)) {
                // Fallback: try public disk
                $disk = Storage::disk('public');
                if (! $disk->exists($csvFile)) {
                    return null;
                }
            }
            return new UploadedFile($disk->path($csvFile), basename($csvFile));
        }

        return new UploadedFile(
            $csvFile->getRealPath(),
            $csvFile->getClientOriginalName()
        );
    }

    public function preview(): void
    {
        $data = $this->form->getState();

        $propertyId = $data['property_id'] ?? null;
        $csvFile = $data['csv_file'] ?? null;

        if (! $propertyId || ! $csvFile) {
            Notification::make()
                ->title('Veuillez sélectionner un bien et un fichier CSV')
                ->danger()
                ->send();
            return;
        }

        $property = Property::where('user_id', auth()->id())
            ->findOrFail($propertyId);

        $uploadedFile = $this->resolveUploadedFile($csvFile);
        if (! $uploadedFile) {
            Notification::make()
                ->title('Fichier introuvable')
                ->danger()
                ->send();
            return;
        }

        $service = app(AirbnbImportService::class);
        $result = $service->preview($uploadedFile, $property);

        $this->previewData = $result;
        $this->previewFilePath = is_string($csvFile) ? $csvFile : null;
        $this->previewPropertyId = (int) $propertyId;
        $this->lastResult = null;
    }

    public function confirmImport(): void
    {
        if (! $this->previewPropertyId || ! $this->previewFilePath) {
            Notification::make()
                ->title('Aucun aperçu en cours')
                ->danger()
                ->send();
            return;
        }

        $property = Property::where('user_id', auth()->id())
            ->findOrFail($this->previewPropertyId);

        $uploadedFile = $this->resolveUploadedFile($this->previewFilePath);
        if (! $uploadedFile) {
            Notification::make()
                ->title('Fichier introuvable — veuillez réimporter')
                ->danger()
                ->send();
            $this->cancelPreview();
            return;
        }

        $service = app(AirbnbImportService::class);
        $result = $service->import($uploadedFile, $property);

        // Événement navigateur relayé vers le dataLayer GTM (partials/gtm-head).
        // RGPD : tranche de lignes uniquement, jamais les montants.
        $this->dispatch('analytics', [
            'event' => 'airbnb_import',
            'status' => $result['imported'] > 0 ? 'success' : 'error',
            'rows_bucket' => \App\Support\Analytics::rowsBucket((int) $result['imported']),
        ]);

        $this->lastResult = $result;
        $this->previewData = null;
        $this->previewFilePath = null;
        $this->previewPropertyId = null;
        $this->form->fill();

        if ($result['imported'] > 0) {
            app(BadgeService::class)->evaluate(auth()->user(), 'csv_imported');

            Notification::make()
                ->title("{$result['imported']} recette(s) importée(s)")
                ->body($result['skipped'] > 0 ? "{$result['skipped']} ligne(s) ignorée(s)" : '')
                ->success()
                ->send();
        } else {
            Notification::make()
                ->title('Aucune recette importée')
                ->body($result['skipped'] . ' ligne(s) ignorée(s). ' . implode(', ', $result['errors']))
                ->warning()
                ->send();
        }
    }

    public function cancelPreview(): void
    {
        $this->previewData = null;
        $this->previewFilePath = null;
        $this->previewPropertyId = null;
    }
}
