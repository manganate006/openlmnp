<?php

namespace App\Filament\Pages\Concerns;

use App\Enums\NavMode;
use Illuminate\Support\Facades\Auth;

trait NavigationAware
{
    protected static function getGuidedNavigationGroup(): string
    {
        return static::$navigationGroup ?? '';
    }

    protected static function isHiddenInSimpleMode(): bool
    {
        return false;
    }

    protected static function getSimpleNavigationGroup(): ?string
    {
        return static::$navigationGroup;
    }

    public static function shouldRegisterNavigation(): bool
    {
        if (static::$shouldRegisterNavigation === false) {
            return false;
        }

        $user = Auth::user();

        if ($user && static::isHiddenInSimpleMode() && $user->nav_mode === NavMode::Simple) {
            return false;
        }

        return true;
    }

    public static function getNavigationGroup(): ?string
    {
        $user = Auth::user();

        if ($user?->nav_mode === NavMode::Guided) {
            return static::getGuidedNavigationGroup();
        }

        if ($user?->nav_mode === NavMode::Simple) {
            return static::getSimpleNavigationGroup();
        }

        return static::$navigationGroup;
    }
}
