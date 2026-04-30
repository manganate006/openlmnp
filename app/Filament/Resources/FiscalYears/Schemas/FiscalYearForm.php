<?php

namespace App\Filament\Resources\FiscalYears\Schemas;

use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class FiscalYearForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Nouvel exercice fiscal')
                    ->icon('heroicon-o-document-text')
                    ->description('Sélectionnez l\'année. Tous les montants seront calculés automatiquement à partir de vos recettes, charges et amortissements.')
                    ->schema([
                        Select::make('year')
                            ->label('Année')
                            ->options(function () {
                                $years = [];
                                for ($y = (int) date('Y') + 1; $y >= (int) date('Y') - 5; $y--) {
                                    $years[$y] = $y;
                                }
                                return $years;
                            })
                            ->default((int) date('Y'))
                            ->required()
                            ->hint('L\'exercice va du 1er janvier au 31 décembre de cette année')
                            ->hintIcon('heroicon-o-question-mark-circle'),
                    ]),
            ]);
    }
}
