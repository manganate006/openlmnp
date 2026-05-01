<?php

namespace App\Filament\Resources\Furniture\Pages;

use App\Filament\Resources\Furniture\FurnitureResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListFurniture extends ListRecords
{
    protected static string $resource = FurnitureResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
