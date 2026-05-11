<?php

namespace App\Filament\Resources\FiscalYears\Pages;

use App\Filament\Pages\FiscalYearWizard;
use App\Filament\Pages\Projection;
use App\Filament\Pages\Simulator;
use App\Filament\Pages\Teledeclaration;
use App\Filament\Resources\FiscalYears\FiscalYearResource;
use App\Models\Document;
use App\Services\DocumentExportService;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\DB;

class ListFiscalYears extends ListRecords
{
    protected static string $resource = FiscalYearResource::class;

    private function getDocumentYears(): array
    {
        $years = DB::table('documents')
            ->selectRaw('DISTINCT strftime("%Y", document_date) as year')
            ->whereNotNull('document_date')
            ->orderByDesc('year')
            ->pluck('year')
            ->filter()
            ->mapWithKeys(fn ($y) => [$y => $y])
            ->toArray();

        return $years ?: [(string) date('Y') => (string) date('Y')];
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('simulator')
                ->label('Simulateur')
                ->icon(Heroicon::OutlinedCalculator)
                ->color('gray')
                ->url(Simulator::getUrl()),
            Action::make('projection')
                ->label('Projection')
                ->icon(Heroicon::OutlinedChartBar)
                ->color('gray')
                ->url(Projection::getUrl()),
            Action::make('teledeclaration')
                ->label('Télédéclaration')
                ->icon(Heroicon::OutlinedPaperAirplane)
                ->color('gray')
                ->url(Teledeclaration::getUrl()),
            Action::make('export_documents')
                ->label('Justificatifs')
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->color('gray')
                ->modalSubmitActionLabel('Télécharger')
                ->form([
                    Select::make('year')
                        ->label('Année')
                        ->options(fn () => $this->getDocumentYears())
                        ->placeholder('Toutes les années'),
                    Select::make('type')
                        ->label('Type')
                        ->options([
                            'expense'   => 'Charges',
                            'furniture' => 'Mobilier',
                            'work'      => 'Travaux',
                        ])
                        ->placeholder('Tous les types'),
                ])
                ->action(function (array $data) {
                    $service = app(DocumentExportService::class);
                    $result = $service->exportZip(
                        auth()->user(),
                        $data['year'] ? (int) $data['year'] : null,
                        $data['type'] ?? null,
                    );

                    if ($result['path'] === null) {
                        Notification::make()
                            ->title('Aucun justificatif à exporter')
                            ->warning()
                            ->send();
                        return;
                    }

                    return response()->download(
                        \Illuminate\Support\Facades\Storage::path($result['path']),
                        'justificatifs' . ($data['year'] ? "-{$data['year']}" : '') . '.zip',
                    )->deleteFileAfterSend();
                }),
            Action::make('create_fiscal_year')
                ->label('Nouvel exercice')
                ->icon('heroicon-o-plus-circle')
                ->url(FiscalYearWizard::getUrl()),
        ];
    }
}
