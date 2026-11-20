<?php

namespace App\Filament\Resources\Leases\Pages;

use App\Filament\Actions\GenerateLeaseRentsAction;
use App\Filament\Resources\Leases\LeaseResource;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;

class CreateLease extends CreateRecord
{
    protected static string $resource = LeaseResource::class;

    /**
     * La garde du modèle (`Lease::assertValid()`) reste le dernier rempart : le formulaire
     * valide déjà tout ce qu'elle vérifie, mais un bail saisi entre-temps dans un autre
     * onglet passerait la règle et échouerait ici. Sans ce filet, une erreur 500.
     */
    protected function handleRecordCreation(array $data): Model
    {
        try {
            return parent::handleRecordCreation($data);
        } catch (\RuntimeException $exception) {
            Notification::make()
                ->title('Bail non enregistré')
                ->body($exception->getMessage())
                ->danger()
                ->send();

            throw (new Halt())->rollBackDatabaseTransaction();
        }
    }

    /**
     * Un bail neuf repart avec une invitation à générer ses loyers : sans elle, l'action
     * ne se découvre que dans la liste.
     */
    protected function getCreatedNotification(): ?Notification
    {
        return GenerateLeaseRentsAction::proposal($this->getRecord(), $this->getCreatedNotificationTitle() ?? 'Bail créé')
            ?? parent::getCreatedNotification();
    }
}
