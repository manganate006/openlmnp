<?php

namespace App\Support;

use App\Models\LinkedDossier;
use App\Models\User;

/**
 * Résolution du quota de dossiers liés qu'un principal peut créer.
 *
 * Trois niveaux, du plus spécifique au plus général :
 *   1. `users.linked_dossier_quota` — s'il est renseigné, il prime TOUJOURS, cloud
 *      ou auto-hébergement (c'est la colonne que posent `openlmnp:dossier-quota` et,
 *      depuis le lot 4, `ProvisioningController::entitlements()`, appelée par la
 *      vitrine à chaque paiement) ;
 *   2. sur une instance CLOUD (détectée par `PROVISION_TOKEN`, comme
 *      `App\Http\Middleware\ProvisioningGuard`) sans valeur propre : quota 0 — rien
 *      n'est offert tant que la vitrine ou la commande ne l'ont pas posé ;
 *   3. en AUTO-HÉBERGEMENT sans valeur propre : `config('dossiers.quota')`, `null`
 *      par défaut = illimité (`LINKED_DOSSIERS_QUOTA` pour le restreindre).
 *
 * `null` signifie illimité partout dans cette classe.
 *
 * ⚠️ `entitlements()` ne baisse jamais ce quota sous `self::used()` : poser une
 * valeur plus petite que le nombre de dossiers déjà créés ne retire rien, elle
 * relève seulement le plancher. Cette classe ne l'impose pas elle-même (elle ne
 * fait qu'exposer `for()`/`used()`), c'est l'appelant qui applique le plancher
 * — voir `ProvisioningController::entitlements()`.
 *
 * ⚠️ **Un compte « membre » (`App\Enums\AccountKind::Member`) a TOUJOURS un quota de
 * 0**, avant même de consulter `linked_dossier_quota` : ce n'est pas un palier parmi
 * d'autres, c'est la garantie produit de la décision du 2026-10-15 (« il ne peut pas
 * en créer »). La vérification prime sur la colonne elle-même — une valeur qui s'y
 * trouverait par erreur (aucun chemin de code n'en écrit une aujourd'hui, mais rien ne
 * l'empêche en base) ne rouvrirait pas la création.
 */
final class DossierQuota
{
    public static function isCloudInstance(): bool
    {
        return filled(config('services.provisioning.token'));
    }

    public static function for(User $principal): ?int
    {
        if ($principal->isMemberAccount()) {
            return 0;
        }

        if ($principal->linked_dossier_quota !== null) {
            return (int) $principal->linked_dossier_quota;
        }

        if (self::isCloudInstance()) {
            return 0;
        }

        $configured = config('dossiers.quota');

        return $configured === null ? null : (int) $configured;
    }

    /** Dossiers actifs (non archivés) déjà créés PAR ce principal. */
    public static function used(User $principal): int
    {
        return LinkedDossier::query()
            ->active()
            ->where('owner_id', $principal->id)
            ->count();
    }

    /** `null` = illimité. */
    public static function remaining(User $principal): ?int
    {
        $quota = self::for($principal);

        return $quota === null ? null : max(0, $quota - self::used($principal));
    }

    public static function canCreate(User $principal): bool
    {
        $remaining = self::remaining($principal);

        return $remaining === null || $remaining > 0;
    }
}
