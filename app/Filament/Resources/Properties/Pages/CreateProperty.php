<?php

namespace App\Filament\Resources\Properties\Pages;

use App\Filament\Resources\Properties\PropertyResource;
use App\Services\BadgeService;
use App\Services\DepreciationService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateProperty extends CreateRecord
{
    protected static string $resource = PropertyResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['user_id'] = auth()->id();

        return $data;
    }

    protected function afterCreate(): void
    {
        // Auto-générer les composants d'amortissement par défaut
        app(DepreciationService::class)->generateDefaultComponents($this->record);

        app(BadgeService::class)->evaluate(auth()->user(), 'property_created');

        // Via la session (flash) : la création est suivie d'une redirection,
        // un événement navigateur Livewire serait perdu.
        \App\Providers\AppServiceProvider::queueAnalyticsEvent(['event' => 'property_added']);
    }
}
