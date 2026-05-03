<?php

namespace App\Filament\Resources\Loans\Pages;

use App\Filament\Pages\LoanDetail;
use App\Filament\Resources\Loans\LoanResource;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;

class ListLoans extends ListRecords
{
    protected static string $resource = LoanResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('loan-detail')
                ->label('Détail emprunt')
                ->icon(Heroicon::OutlinedTableCells)
                ->color('gray')
                ->url(LoanDetail::getUrl()),
            CreateAction::make(),
        ];
    }
}
