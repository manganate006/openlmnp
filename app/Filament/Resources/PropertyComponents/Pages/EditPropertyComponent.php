<?php

namespace App\Filament\Resources\PropertyComponents\Pages;

use App\Filament\Resources\PropertyComponents\PropertyComponentResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditPropertyComponent extends EditRecord
{
    protected static string $resource = PropertyComponentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
