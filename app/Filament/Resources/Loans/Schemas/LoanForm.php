<?php

namespace App\Filament\Resources\Loans\Schemas;

use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class LoanForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('property_id')
                    ->relationship('property', 'name')
                    ->required(),
                TextInput::make('bank_name'),
                TextInput::make('amount')
                    ->required()
                    ->numeric(),
                TextInput::make('annual_rate')
                    ->required()
                    ->numeric(),
                TextInput::make('duration_months')
                    ->required()
                    ->numeric(),
                DatePicker::make('start_date')
                    ->required(),
                TextInput::make('monthly_payment')
                    ->required()
                    ->numeric(),
                TextInput::make('insurance_monthly')
                    ->required()
                    ->numeric()
                    ->default(0),
            ]);
    }
}
