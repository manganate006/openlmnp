<?php

namespace App\Filament\Resources\Incomes\Pages;

use App\Filament\Resources\Incomes\IncomeResource;
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
            CreateAction::make(),
        ];
    }
}
