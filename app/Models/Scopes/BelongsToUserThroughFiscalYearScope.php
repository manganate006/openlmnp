<?php

namespace App\Models\Scopes;

use App\Models\FiscalYear;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Support\Facades\Auth;

/**
 * Scope global qui restreint les requêtes aux enregistrements rattachés
 * à un exercice fiscal appartenant à l'utilisateur authentifié.
 *
 * Utilisé par AccountingEntry (fiscal_year_id → fiscal_years.user_id).
 * On scoppe via l'exercice plutôt que via property_id, car property_id
 * peut être nul pour les écritures consolidées.
 */
class BelongsToUserThroughFiscalYearScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        if (Auth::check()) {
            $builder->whereIn(
                $model->qualifyColumn('fiscal_year_id'),
                FiscalYear::withoutGlobalScopes()
                    ->select('id')
                    ->where('user_id', Auth::id())
            );
        }
    }
}
