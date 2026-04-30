<?php

namespace App\Filament\Resources\FiscalYears\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Schema;

class FiscalYearForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('user_id')
                    ->relationship('user', 'name')
                    ->required(),
                TextInput::make('year')
                    ->required()
                    ->numeric(),
                TextInput::make('status')
                    ->required()
                    ->default('draft'),
                TextInput::make('total_income')
                    ->required()
                    ->numeric()
                    ->default(0),
                TextInput::make('total_expenses')
                    ->required()
                    ->numeric()
                    ->default(0),
                TextInput::make('total_depreciation')
                    ->required()
                    ->numeric()
                    ->default(0),
                TextInput::make('capped_depreciation')
                    ->required()
                    ->numeric()
                    ->default(0),
                TextInput::make('deferred_depreciation')
                    ->required()
                    ->numeric()
                    ->default(0),
                TextInput::make('previous_deferred')
                    ->required()
                    ->numeric()
                    ->default(0),
                TextInput::make('fiscal_result')
                    ->required()
                    ->numeric()
                    ->default(0),
                Textarea::make('form_data')
                    ->columnSpanFull(),
                TextInput::make('pdf_path'),
                TextInput::make('fec_path'),
            ]);
    }
}
