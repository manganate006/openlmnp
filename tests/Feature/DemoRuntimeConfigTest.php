<?php

/**
 * L'allowlist de `docker-entrypoint.sh` est le piège n°1 du déploiement : une variable
 * d'environnement absente de sa boucle `for var in …` est SILENCIEUSEMENT ignorée en
 * production, `-e` ou pas.
 *
 * Ce test relit les `env()` de `config/demo.php` par expression régulière plutôt que d'en
 * tenir une copie : une liste recopiée ici se périmerait à la première variable ajoutée, et
 * se mettrait alors à valider l'oubli qu'elle est censée détecter. Même patron que
 * `FeedbackRuntimeConfigTest` et `AiConfigTest`.
 */
it('propagates every demo setting from the container environment to .env', function () {
    $config = file_get_contents(base_path('config/demo.php'));
    preg_match_all("/env\('([A-Z0-9_]+)'/", $config, $matches);

    $declared = array_unique($matches[1]);

    expect($declared)->not->toBeEmpty();

    $entrypoint = file_get_contents(base_path('docker-entrypoint.sh'));

    // La boucle de propagation, entre `for var in` et le `; do` qui la referme.
    preg_match('/for var in (.*?); do/s', $entrypoint, $loop);
    $allowlist = preg_split('/\s+/', str_replace('\\', ' ', $loop[1] ?? ''), -1, PREG_SPLIT_NO_EMPTY);

    $missing = array_values(array_diff($declared, $allowlist));

    expect($missing)->toBe([], 'Variables de config/demo.php absentes de l\'allowlist de docker-entrypoint.sh : '.implode(', ', $missing));
});
