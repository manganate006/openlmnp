<?php

namespace App\Filament\Resources\Furniture\Pages;

use App\Filament\Resources\Furniture\FurnitureResource;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditFurniture extends EditRecord
{
    protected static string $resource = FurnitureResource::class;

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
