<?php

return [

    /*
    |--------------------------------------------------------------------------
    | DVF — « Demandes de valeurs foncières » (DGFiP)
    |--------------------------------------------------------------------------
    |
    | Estimation de la valeur vénale d'un bien à partir des ventes réelles de sa
    | commune, publiées sur data.gouv.fr sous Licence Ouverte 2.0. Fichiers PAR
    | COMMUNE (20 Ko à 4 Mo), sans clé ni quota : le fichier national de 523 Mo
    | n'est jamais téléchargé.
    |
    | ⚠️ APPEL SORTANT. C'est la seule fonctionnalité du logiciel qui interroge un
    | service externe à la demande de l'utilisateur, et elle révèle la COMMUNE du
    | bien à data.gouv.fr. Elle n'est donc déclenchée que par une action explicite
    | (bouton « Estimer »), jamais au chargement d'un écran, et se coupe
    | entièrement avec DVF_ENABLED=false. Une fois une commune consultée, le
    | résultat est conservé sur disque : l'instance refonctionne hors ligne.
    |
    */

    'dvf' => [
        'enabled' => (bool) env('DVF_ENABLED', true),

        'base_url' => env('DVF_BASE_URL', 'https://files.data.gouv.fr/geo-dvf/latest/csv'),

        // API Découpage administratif : commune ou code postal → code INSEE.
        'geo_api_url' => env('DVF_GEO_API_URL', 'https://geo.api.gouv.fr'),

        'timeout' => (int) env('DVF_TIMEOUT', 8),

        // Millésimes publiés. ⚠️ À bumper à chaque publication (semestrielle) : l'écran
        // affiche les années réellement utilisées, donc un oubli finit par se voir.
        'years' => [2021, 2022, 2023, 2024, 2025],

        // En deçà, on refuse d'afficher une médiane plutôt que d'en proposer une fragile
        // comme base amortissable.
        'min_sample' => (int) env('DVF_MIN_SAMPLE', 5),

        'cache_days' => (int) env('DVF_CACHE_DAYS', 30),

        // Recherches par utilisateur et par minute (garde-fou, pas une facturation).
        'rate_limit' => (int) env('DVF_RATE_LIMIT', 20),
    ],

];
