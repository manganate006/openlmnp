<?php

namespace App\Filament\Resources\PropertyComponents\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class PropertyComponentForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('property_id')
                    ->relationship('property', 'name')
                    ->required(),
                TextInput::make('name')
                    ->required(),
                TextInput::make('percentage')
                    ->required()
                    ->numeric(),
                TextInput::make('duration_years')
                    ->required()
                    ->numeric(),
                TextInput::make('base_amount')
                    ->required()
                    ->numeric(),
                TextInput::make('annual_depreciation')
                    ->required()
                    ->numeric(),
                TextInput::make('sort_order')
                    ->required()
                    ->numeric()
                    ->default(0),
            ]);
    }
}
