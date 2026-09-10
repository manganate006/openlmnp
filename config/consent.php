<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Portée du cookie de consentement
    |--------------------------------------------------------------------------
    |
    | Le bandeau pose son choix en JavaScript. Sans attribut `domain`, le cookie est
    | HOST-ONLY : il ne quitte jamais l'hôte qui l'a posé.
    |
    | ⚠️ VIDE PAR DÉFAUT, et ce défaut est le bon pour une instance auto-hébergée : elle
    | tourne sur un seul domaine, souvent le sien, et n'a rien à partager avec personne.
    | Y écrire une valeur en dur serait pire qu'inutile — un navigateur REFUSE un cookie
    | dont le `domain` ne couvre pas l'hôte courant, donc `openlmnp.fr` écrit ici rendrait
    | le consentement impossible à mémoriser sur toute autre installation. Le bandeau
    | reparaîtrait à chaque page, sans le moindre message d'erreur.
    |
    | ⚠️ Sur le cloud, en revanche, la vitrine (`openlmnp.fr`) et l'application
    | (`app.openlmnp.fr`) sont deux hôtes d'un même domaine, et `_ga` y est déjà partagé
    | (gtag le pose en `cookieDomain=auto`, donc sur `.openlmnp.fr`). Laisser le
    | consentement host-only créerait une asymétrie : accepter sur la vitrine donnerait un
    | `_ga` valable ici, mais l'application redemanderait quand même — et un refus là-bas
    | ne protégerait pas ici. D'où `CONSENT_COOKIE_DOMAIN=.openlmnp.fr` au `docker run`.
    |
    | ⚠️ Cette variable DOIT figurer dans l'allowlist de `docker-entrypoint.sh`, sinon le
    | `-e` est ignoré en silence en production. `ConsentRuntimeConfigTest` le vérifie.
    |
    */

    'cookie_domain' => env('CONSENT_COOKIE_DOMAIN'),

];
