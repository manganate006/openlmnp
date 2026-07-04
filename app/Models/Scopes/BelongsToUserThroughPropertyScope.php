<?php

namespace App\Models\Scopes;

use App\Models\Property;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Support\Facades\Auth;

/**
 * Scope global qui restreint les requêtes aux enregistrements rattachés
 * à un bien appartenant à l'utilisateur authentifié.
 *
 * Utilisé par les modèles sans colonne user_id directe (Loan, Income,
 * Expense, Furniture, PropertyWork, PropertyComponent) dont l'isolation
 * multi-utilisateurs passe par la relation property.
 */
class BelongsToUserThroughPropertyScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        if (Auth::check()) {
            $builder->whereIn(
                $model->qualifyColumn('property_id'),
                Property::withoutGlobalScopes()
                    ->select('id')
                    ->where('user_id', Auth::id())
            );
        }
    }
}
