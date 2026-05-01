<?php

namespace App\Filament\Resources\PropertyWorks\Pages;

use App\Filament\Resources\PropertyWorks\PropertyWorkResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditPropertyWork extends EditRecord
{
    protected static string $resource = PropertyWorkResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
