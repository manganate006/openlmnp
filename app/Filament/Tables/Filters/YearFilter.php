<?php

namespace App\Filament\Tables\Filters;

use Filament\Tables\Filters\SelectFilter;
use Illuminate\Database\Eloquent\Builder;

class YearFilter
{
    public static function make(string $dateColumn, string $modelClass): SelectFilter
    {
        return SelectFilter::make('year')
            ->label('Année')
            ->options(fn () => $modelClass::query()
                ->selectRaw("DISTINCT strftime('%Y', {$dateColumn}) as year")
                ->whereNotNull($dateColumn)
                ->orderByDesc('year')
                ->pluck('year', 'year')
                ->toArray()
            )
            ->query(fn (Builder $query, array $data) => $query->when(
                $data['value'],
                fn ($q, $year) => $q->whereYear($dateColumn, $year)
            ))
            ->default((string) now()->year);
    }
}
