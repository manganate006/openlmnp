<?php

namespace App\Models\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Support\Facades\Auth;

/**
 * Scope global qui restreint les requêtes aux enregistrements
 * appartenant à l'utilisateur authentifié.
 */
class BelongsToUserScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        if (Auth::check()) {
            $builder->where($model->getTable() . '.user_id', Auth::id());
        }
    }
}
