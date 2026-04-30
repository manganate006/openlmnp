<?php

namespace App\Filament\Resources\Expenses\Schemas;

use App\Models\Expense;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class ExpenseForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Charge')
                    ->icon('heroicon-o-receipt-percent')
                    ->schema([
                        Select::make('property_id')
                            ->label('Bien')
                            ->relationship('property', 'name')
                            ->required()
                            ->preload(),
                        Grid::make(2)->schema([
                            DatePicker::make('expense_date')
                                ->label('Date')
                                ->required()
                                ->displayFormat('d/m/Y')
                                ->default(now()),
                            Select::make('category')
                                ->label('Catégorie')
                                ->options(Expense::categoryLabels())
                                ->required()
                                ->searchable(),
                        ]),
                        TextInput::make('description')
                            ->label('Description')
                            ->required()
                            ->placeholder('Ex : Taxe foncière 2026'),
                        Grid::make(2)->schema([
                            TextInput::make('amount')
                                ->label('Montant')
                                ->suffix('€')
                                ->required()
                                ->numeric()
                                ->step(0.01)
                                ->formatStateUsing(fn ($state) => $state ? number_format($state / 100, 2, '.', '') : null)
                                ->dehydrateStateUsing(fn ($state) => (int) round(((float) $state) * 100)),
                            Select::make('recurring_type')
                                ->label('Récurrence')
                                ->options(Expense::recurringLabels())
                                ->required()
                                ->default('once'),
                        ]),
                        Toggle::make('is_dedicated')
                            ->label('Charge 100% dédiée au bien loué')
                            ->helperText('Si non coché, la quote-part surface sera appliquée automatiquement')
                            ->default(false),
                    ]),

                Section::make('Justificatif')
                    ->icon('heroicon-o-paper-clip')
                    ->collapsed()
                    ->schema([
                        FileUpload::make('receipt_path')
                            ->label('Pièce justificative')
                            ->acceptedFileTypes(['application/pdf', 'image/*'])
                            ->directory('receipts')
                            ->maxSize(5120),
                        Textarea::make('notes')
                            ->label('Notes')
                            ->rows(2)
                            ->columnSpanFull(),
                    ]),
            ]);
    }
}
