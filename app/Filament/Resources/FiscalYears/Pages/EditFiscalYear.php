<?php

namespace App\Filament\Resources\FiscalYears\Pages;

use App\Filament\Pages\Projection;
use App\Filament\Pages\Simulator;
use App\Filament\Pages\Teledeclaration;
use App\Filament\Resources\FiscalYears\FiscalYearResource;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Icons\Heroicon;

class EditFiscalYear extends EditRecord
{
    protected static string $resource = FiscalYearResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('back')
                ->label('Retour')
                ->icon('heroicon-o-arrow-left')
                ->color('gray')
                ->url(static::$resource::getUrl()),
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
            DeleteAction::make(),
        ];
    }
}
