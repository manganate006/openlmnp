<?php

namespace App\Filament\Resources\PropertyComponents\Pages;

use App\Filament\Resources\PropertyComponents\PropertyComponentResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListPropertyComponents extends ListRecords
{
    protected static string $resource = PropertyComponentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
