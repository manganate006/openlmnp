<?php

namespace App\Filament\Resources\FiscalYears\Tables;

use App\Models\FiscalYear;
use App\Services\FecService;
use App\Services\FiscalYearService;
use App\Services\TaxReturnService;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Storage;

class FiscalYearsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('year')
                    ->label('Année')
                    ->sortable(),
                TextColumn::make('status')
                    ->label('Statut')
                    ->formatStateUsing(fn ($state) => FiscalYear::statusLabels()[$state] ?? $state)
                    ->badge()
                    ->color(fn ($state) => $state === 'closed' ? 'success' : 'warning'),
                TextColumn::make('total_income')
                    ->label('Recettes')
                    ->formatStateUsing(fn ($state) => number_format($state / 100, 0, ',', ' ') . ' €')
                    ->sortable(),
                TextColumn::make('total_expenses')
                    ->label('Charges')
                    ->formatStateUsing(fn ($state) => number_format($state / 100, 0, ',', ' ') . ' €')
                    ->sortable(),
                TextColumn::make('capped_depreciation')
                    ->label('Amort. déduits')
                    ->formatStateUsing(fn ($state) => number_format($state / 100, 0, ',', ' ') . ' €')
                    ->sortable(),
                TextColumn::make('deferred_depreciation')
                    ->label('Amort. différés')
                    ->formatStateUsing(fn ($state) => $state > 0 ? number_format($state / 100, 0, ',', ' ') . ' €' : '—')
                    ->sortable(),
                TextColumn::make('fiscal_result')
                    ->label('Résultat fiscal')
                    ->formatStateUsing(fn ($state) => number_format($state / 100, 0, ',', ' ') . ' €')
                    ->color(fn ($state) => $state > 0 ? 'warning' : 'success')
                    ->weight('bold')
                    ->sortable(),
            ])
            ->defaultSort('year', 'desc')
            ->recordActions([
                EditAction::make(),
                Action::make('calculate')
                    ->label('Calculer')
                    ->icon('heroicon-o-calculator')
                    ->color('info')
                    ->action(function (FiscalYear $record) {
                        app(FiscalYearService::class)->calculate($record);
                        Notification::make()->title('Exercice recalculé')->success()->send();
                    }),
                Action::make('pdf')
                    ->label('PDF')
                    ->icon('heroicon-o-document-arrow-down')
                    ->color('success')
                    ->action(function (FiscalYear $record) {
                        $path = app(TaxReturnService::class)->generatePdf($record);
                        return response()->streamDownload(
                            fn () => print(Storage::get($path)),
                            "liasse_fiscale_{$record->year}.pdf"
                        );
                    }),
                Action::make('fec')
                    ->label('FEC')
                    ->icon('heroicon-o-document-text')
                    ->color('gray')
                    ->action(function (FiscalYear $record) {
                        app(FiscalYearService::class)->calculate($record);
                        $path = app(FecService::class)->generate($record);
                        return response()->streamDownload(
                            fn () => print(Storage::get($path)),
                            "FEC_{$record->year}.txt"
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
