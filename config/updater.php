<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Mise à jour en place
    |--------------------------------------------------------------------------
    |
    | La mise à jour depuis le panel (page « Mises à jour ») et la commande
    | `app:auto-update` réécrivent les fichiers de l'application sur place :
    | téléchargement d'un tarball, `rsync`, `composer install`, `npm run build`.
    |
    | Cela suppose un hébergement où le code est modifiable et où ces binaires
    | existent — vrai pour une install bare-metal / community-script, faux pour
    | l'image Docker officielle, volontairement immuable et minimale (mise à
    | jour = `docker pull` + recréation du conteneur). `.env.docker` positionne
    | donc UPDATE_SELF_APPLY=false.
    |
    | La *détection* d'une nouvelle version reste active dans les deux cas :
    | seule l'application automatique est neutralisée.
    |
    */

    'self_apply' => env('UPDATE_SELF_APPLY', true),

    /*
    | Commande à afficher aux utilisateurs Docker quand la mise à jour en place
    | est désactivée. Image officielle publiée par .github/workflows/docker-publish.yml.
    */
    'docker_image' => env('UPDATE_DOCKER_IMAGE', 'manganate06/openlmnp:latest'),
];
