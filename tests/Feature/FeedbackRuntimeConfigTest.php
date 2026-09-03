<?php

// Rappel du piège n°1 de l'image Docker : `docker-entrypoint.sh` ne recopie vers `.env`
// qu'une allowlist FIXE. Une variable absente de cette liste est silencieusement ignorée
// en production, `-e` ou pas — `php artisan serve` ne transmettant pas l'environnement du
// processus aux workers du serveur intégré, seul `.env` compte côté web.
//
// Ce test se veut auto-entretenu : il relit les `env()` de `config/feedback.php` plutôt
// que d'en tenir une copie. Ajouter un réglage sans l'ajouter à l'allowlist fait échouer
// la suite, au lieu de produire une option qui ne répond pas en production.

it('exposes every feedback setting through the entrypoint allowlist', function () {
    $config = file_get_contents(config_path('feedback.php'));
    $entrypoint = file_get_contents(base_path('docker-entrypoint.sh'));

    preg_match_all("/env\(\s*'([A-Z0-9_]+)'/", $config, $matches);
    $variables = array_unique($matches[1]);

    expect($variables)->not->toBeEmpty();

    foreach ($variables as $variable) {
        expect($entrypoint)->toContain($variable);
    }
});

it('is enabled by default, and switchable off in one gesture', function () {
    // Un utilisateur qui héberge le logiciel chez lui doit pouvoir éteindre
    // l'invitation sans lire le code : une seule variable, pas une combinaison.
    expect(config('feedback.enabled'))->toBeTrue();

    config()->set('feedback.enabled', false);
    expect(config('feedback.enabled'))->toBeFalse();
});

it('sends nothing anywhere unless a forwarding address is configured', function () {
    // Le défaut vide est un choix : une instance auto-hébergée chez un tiers ne doit
    // pas expédier vers nos serveurs du texte saisi par ses utilisateurs.
    expect(config('feedback.forward_email'))->toBe('');
});
