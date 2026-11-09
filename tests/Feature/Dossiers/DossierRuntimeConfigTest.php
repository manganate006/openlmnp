<?php

// PIÈGE N°1 DU PROJET : `docker-entrypoint.sh` ne recopie vers `.env` qu'une
// allowlist FIXE de variables. Une variable ajoutée à `config/dossiers.php` sans
// être ajoutée à cette boucle est silencieusement ignorée en production, `-e`
// ou pas. Même patron que tests/Feature/VaultRuntimeConfigTest.php,
// tests/Feature/FeedbackRuntimeConfigTest.php, etc. — ce fichier balaie les
// `env()` plutôt que d'en tenir une copie, pour ne jamais se périmer.

it('propage chaque variable de config/dossiers.php à travers l\'allowlist de l\'entrypoint', function () {
    preg_match_all("/env\('([A-Z0-9_]+)'/", file_get_contents(config_path('dossiers.php')), $matches);

    $variables = array_unique($matches[1]);
    $entrypoint = file_get_contents(base_path('docker-entrypoint.sh'));

    expect($variables)->not->toBeEmpty();

    foreach ($variables as $variable) {
        expect($entrypoint)->toContain($variable);
    }
});
