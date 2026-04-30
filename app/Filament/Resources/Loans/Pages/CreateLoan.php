<?php

namespace App\Filament\Resources\Loans\Pages;

use App\Filament\Resources\Loans\LoanResource;
use App\Services\LoanService;
use Filament\Resources\Pages\CreateRecord;

class CreateLoan extends CreateRecord
{
    protected static string $resource = LoanResource::class;

    protected function afterCreate(): void
    {
        // Auto-générer le tableau d'amortissement
        app(LoanService::class)->generateSchedule($this->record);
    }
}
