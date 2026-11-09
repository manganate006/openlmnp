<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Dossiers liés (sous-comptes d'indivision offerts)
    |--------------------------------------------------------------------------
    |
    | Un dossier lié est un `User` à part entière (sans connexion directe),
    | ouvert par bascule d'authentification depuis un compte principal abonné.
    | Voir `_admin/docs/plan-dossiers-lies-2026-10-14.md`. Désactivé par défaut :
    | aucune route, aucun menu, aucune colonne consultée tant que c'est faux.
    |
    */
    'enabled' => (bool) env('LINKED_DOSSIERS_ENABLED', false),

    /*
    |--------------------------------------------------------------------------
    | Quota par défaut en AUTO-HÉBERGEMENT
    |--------------------------------------------------------------------------
    |
    | Sur une instance auto-hébergée (sans PROVISION_TOKEN, cf. App\Support\
    | DossierQuota::isCloudInstance()), le quota est ILLIMITÉ par défaut — cette
    | clé vaut `null`. Renseigner LINKED_DOSSIERS_QUOTA fixe un plafond identique
    | pour tous les comptes de l'instance qui n'ont pas de valeur propre en base
    | (`users.linked_dossier_quota`, qui prime toujours quand elle est renseignée).
    |
    | Sur le cloud, cette clé n'est PAS consultée : le quota par défaut y est 0
    | (rien n'est offert tant que la vitrine — lot 4 — ou la commande
    | `openlmnp:dossier-quota` ne l'ont pas posé explicitement en base).
    |
    */
    'quota' => env('LINKED_DOSSIERS_QUOTA') !== null ? (int) env('LINKED_DOSSIERS_QUOTA') : null,

    /*
    |--------------------------------------------------------------------------
    | Durée de validité d'une invitation à rejoindre un dossier, en jours
    |--------------------------------------------------------------------------
    |
    | Lot 3 : invitation du second indivisaire (App\Services\
    | LinkedDossierInvitationService). Volontairement indépendante de
    | `invitations.ttl_days` (invitations de COMPTE, menées par un administrateur) :
    | celle-ci est ouverte à n'importe quel propriétaire de dossier, pour une
    | personne qu'il connaît personnellement — rien n'impose de partager le même
    | délai. Figée sur la ligne à la création : changer ce réglage ne rallonge pas
    | les invitations déjà envoyées.
    |
    */
    'invitation_ttl_days' => (int) env('LINKED_DOSSIER_INVITATION_TTL_DAYS', 14),

];
