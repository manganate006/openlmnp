<?php

namespace App\Filament\Resources\PropertyComponents\Pages;

use App\Filament\Resources\PropertyComponents\PropertyComponentResource;
use App\Models\PropertyComponent;
use App\Services\BadgeService;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;

class CreatePropertyComponent extends CreateRecord
{
    protected static string $resource = PropertyComponentResource::class;

    protected function afterCreate(): void
    {
        app(BadgeService::class)->evaluate(auth()->user(), 'component_created');

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
