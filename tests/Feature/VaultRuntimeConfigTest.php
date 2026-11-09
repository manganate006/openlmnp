<?php

use App\Services\Vault\VaultKeyManager;

// PIÈGE N°1 DU PROJET : `docker-entrypoint.sh` ne recopie vers `.env` qu'une
// allowlist fixe de variables. Une variable oubliée est SILENCIEUSEMENT ignorée en
// production, `-e` ou pas — `php artisan serve` ne transmet pas l'environnement du
// processus à ses workers, seul `.env` compte côté web.
//
// Même patron que LoggingRuntimeConfigTest : toute variable lue par
// config/vault.php doit figurer dans la boucle de l'entrypoint.

it('propagates every vault variable through the entrypoint allowlist', function () {
    preg_match_all("/env\('([A-Z0-9_]+)'/", file_get_contents(config_path('vault.php')), $matches);

    $variables = array_unique($matches[1]);
    $entrypoint = file_get_contents(base_path('docker-entrypoint.sh'));

    expect($variables)->not->toBeEmpty();

    foreach ($variables as $variable) {
        expect($entrypoint)->toContain($variable);
    }
});

it('lets the session cookie be tuned at runtime, which the vault cookie follows', function () {
    // Le cookie porteur de clé reprend `secure` et `same_site` de la session.
    // Sans ces variables dans l'allowlist, une instance auto-hébergée servie en
    // HTTP sur un réseau local ne recevrait jamais le cookie — et le coffre
    // resterait verrouillé sans le moindre message.
    $entrypoint = file_get_contents(base_path('docker-entrypoint.sh'));

    expect($entrypoint)->toContain('SESSION_SECURE_COOKIE')
        ->and($entrypoint)->toContain('SESSION_SAME_SITE')
        ->and($entrypoint)->toContain('SESSION_LIFETIME');
});

it('declares derivation profiles as literals, never as sodium constants', function () {
    // ⚠️ ext-sodium n'expose PAS de constantes *_MIN : n'en dépendre nulle part.
    // Le fichier de configuration ne doit contenir que des entiers — on regarde
    // les jetons PHP, pas le texte, pour ne pas se heurter aux commentaires qui
    // expliquent justement pourquoi ces constantes sont proscrites ici.
    $identifiers = array_filter(
        token_get_all(file_get_contents(config_path('vault.php'))),
        fn ($token) => is_array($token) && $token[0] === T_STRING && str_starts_with($token[1], 'SODIUM_'),
    );

    expect($identifiers)->toBeEmpty();

    foreach (config('vault.kdf_profiles') as $profile) {
        expect($profile['ops'])->toBeInt()->toBeGreaterThanOrEqual(1)
            ->and($profile['mem'])->toBeInt()->toBeGreaterThanOrEqual(8192);
    }
});

it('runs the test suite on a cheap but real derivation profile', function () {
    // Le pendant de BCRYPT_ROUNDS=4 : sans ça, 500 ms par dérivation rendraient
    // la suite inexploitable. Cela reste un vrai Argon2id.
    expect(config('vault.kdf'))->toBe('test');

    $profile = app(VaultKeyManager::class)->profile();

    expect($profile['alg'])->toBe(SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13);

    // Les paramètres doivent être acceptés par libsodium tels quels.
    $derived = sodium_crypto_pwhash(
        32,
        'une phrase de passe solide',
        random_bytes(SODIUM_CRYPTO_PWHASH_SALTBYTES),
        $profile['ops'],
        $profile['mem'],
        $profile['alg'],
    );

    expect(strlen($derived))->toBe(32);
});

it('ships the sodium extension requirement so a missing one fails at install', function () {
    $composer = json_decode(file_get_contents(base_path('composer.json')), true);

    expect($composer['require'])->toHaveKey('ext-sodium')
        ->and(file_get_contents(base_path('.github/workflows/tests.yml')))->toContain('sodium');
});

it('schedules the cleanup of temporary uploads, which Livewire only does on the next upload', function () {
    $schedule = file_get_contents(base_path('routes/console.php'));

    expect($schedule)->toContain('openlmnp:cleanup-uploads');
});
