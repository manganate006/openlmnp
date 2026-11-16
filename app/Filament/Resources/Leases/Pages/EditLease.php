<?php

namespace App\Filament\Resources\Leases\Pages;

use App\Filament\Actions\GenerateLeaseRentsAction;
use App\Filament\Resources\Leases\LeaseResource;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;

class EditLease extends EditRecord
{
    protected static string $resource = LeaseResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('back')
                ->label('Retour')
                ->icon('heroicon-o-arrow-left')
                ->color('gray')
                ->url(static::$resource::getUrl()),
            GenerateLeaseRentsAction::make(),
            // Supprimer un bail garde ses loyers, sans le lien : ce sont des recettes
            // déjà déclarées ou à déclarer (`Lease::booted()`).
            DeleteAction::make()
                ->modalDescription('Les loyers déjà générés restent dans vos recettes, simplement détachés du bail.'),
        ];
    }

    /** Même filet qu'à la création : voir `CreateLease::handleRecordCreation()`. */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        try {
            return parent::handleRecordUpdate($record, $data);
        } catch (\RuntimeException $exception) {
            Notification::make()
                ->title('Bail non enregistré')
                ->body($exception->getMessage())
                ->danger()
                ->send();

            throw (new Halt())->rollBackDatabaseTransaction();
        }
    }

    /** Retour à la liste après « Enregistrer » (#16), comme EditLoan. */
    protected function getRedirectUrl(): ?string
    {
        return static::$resource::getUrl();
    }
}
