<?php

namespace App\Filament\Resources\Incomes\Pages;

use App\Filament\Resources\Incomes\IncomeResource;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditIncome extends EditRecord
{
    protected static string $resource = IncomeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('back')
                ->label('Retour')
                ->icon('heroicon-o-arrow-left')
                ->color('gray')
                ->url(static::$resource::getUrl()),
            DeleteAction::make(),
        ];
    }
}
