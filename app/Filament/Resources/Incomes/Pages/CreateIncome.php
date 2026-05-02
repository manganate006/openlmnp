<?php

namespace App\Filament\Resources\Incomes\Pages;

use App\Filament\Resources\Incomes\IncomeResource;
use App\Services\BadgeService;
use Filament\Resources\Pages\CreateRecord;

class CreateIncome extends CreateRecord
{
    protected static string $resource = IncomeResource::class;

    protected function afterCreate(): void
    {
        app(BadgeService::class)->evaluate(auth()->user(), 'income_created');
    }
}
