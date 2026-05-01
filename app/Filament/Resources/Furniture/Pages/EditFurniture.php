<?php

namespace App\Filament\Resources\Furniture\Pages;

use App\Filament\Resources\Furniture\FurnitureResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditFurniture extends EditRecord
{
    protected static string $resource = FurnitureResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
