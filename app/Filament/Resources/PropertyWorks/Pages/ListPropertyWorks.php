<?php

namespace App\Filament\Resources\PropertyWorks\Pages;

use App\Filament\Resources\PropertyWorks\PropertyWorkResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListPropertyWorks extends ListRecords
{
    protected static string $resource = PropertyWorkResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
