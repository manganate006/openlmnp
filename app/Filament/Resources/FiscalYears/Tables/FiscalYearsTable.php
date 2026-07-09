<?php

namespace App\Filament\Resources\FiscalYears\Tables;

use App\Models\FiscalYear;
use App\Services\BadgeService;
use App\Services\FecService;
use App\Services\FiscalYearService;
use App\Services\TaxReturnService;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Storage;

class FiscalYearsTable
{
    /**
     * Couleur du badge de report selon l'état de la chaîne.
     */
    private static function getChainStatusColor(FiscalYear $record): string
    {
        if ((int) $record->deferred_depreciation === 0) {
            return 'gray';
        }

        // Vérifier si N+1 existe et est synchronisé
        $next = FiscalYear::withoutGlobalScopes()
            ->where('user_id', $record->user_id)
            ->where('year', $record->year + 1)
            ->first();

        if (! $next) {
            return 'warning'; // N+1 n'existe pas encore
        }

        if ((int) $next->previous_deferred !== (int) $record->deferred_depreciation) {
            return 'danger'; // Désynchronisé
        }

        return 'success'; // Chaîne cohérente
    }

    /**
     * Tooltip expliquant l'état de la chaîne de report.
     */
    private static function getChainTooltip(FiscalYear $record): ?string
    {
        if ((int) $record->deferred_depreciation === 0) {
            return null;
        }

        $next = FiscalYear::withoutGlobalScopes()
            ->where('user_id', $record->user_id)
            ->where('year', $record->year + 1)
            ->first();

        if (! $next) {
            return 'L\'exercice ' . ($record->year + 1) . ' n\'existe pas encore — le report sera appliqué à sa création.';
        }

        if ((int) $next->previous_deferred !== (int) $record->deferred_depreciation) {
            return 'Désynchronisé : ' . ($record->year + 1) . ' attend '
                . number_format($next->previous_deferred / 100, 0, ',', ' ') . ' € mais devrait recevoir '
                . number_format($record->deferred_depreciation / 100, 0, ',', ' ') . ' €. Recalculez la chaîne.';
        }

        return 'Report correctement propagé vers ' . ($record->year + 1) . '.';
    }

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
                TextColumn::make('previous_deferred')
                    ->label('Report N−1')
                    ->formatStateUsing(fn ($state) => $state > 0 ? '← ' . number_format($state / 100, 0, ',', ' ') . ' €' : '—')
                    ->color('gray')
                    ->sortable(),
                TextColumn::make('deferred_depreciation')
                    ->label('Différé → N+1')
                    ->formatStateUsing(fn ($state) => $state > 0 ? number_format($state / 100, 0, ',', ' ') . ' € →' : '—')
                    ->color(fn (FiscalYear $record) => self::getChainStatusColor($record))
                    ->tooltip(fn (FiscalYear $record) => self::getChainTooltip($record))
                    ->sortable(),
                TextColumn::make('fiscal_result')
                    ->label('Résultat fiscal')
                    ->formatStateUsing(fn ($state) => number_format($state / 100, 0, ',', ' ') . ' €')
                    ->color(fn ($state) => $state > 0 ? 'warning' : 'success')
                    ->weight('bold')
                    ->sortable(),
            ])
            ->reorderableColumns()
            ->defaultSort('year', 'desc')
            ->recordActions([
                Action::make('calculate')
                    ->label('Calculer')
                    ->icon('heroicon-o-calculator')
                    ->color('info')
                    ->visible(fn (FiscalYear $record) => $record->status !== FiscalYear::STATUS_CLOSED)
                    ->action(function (FiscalYear $record) {
                        app(FiscalYearService::class)->calculate($record);
                        Notification::make()
                            ->title('Exercice ' . $record->year . ' recalculé')
                            ->body('Résultat fiscal : ' . number_format($record->fresh()->fiscal_result / 100, 0, ',', ' ') . ' €')
                            ->success()
                            ->send();
                    }),
                Action::make('reopen')
                    ->label('Rouvrir')
                    ->icon('heroicon-o-lock-open')
                    ->color('warning')
                    ->visible(fn (FiscalYear $record) => $record->status === FiscalYear::STATUS_CLOSED)
                    ->requiresConfirmation()
                    ->modalHeading('Rouvrir l\'exercice')
                    ->modalDescription('L\'exercice repassera en brouillon et pourra être recalculé. Les exercices suivants seront recalculés automatiquement si nécessaire.')
                    ->action(function (FiscalYear $record) {
                        $record->update(['status' => FiscalYear::STATUS_DRAFT]);
                        Notification::make()
                            ->title('Exercice ' . $record->year . ' rouvert')
                            ->body('L\'exercice est maintenant en brouillon.')
                            ->success()
                            ->send();
                    }),
                Action::make('pdf')
                    ->label('PDF')
                    ->icon('heroicon-o-document-arrow-down')
                    ->color('success')
                    ->action(function (FiscalYear $record, $livewire) {
                        $path = app(TaxReturnService::class)->generatePdf($record);

                        app(BadgeService::class)->evaluate(auth()->user(), 'tax_return_generated', [
                            'fiscal_year' => $record->year,
                        ]);

                        // Événement navigateur relayé vers le dataLayer GTM (partials/gtm-head)
                        $livewire->dispatch('analytics', event: 'cerfa_generated', fiscal_year: $record->year);

                        return response()->streamDownload(
                            fn () => print(Storage::get($path)),
                            "liasse_fiscale_{$record->year}.pdf"
                        );
                    }),
                Action::make('fec')
                    ->label('FEC')
                    ->icon('heroicon-o-document-text')
                    ->color('gray')
                    ->action(function (FiscalYear $record, $livewire) {
                        app(FiscalYearService::class)->calculate($record);
                        $path = app(FecService::class)->generate($record);

                        app(BadgeService::class)->evaluate(auth()->user(), 'fec_generated', [
                            'fiscal_year' => $record->year,
                        ]);

                        // Événement navigateur relayé vers le dataLayer GTM (partials/gtm-head)
                        $livewire->dispatch('analytics', event: 'fec_exported', fiscal_year: $record->year);

                        return response()->streamDownload(
                            fn () => print(Storage::get($path)),
                            "FEC_{$record->year}.txt"
                        );
                    }),
            ])
            ->toolbarActions([
                Action::make('recalculate_chain')
                    ->label('Recalculer la chaîne')
                    ->icon('heroicon-o-arrow-path')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->modalHeading('Recalculer tous les exercices')
                    ->modalDescription('Tous les exercices seront recalculés dans l\'ordre chronologique pour garantir la cohérence des reports d\'amortissements différés.')
                    ->action(function () {
                        $count = app(FiscalYearService::class)->recalculateChain(auth()->user());
                        Notification::make()
                            ->title('Chaîne recalculée')
                            ->body($count . ' exercice(s) recalculé(s) avec succès.')
                            ->success()
                            ->send();
                    }),
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
