<?php

// Le nom affiché de l'instance et la liste des proxies de confiance sont documentés
// comme réglables à l'exécution (tableau des variables d'environnement de
// docs/INSTALLATION.md, commentaire de bootstrap/app.php). Encore faut-il qu'ils
// atteignent `.env` : `php artisan serve` ne transmet aux workers qu'une liste figée
// de variables (Illuminate\Foundation\Console\ServeCommand::$passthroughVariables),
// où ni l'un ni l'autre ne figure. Hors allowlist de l'entrypoint, un `-e` est donc
// silencieusement ignoré côté web — constaté sur l'image 1.6.6 le 2026-09-14 :
// `-e APP_NAME=…` laissait `.env` sur la valeur de l'image, et `TRUSTED_PROXIES`
// n'y apparaissait pas du tout.
//
// Ce réglage n'est pas cosmétique : derrière le proxy d'un PaaS (Coolify, Dokploy,
// Easypanel, YunoHost), c'est lui qui décide si les en-têtes X-Forwarded-* sont crus,
// donc si l'application se sait en https.

it('propagates the instance name and the trusted proxies through the entrypoint allowlist', function () {
    $entrypoint = file_get_contents(base_path('docker-entrypoint.sh'));

    // On n'interroge que la liste elle-même, entre `for var in` et le `; do` qui la
    // referme : trouver le nom ailleurs dans le fichier (un commentaire, par exemple)
    // ne prouverait pas qu'il est recopié.
    preg_match('/for var in (.*?); do/s', $entrypoint, $matches);
    $allowlist = $matches[1] ?? '';

    expect($allowlist)->not->toBe('')
        ->and($allowlist)->toContain('APP_NAME')
        ->and($allowlist)->toContain('TRUSTED_PROXIES');
});
