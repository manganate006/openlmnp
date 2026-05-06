<?php

namespace App\Filament\Resources\PropertyWorks\Pages;

use App\Filament\Resources\PropertyWorks\PropertyWorkResource;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditPropertyWork extends EditRecord
{
    protected static string $resource = PropertyWorkResource::class;

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
