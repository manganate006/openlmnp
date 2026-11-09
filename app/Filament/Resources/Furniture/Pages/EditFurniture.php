<?php

namespace App\Filament\Resources\Furniture\Pages;

use App\Filament\Resources\Furniture\FurnitureResource;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditFurniture extends EditRecord
{
    protected static string $resource = FurnitureResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('back')
                ->label('Retour')
                ->icon('heroicon-o-arrow-left')
                ->color('gray')
                ->url(fn (): string => $this->getRedirectUrl()),
            DeleteAction::make(),
        ];
    }

    /**
     * Retour à la liste du bien après « Enregistrer » (#16). L'index général mélange les
     * biens ; la page `property` est celle d'où l'on vient. Voir EditExpense pour le choix
     * d'une redirection par page plutôt que globale.
     */
    protected function getRedirectUrl(): ?string
    {
        return static::$resource::getUrl('property', ['propertyId' => $this->getRecord()->property_id]);
    }
}
