<?php

namespace App\Filament\Pages;

use App\Filament\Pages\Concerns\NavigationAware;
use App\Models\Property;
use App\Services\AirbnbImportService;
use App\Services\BadgeService;
use BackedEnum;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\WithFileUploads;
use UnitEnum;

class ImportAirbnb extends Page implements HasForms
{
    use InteractsWithForms, NavigationAware, WithFileUploads;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowDownTray;
    protected static string | UnitEnum | null $navigationGroup = 'Paramètres';
    protected static ?string $navigationLabel = 'Import Airbnb';
    protected static ?string $title = 'Import des revenus Airbnb';
    protected static ?int $navigationSort = 1;

    protected static function getGuidedNavigationGroup(): string
    {
        return 'Au quotidien';
    }

    protected static function getSimpleNavigationGroup(): ?string
    {
        return 'Outils';
    }
    protected string $view = 'filament.pages.import-airbnb';

    public ?int $property_id = null;
    public $csv_file = null;
    public ?array $lastResult = null;

    public function import(): void
    {
        if (! $this->property_id || ! $this->csv_file) {
            Notification::make()
                ->title('Veuillez sélectionner un bien et un fichier CSV')
                ->danger()
                ->send();
            return;
        }

        $property = Property::findOrFail($this->property_id);

        // Livewire TemporaryUploadedFile
        $tempFile = $this->csv_file;
        $uploadedFile = new UploadedFile($tempFile->getRealPath(), $tempFile->getClientOriginalName());

        $service = app(AirbnbImportService::class);
        $result = $service->import($uploadedFile, $property);

        $this->lastResult = $result;
        $this->csv_file = null;

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
}
