<?php

namespace App\Support;

use App\Models\User;

/**
 * Décide si la page d'inscription publique (/register) est active, et à quelles conditions.
 * Voir config('app.allow_registration') : "auto" | "invite" | true | false.
 */
class RegistrationGate
{
    public const MODE_INVITE = 'invite';

    /** L'inscription LIBRE est-elle ouverte ? */
    public static function allows(): bool
    {
        $setting = config('app.allow_registration', 'auto');

        // ⚠️ AVANT tout le reste. `filter_var('invite', FILTER_VALIDATE_BOOL)` rend
        // FALSE : sans ce court-circuit, le mode « invitation » se comporterait
        // EXACTEMENT comme « false », la route /register ne serait pas enregistrée,
        // et tous les liens d'invitation tomberaient en 404 — sans le moindre
        // message, ni côté invité ni côté administrateur.
        if ($setting === self::MODE_INVITE) {
            // Amorçage : comme en mode « auto », tant qu'aucun compte réel n'existe
            // la porte reste ouverte, sinon une instance neuve démarrée en
            // « invite » n'aurait personne pour émettre la première invitation.
            // Après le premier compte, invitation obligatoire.
            return self::noRealAccountYet();
        }

        if ($setting === 'auto') {
            return self::noRealAccountYet();
        }

        return filter_var($setting, FILTER_VALIDATE_BOOL);
    }

    /** Le formulaire /register exige-t-il de présenter une invitation valide ? */
    public static function requiresInvitation(): bool
    {
        return config('app.allow_registration', 'auto') === self::MODE_INVITE
            && ! self::allows();
    }

    /**
     * La route /register doit-elle exister ?
     *
     * En mode invitation, OUI même quand l'inscription libre est fermée : c'est là
     * qu'atterrit le porteur d'un lien.
     */
    public static function exposesRoute(): bool
    {
        return self::allows()
            || config('app.allow_registration', 'auto') === self::MODE_INVITE;
    }

    /**
     * Ouverte uniquement tant qu'aucun compte réel n'existe. Les comptes démo
     * (sandbox éphémères) ne comptent pas. En l'absence de base migrée (premier
     * démarrage), on laisse ouvert.
     */
    private static function noRealAccountYet(): bool
    {
        return rescue(
            fn () => User::query()
                ->where(fn ($q) => $q->where('is_demo', false)->orWhereNull('is_demo'))
                ->doesntExist(),
            true,
            false,
        );
    }
}
