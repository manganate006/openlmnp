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

    /*
    | Consigne affichée à la place de celle-ci quand la mise à jour en place est
    | désactivée sur un hébergement qui n'est pas Docker. Un gestionnaire de paquets qui
    | prend les mises à jour en charge (YunoHost, et demain d'autres) y met la sienne :
    | sans ça, son utilisateur lit « docker pull » sur une machine sans Docker, et suit
    | une instruction qui ne s'applique pas à lui.
    */
    'blocked_hint' => env('UPDATE_SELF_APPLY_HINT'),

    /*
    |--------------------------------------------------------------------------
    | Relevé des versions publiées
    |--------------------------------------------------------------------------
    |
    | La page « Nouveautés » affiche à tout utilisateur sa version installée, la
    | dernière publiée et les notes des dernières releases. Les deux dernières
    | viennent de l'API GitHub : c'est le SEUL appel sortant de cette page.
    |
    | UPDATE_CHECK_ENABLED=false le coupe entièrement — pour une instance sans
    | accès sortant, ou qui refuse de contacter GitHub. La version installée,
    | elle, est lue dans `version.json` et reste affichée dans tous les cas.
    |
    */

    'check_enabled' => env('UPDATE_CHECK_ENABLED', true),

    /*
    | Durée de conservation d'un relevé RÉUSSI. L'API GitHub non authentifiée est
    | plafonnée à 60 requêtes/h et par IP : sans cache, chaque affichage de page en
    | consommait trois. Les boutons « Vérifier » forcent le rafraîchissement.
    */

    'check_ttl' => 3600,

    /*
    | Durée de conservation d'un relevé EN ÉCHEC — bien plus courte, mais non nulle :
    | sans elle, une instance hors ligne repaie le délai d'attente HTTP (10 s) à
    | chaque affichage. Valeurs en dur : une durée n'a pas à encombrer l'allowlist
    | de `docker-entrypoint.sh`, contrairement à l'interrupteur ci-dessus.
    */

    'failure_ttl' => 300,
];
