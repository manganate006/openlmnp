<?php

namespace App\Filament\Resources\PropertyComponents\Schemas;

use App\Models\PropertyComponent;
use Closure;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Get;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;

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
                    ->numeric()
                    ->rules([
                        fn (Get $get, ?Model $record): Closure => function (string $attribute, $value, Closure $fail) use ($get, $record) {
                            $propertyId = $record?->property_id ?? $get('property_id');
                            if (! $propertyId) {
                                return;
                            }
                            $existingSum = PropertyComponent::where('property_id', $propertyId)
                                ->when($record, fn ($q) => $q->where('id', '!=', $record->id))
                                ->sum('percentage');
                            $total = $existingSum + (int) $value;
                            if ($total > 100) {
                                $fail("Le total des pourcentages dépasserait 100 % ({$existingSum} % existants + {$value} % = {$total} %).");
                            }
                        },
                    ]),
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
