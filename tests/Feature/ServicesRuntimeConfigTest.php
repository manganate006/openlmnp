<?php

/**
 * L'allowlist de `docker-entrypoint.sh` est le piège n°1 du déploiement : une variable
 * d'environnement absente de sa boucle `for var in …` est SILENCIEUSEMENT ignorée en
 * production, `-e` ou pas — `php artisan serve` ne transmet pas l'environnement du
 * processus aux workers du serveur intégré, seul `.env` est lu côté web.
 *
 * `config/services.php` n'était couvert par AUCUN test de cette famille, et c'est
 * exactement là que le trou s'est creusé : `OPENLMNP_WEBSITE_URL` est documentée dans
 * `.env.example`, lue par la vue de /login, et pourtant absente de la boucle depuis son
 * introduction — donc inopérante en Docker, l'instance restant figée sur le défaut.
 *
 * Comme ses aînés (`DemoRuntimeConfigTest`, `FeedbackRuntimeConfigTest`, `AiConfigTest`),
 * il relit le fichier de configuration par expression régulière plutôt que d'en tenir une
 * copie : une liste recopiée ici se périmerait à la première variable ajoutée, et se
 * mettrait alors à valider l'oubli qu'elle est censée détecter.
 */
it('propagates every services setting from the container environment to .env', function () {
    $config = file_get_contents(config_path('services.php'));
    preg_match_all("/env\('([A-Z0-9_]+)'/", $config, $matches);

    /*
     * Reliquats du squelette Laravel, délibérément HORS allowlist : l'application n'utilise
     * ni Postmark, ni Resend, ni SES, ni les notifications Slack — le seul transport de
     * courrier réellement configuré est `smtp` (les MAIL_* sont, eux, dans la boucle), et
     * aucun disque S3 n'est monté (config/filesystems.php : `local` par défaut). Les
     * propager promettrait un réglage qui ne pilote rien. À retirer de cette liste le jour
     * où l'un d'eux sert vraiment.
     */
    $unused = [
        'POSTMARK_API_KEY',
        'RESEND_API_KEY',
        'AWS_ACCESS_KEY_ID',
        'AWS_SECRET_ACCESS_KEY',
        'AWS_DEFAULT_REGION',
        'SLACK_BOT_USER_OAUTH_TOKEN',
        'SLACK_BOT_USER_DEFAULT_CHANNEL',
    ];

    $declared = array_values(array_diff(array_unique($matches[1]), $unused));

    expect($declared)->not->toBeEmpty();

    $entrypoint = file_get_contents(base_path('docker-entrypoint.sh'));

    // La boucle de propagation, entre `for var in` et le `; do` qui la referme : trouver le
    // nom ailleurs dans le fichier (un commentaire, par exemple) ne prouverait rien.
    preg_match('/for var in (.*?); do/s', $entrypoint, $loop);
    $allowlist = preg_split('/\s+/', str_replace('\\', ' ', $loop[1] ?? ''), -1, PREG_SPLIT_NO_EMPTY);

    $missing = array_values(array_diff($declared, $allowlist));

    expect($missing)->toBe([], 'Variables de config/services.php absentes de l\'allowlist de docker-entrypoint.sh : '.implode(', ', $missing));
});

/**
 * ⚠️ Le défaut vide n'est pas un oubli, c'est le cœur du partage public/privé : ce dépôt
 * est public et ne porte ni tarif ni argumentaire. Sans URL déclarée, /register renvoie
 * vers /login avec une explication et /login garde son seul lien neutre — une instance
 * auto-hébergée n'affiche aucune sollicitation commerciale.
 */
it('declares no subscription page by default', function () {
    expect(config('services.website.signup_url'))->toBe('');
});
