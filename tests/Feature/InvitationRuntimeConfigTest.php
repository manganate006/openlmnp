<?php

/**
 * Même patron que `DemoRuntimeConfigTest` / `FeedbackRuntimeConfigTest` / `AiConfigTest` :
 * l'allowlist de `docker-entrypoint.sh` est le piège n°1 du déploiement, une variable
 * absente de sa boucle est SILENCIEUSEMENT ignorée en production.
 *
 * On relit les `env()` de `config/invitations.php` plutôt que d'en tenir une copie : une
 * liste recopiée ici se périmerait à la première variable ajoutée, et validerait alors
 * l'oubli qu'elle est censée détecter.
 */
it('propagates every invitation setting from the container environment to .env', function () {
    $config = file_get_contents(base_path('config/invitations.php'));
    preg_match_all("/env\('([A-Z0-9_]+)'/", $config, $matches);

    $declared = array_unique($matches[1]);

    expect($declared)->not->toBeEmpty();

    $entrypoint = file_get_contents(base_path('docker-entrypoint.sh'));
    preg_match('/for var in (.*?); do/s', $entrypoint, $loop);
    $allowlist = preg_split('/\s+/', str_replace('\\', ' ', $loop[1] ?? ''), -1, PREG_SPLIT_NO_EMPTY);

    expect(array_values(array_diff($declared, $allowlist)))->toBe([]);
});
