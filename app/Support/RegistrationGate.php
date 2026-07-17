<?php

namespace App\Support;

use App\Models\User;

/**
 * Décide si la page d'inscription publique (/register) est active.
 * Voir config('app.allow_registration') : "auto" | true | false.
 */
class RegistrationGate
{
    public static function allows(): bool
    {
        $setting = config('app.allow_registration', 'auto');

        if ($setting === 'auto') {
            // Ouverte uniquement tant qu'aucun compte réel n'existe. Les comptes
            // démo (sandbox éphémères) ne comptent pas. En l'absence de base
            // migrée (premier démarrage), on laisse ouvert.
            return rescue(
                fn () => User::query()
                    ->where(fn ($q) => $q->where('is_demo', false)->orWhereNull('is_demo'))
                    ->doesntExist(),
                true,
                false,
            );
        }

        return filter_var($setting, FILTER_VALIDATE_BOOL);
    }
}
