<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'github' => [
        'token' => env('GITHUB_TOKEN'),
        'repo' => env('GITHUB_REPO', 'openlmnp/openlmnp'),
    ],

    // API de provisioning de comptes (POST /api/admin/users). Désactivée (404)
    // tant que PROVISION_TOKEN est vide — cas normal d'une instance self-hosted.
    'provisioning' => [
        'token' => env('PROVISION_TOKEN'),
    ],

    // Google Tag Manager (optionnel) : rien n'est injecté tant que `id` est vide.
    // `server_url` permet un GTM server-side auto-hébergé, `script_path` un
    // chemin de script renommé (anti-adblock).
    'gtm' => [
        'id' => env('GTM_CONTAINER_ID'),
        // `?:` et non un défaut env() : un `GTM_SERVER_URL=` vide dans .env doit
        // retomber sur les serveurs Google, pas produire une URL vide.
        'server_url' => env('GTM_SERVER_URL') ?: 'https://www.googletagmanager.com',
        'script_path' => env('GTM_SCRIPT_PATH') ?: '/gtm.js',
    ],

    // Site officiel du projet. Affiché comme lien « Découvrir OpenLMNP » sur /login.
    // Défaut visible (openlmnp.fr) ; vider OPENLMNP_WEBSITE_URL pour masquer le lien
    // sur une instance auto-hébergée.
    'website' => [
        'url' => env('OPENLMNP_WEBSITE_URL', 'https://openlmnp.fr'),
    ],

    // Télémétrie anonyme (opt-out). Un check-in quotidien envoie UNIQUEMENT un
    // identifiant aléatoire (UUID) + la version de l'app, pour permettre au projet
    // de compter le nombre d'instances auto-hébergées. AUCUNE donnée comptable,
    // personnelle ou fiscale n'est transmise. Pour la désactiver complètement :
    // TELEMETRY_ENABLED=false dans .env (aucune requête sortante alors).
    'telemetry' => [
        'enabled' => env('TELEMETRY_ENABLED', true),
        'url' => env('TELEMETRY_URL', 'https://openlmnp.fr/api/instances/checkin'),
        // Indice grossier et non identifiant du mode de déploiement (selfhosted/cloud/dev).
        'install_type' => env('TELEMETRY_INSTALL_TYPE', 'selfhosted'),
    ],

];
