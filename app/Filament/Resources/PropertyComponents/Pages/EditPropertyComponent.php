<?php

namespace App\Filament\Resources\PropertyComponents\Pages;

use App\Filament\Resources\PropertyComponents\PropertyComponentResource;
use App\Models\PropertyComponent;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
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

    protected function afterSave(): void
    {
        $propertyId = $this->record->property_id;
        $total = PropertyComponent::where('property_id', $propertyId)->sum('percentage');
        if ($total < 100) {
            Notification::make()
                ->warning()
                ->title('Total des pourcentages incomplet')
                ->body("Le total des composants est de {$total} % (attendu : 100 %).")
                ->persistent()
                ->send();
        }
    }
}
