<?php

namespace App\Filament\Resources\Loans\Tables;

use App\Filament\Pages\LoanDetail;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class LoansTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('property.name')
                    ->label('Bien')
                    ->searchable(),
                TextColumn::make('bank_name')
                    ->label('Banque')
                    ->searchable(),
                TextColumn::make('amount')
                    ->label('Montant emprunté')
                    ->formatStateUsing(fn ($state) => number_format($state / 100, 0, ',', ' ') . ' €')
                    ->sortable(),
                TextColumn::make('annual_rate')
                    ->label('Taux')
                    ->suffix(' %')
                    ->sortable(),
                TextColumn::make('duration_months')
                    ->label('Durée')
                    ->formatStateUsing(fn ($state) => intdiv($state, 12) . ' ans')
                    ->sortable(),
                TextColumn::make('start_date')
                    ->label('Début')
                    ->date('d/m/Y')
                    ->sortable(),
                TextColumn::make('monthly_payment')
                    ->label('Mensualité')
                    ->formatStateUsing(fn ($state) => $state > 0 ? number_format($state / 100, 2, ',', ' ') . ' €' : '—')
                    ->sortable(),
            ])
            ->reorderableColumns()
            ->filters([])
            ->recordActions([
                Action::make('detail')
                    ->label('Détail')
                    ->icon(Heroicon::OutlinedTableCells)
                    ->color('gray')
                    ->url(fn ($record) => LoanDetail::getUrl(['loanId' => $record->id])),
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
