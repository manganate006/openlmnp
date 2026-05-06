<?php

namespace App\Filament\Resources\Properties\Pages;

use App\Filament\Resources\Properties\PropertyResource;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Contracts\View\View;

class EditProperty extends EditRecord
{
    protected static string $resource = PropertyResource::class;

    public function getHeader(): ?View
    {
        return view('filament.partials.list-with-tabs', [
            'propertyId' => $this->record->id,
            'propertyName' => $this->record->name,
            'active' => 'general',
            'heading' => 'Modifier Bien',
            'actions' => $this->getCachedHeaderActions(),
        ]);
    }

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
