<?php

namespace App\Filament\Pages\Concerns;

use Illuminate\Support\Facades\Auth;

trait RequiresAdmin
{
    public static function canAccess(): bool
    {
        return Auth::check() && Auth::user()->isAdmin();
    }
}
