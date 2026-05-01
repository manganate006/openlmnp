<?php

namespace App\Filament\Resources\Furniture\Schemas;

use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class FurnitureForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('property_id')
                    ->relationship('property', 'name')
                    ->required(),
                TextInput::make('description')
                    ->required(),
                TextInput::make('amount')
                    ->required()
                    ->numeric(),
                DatePicker::make('purchase_date')
                    ->required(),
                TextInput::make('duration_years')
                    ->required()
                    ->numeric()
                    ->default(5),
                Toggle::make('is_dedicated')
                    ->required(),
                Toggle::make('is_second_hand')
                    ->required(),
                TextInput::make('annual_depreciation')
                    ->required()
                    ->numeric(),
            ]);
    }
}
