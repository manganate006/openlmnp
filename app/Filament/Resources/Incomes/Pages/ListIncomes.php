<?php

namespace App\Filament\Resources\Incomes\Pages;

use App\Filament\Resources\Incomes\IncomeResource;
use App\Models\Income;
use App\Services\CsvExportService;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListIncomes extends ListRecords
{
    protected static string $resource = IncomeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('import_airbnb')
                ->label('Import Airbnb')
                ->icon('heroicon-o-arrow-up-tray')
                ->color('gray')
                ->url('/import-airbnb'),
            Action::make('export_csv')
                ->label('Exporter CSV')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->action(function () {
                    $incomes = Income::with('property')->orderBy('income_date', 'desc')->get();
                    return CsvExportService::export(
                        'recettes_' . date('Y') . '.csv',
                        ['Date', 'Bien', 'Montant (€)', 'Commission (€)', 'Taxe séjour (€)', 'Source', 'Client', 'Réf.'],
                        $incomes,
                        fn ($r) => [
                            $r->income_date->format('d/m/Y'),
                            $r->property->name ?? '',
                            number_format($r->amount / 100, 2, ',', ''),
                            number_format($r->platform_fee / 100, 2, ',', ''),
                            number_format($r->tourist_tax / 100, 2, ',', ''),
                            Income::sourceLabels()[$r->source] ?? $r->source,
                            $r->guest_name ?? '',
                            $r->reservation_ref ?? '',
                        ]
                    );
                }),
            CreateAction::make(),
        ];
    }
}
