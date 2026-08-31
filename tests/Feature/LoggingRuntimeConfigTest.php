<?php

// Le niveau et le canal de journalisation doivent être réglables SANS rebuild : c'est
// la seule façon, sur une instance Docker déjà en service, de passer temporairement en
// `debug` pour diagnostiquer un incident puis de revenir en arrière.
//
// Rappel du piège n°1 de l'image : `docker-entrypoint.sh` ne recopie vers `.env` qu'une
// allowlist fixe. Une variable absente de cette liste est silencieusement ignorée en
// production, `-e` ou pas — `php artisan serve` ne transmettant pas l'environnement du
// processus aux workers, seul `.env` compte côté web.

it('lets the log channel and level be tuned at runtime through the entrypoint allowlist', function () {
    $entrypoint = file_get_contents(base_path('docker-entrypoint.sh'));

    // Les trois variables réellement consommées par config/logging.php pour le canal
    // fichier (le seul utilisable dans un conteneur au stockage persistant).
    expect($entrypoint)->toContain('LOG_CHANNEL')
        ->and($entrypoint)->toContain('LOG_LEVEL')
        ->and($entrypoint)->toContain('LOG_DAILY_DAYS');
});

it('writes its logs to the persistent storage directory', function () {
    // storage/ est un volume : ce qui y est écrit survit à la recréation du conteneur.
    // Un canal qui partirait sur stdout perdrait tout l'historique à chaque déploiement.
    expect(config('logging.channels.single.path'))->toContain('storage/logs')
        ->and(config('logging.channels.daily.path'))->toContain('storage/logs');
});
