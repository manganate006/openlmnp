<?php

return [
    'enabled' => env('DEMO_MODE', false),
    'ttl_hours' => (int) env('DEMO_TTL_HOURS', 24),
    'max_accounts' => (int) env('DEMO_MAX_ACCOUNTS', 200),

    // Compte de démonstration fixe (seedé par DemoSeeder, is_demo=false → jamais purgé).
    'email' => env('DEMO_EMAIL', 'demo@openlmnp.fr'),

    /*
    |---------------------------------------------------------------------------
    | Relances avant effacement
    |---------------------------------------------------------------------------
    |
    | Les paliers s'expriment en heures RESTANTES, jamais écoulées. C'est ce qui
    | supprime tout cas particulier entre un sandbox de 24 h et un sandbox prolongé
    | à 7 jours, et ce qui rend la liste insensible à un changement de `ttl_hours` :
    |
    |   - sandbox de 24 h    → seuls 18/12/6/1 peuvent se déclencher (il ne lui reste
    |                          jamais 96 h ni 24 h), soit 6 h, 12 h, 18 h et 23 h
    |                          après l'ouverture ;
    |   - sandbox de 7 jours → 96 et 24 s'ajoutent devant, sans une ligne de code
    |                          supplémentaire.
    |
    | Format : "<heures>:<forme>", séparés par des virgules. Deux formes seulement,
    | `banner` et `modal` ; toute autre valeur est ignorée (voir DemoExpiry::thresholds()).
    */
    'reminders' => env('DEMO_REMINDERS', '96:banner,24:modal,23:banner,18:banner,12:banner,6:modal,1:modal'),

    /*
    | Espacement minimal entre deux relances, en heures. Il existe parce que la liste
    | ci-dessus sert DEUX durées de vie : sur un sandbox prolongé à 7 jours, « il reste
    | 24 h » (la modale de l'offre) et « il reste 23 h » (le premier rappel d'un sandbox
    | de 24 h) tomberaient à une heure d'intervalle. Servir un palier rend donc caducs
    | tous ceux situés dans cette fenêtre en dessous de lui.
    */
    'min_gap_hours' => (int) env('DEMO_REMINDER_MIN_GAP_HOURS', 2),

    // Durée de la prolongation accordée contre une adresse e-mail.
    'extended_ttl_days' => (int) env('DEMO_EXTENDED_TTL_DAYS', 7),

    /*
    | Cible commerciale de « Garder mes données ». VIDE PAR DÉFAUT, et c'est le
    | cœur du partage public/privé : ce dépôt ne porte ni tarif ni argumentaire.
    | Renseignée, elle est chargée dans une iframe au sein de la modale ; vide,
    | l'offre n'existe tout simplement pas et seule la prolongation est proposée.
    | Même patron que `feedback.links.pro`.
    */
    'links' => [
        'pro' => env('DEMO_URL_PRO', ''),
    ],

    /*
    | Délai au-delà duquel on considère que l'iframe ne s'affichera pas (refus
    | d'encadrement côté serveur : `X-Frame-Options`, `frame-ancestors`). Un cadre
    | refusé ne lève AUCUNE erreur exploitable en JavaScript — il reste simplement
    | vide. Sans ce délai, une régression d'infra deviendrait un écran muet.
    */
    'iframe_timeout_ms' => (int) env('DEMO_IFRAME_TIMEOUT_MS', 4000),
];
