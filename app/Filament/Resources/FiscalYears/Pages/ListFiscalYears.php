<?php

namespace App\Filament\Resources\FiscalYears\Pages;

use App\Filament\Pages\FiscalYearWizard;
use App\Filament\Resources\FiscalYears\FiscalYearResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;

class ListFiscalYears extends ListRecords
{
    protected static string $resource = FiscalYearResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('create_fiscal_year')
                ->label('Nouvel exercice')
                ->icon('heroicon-o-plus-circle')
                ->url(FiscalYearWizard::getUrl()),
        ];
    }
}
