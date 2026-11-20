<?php

namespace App\Filament\Resources\Leases\Tables;

use App\Filament\Actions\GenerateLeaseRentsAction;
use App\Helpers\TvaHelper;
use App\Models\Lease;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class LeasesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('property.name')
                    ->label('Bien')
                    ->searchable(),
                TextColumn::make('tenant_name')
                    ->label('Locataire')
                    ->searchable(),
                TextColumn::make('start_date')
                    ->label('Début')
                    ->date('d/m/Y')
                    ->sortable(),
                TextColumn::make('end_date')
                    ->label('Fin')
                    ->date('d/m/Y')
                    ->placeholder('En cours')
                    ->sortable(),
                TextColumn::make('rent_amount')
                    ->label('Par mois')
                    ->formatStateUsing(fn ($state, Lease $record) => TvaHelper::formatEuros((int) $record->monthly_total))
                    ->description(fn (Lease $record) => (int) $record->charges_amount > 0
                        ? 'dont ' . TvaHelper::formatEuros((int) $record->charges_amount) . ' (' . mb_strtolower(Lease::chargesModeLabels()[$record->charges_mode] ?? 'charges') . ')'
                        : null)
                    ->sortable(),
                TextColumn::make('due_day')
                    ->label('Échéance')
                    ->formatStateUsing(fn ($state) => 'le ' . $state),
            ])
            ->reorderableColumns()
            ->defaultSort('start_date', 'desc')
            ->filters([])
            ->recordActions([
                ActionGroup::make([
                    GenerateLeaseRentsAction::make(),
                    EditAction::make(),
                ]),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
