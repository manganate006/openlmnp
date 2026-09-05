<?php

namespace App\Filament\Resources\Expenses\Pages;

use App\Filament\Actions\GenerateOccurrencesAction;
use App\Filament\Resources\Expenses\ExpenseResource;
use App\Services\BadgeService;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;

class CreateExpense extends CreateRecord
{
    protected static string $resource = ExpenseResource::class;

    protected function afterCreate(): void
    {
        app(BadgeService::class)->evaluate(auth()->user(), 'expense_created');
    }

    /**
     * Une charge mensuelle ou trimestrielle repart avec une invitation à générer ses
     * échéances : sans elle, l'action ne se découvre que par hasard dans la liste.
     */
    protected function getCreatedNotification(): ?Notification
    {
        return GenerateOccurrencesAction::proposal($this->getRecord(), $this->getCreatedNotificationTitle() ?? 'Charge créée')
            ?? parent::getCreatedNotification();
    }
}
