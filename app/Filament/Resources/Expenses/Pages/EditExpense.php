<?php

namespace App\Filament\Resources\Expenses\Pages;

use App\Filament\Actions\GenerateOccurrencesAction;
use App\Filament\Resources\Expenses\ExpenseResource;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditExpense extends EditRecord
{
    protected static string $resource = ExpenseResource::class;

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

    /**
     * Même invitation qu'à la création : on bascule souvent une charge en « Mensuel »
     * après coup, en se rendant compte qu'elle revient tous les mois.
     */
    protected function getSavedNotification(): ?Notification
    {
        return GenerateOccurrencesAction::proposal($this->getRecord(), $this->getSavedNotificationTitle() ?? 'Charge enregistrée')
            ?? parent::getSavedNotification();
    }
}
