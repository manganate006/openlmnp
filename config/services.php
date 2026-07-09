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

];
