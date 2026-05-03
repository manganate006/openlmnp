<?php

namespace App\Filament\Resources\FiscalYears\Pages;

use App\Filament\Pages\FiscalYearWizard;
use App\Filament\Pages\Projection;
use App\Filament\Pages\Simulator;
use App\Filament\Pages\Teledeclaration;
use App\Filament\Resources\FiscalYears\FiscalYearResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;

class ListFiscalYears extends ListRecords
{
    protected static string $resource = FiscalYearResource::class;

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
            Action::make('create_fiscal_year')
                ->label('Nouvel exercice')
                ->icon('heroicon-o-plus-circle')
                ->url(FiscalYearWizard::getUrl()),
        ];
    }
}
