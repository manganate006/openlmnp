<?php

namespace App\Filament\Resources\Incomes\Tables;

use App\Models\Income;
use App\Services\CsvExportService;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class IncomesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('property.name')
                    ->label('Bien')
                    ->searchable(),
                TextColumn::make('income_date')
                    ->label('Date')
                    ->date('d/m/Y')
                    ->sortable(),
                TextColumn::make('amount')
                    ->label('Montant')
                    ->formatStateUsing(fn ($state) => number_format($state / 100, 2, ',', ' ') . ' €')
                    ->sortable(),
                TextColumn::make('platform_fee')
                    ->label('Commission')
                    ->formatStateUsing(fn ($state) => number_format($state / 100, 2, ',', ' ') . ' €')
                    ->sortable(),
                TextColumn::make('source')
                    ->label('Source')
                    ->formatStateUsing(fn ($state) => Income::sourceLabels()[$state] ?? $state)
                    ->searchable(),
                TextColumn::make('guest_name')
                    ->label('Client')
                    ->searchable(),
                TextColumn::make('checkin_date')
                    ->label('Arrivée')
                    ->date('d/m/Y')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('checkout_date')
                    ->label('Départ')
                    ->date('d/m/Y')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('income_date', 'desc')
            ->filters([])
            ->recordActions([
                EditAction::make(),
            ])
            ->headerActions([
                Action::make('export_csv')
                    ->label('Exporter CSV')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->color('gray')
                    ->action(function () {
                        $incomes = \App\Models\Income::with('property')->orderBy('income_date', 'desc')->get();
                        return CsvExportService::export(
                            'recettes_' . date('Y') . '.csv',
                            ['Date', 'Bien', 'Montant (€)', 'Commission (€)', 'Taxe séjour (€)', 'Source', 'Client', 'Réf.'],
                            $incomes,
                            fn ($r) => [
                                $r->income_date->format('d/m/Y'),
                                $r->property->name ?? '',
                                number_format($r->amount / 100, 2, ',', ''),
                                number_format($r->platform_fee / 100, 2, ',', ''),
                                number_format($r->tourist_tax / 100, 2, ',', ''),
                                Income::sourceLabels()[$r->source] ?? $r->source,
                                $r->guest_name ?? '',
                                $r->reservation_ref ?? '',
                            ]
                        );
                    }),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
