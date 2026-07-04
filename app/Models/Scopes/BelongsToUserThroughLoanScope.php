<?php

namespace App\Models\Scopes;

use App\Models\Loan;
use App\Models\Property;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Support\Facades\Auth;

/**
 * Scope global qui restreint les requêtes aux enregistrements rattachés
 * à un emprunt d'un bien appartenant à l'utilisateur authentifié.
 *
 * Utilisé par LoanPayment, dont l'isolation multi-utilisateurs passe par
 * la chaîne loan_id → loans.property_id → properties.user_id.
 */
class BelongsToUserThroughLoanScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        if (Auth::check()) {
            $builder->whereIn(
                $model->qualifyColumn('loan_id'),
                Loan::withoutGlobalScopes()
                    ->select('id')
                    ->whereIn(
                        'property_id',
                        Property::withoutGlobalScopes()
                            ->select('id')
                            ->where('user_id', Auth::id())
                    )
            );
        }
    }
}
