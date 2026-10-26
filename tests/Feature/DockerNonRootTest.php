<?php

// GHSA-j4gm-g8m8-93x2, point 20 — le conteneur ne fait plus tourner l'application en root.
// L'image elle-même se vérifie par un build (voir docs/INSTALLATION.md) ; ces tests tiennent
// les garde-fous du script, ceux qu'une retouche défait sans bruit.

use Dotenv\Dotenv;
use Illuminate\Support\Facades\Process;

function entrypoint(): string
{
    return file_get_contents(base_path('docker-entrypoint.sh'));
}

it('lance le serveur, le planificateur et la commande alternative sous l\'utilisateur de l\'application', function () {
    $script = entrypoint();

    expect($script)->toContain('APP_USER=www-data')
        ->toMatch('/exec su-exec "\$APP_USER" \/usr\/local\/bin\/php artisan serve[^\n]*--no-reload/')
        ->toContain('su-exec "$APP_USER" sh -c "$SCHEDULER_LOOP" &')
        ->toContain("SCHEDULER_LOOP='while true; do /usr/local/bin/php artisan schedule:run")
        ->toContain('exec su-exec "$APP_USER" "$@"')
        ->toContain('as_app /usr/local/bin/php artisan migrate --force');

    expect(file_get_contents(base_path('Dockerfile')))->toContain('su-exec')
        ->toContain('COPY docker/php-as-app.sh /usr/local/sbin/php')
        ->not->toMatch('/^USER /m');
});

it('remet les volumes à l\'utilisateur de l\'application sans suivre les liens symboliques', function () {
    expect(entrypoint())->toContain('find -P storage database bootstrap/cache ! -user "$APP_USER"')
        ->toContain('chown -h "$APP_USER:$APP_USER"')
        ->toContain('[ -L "$keyfile" ] && rm -f "$keyfile"');
});

it('relaie un « docker exec … php » lancé en root vers www-data, sauf repli en root', function () {
    $shim = file_get_contents(base_path('docker/php-as-app.sh'));

    expect($shim)->toContain('exec su-exec www-data /usr/local/bin/php "$@"')
        ->toContain('/run/openlmnp/run-as-root')
        ->and(entrypoint())->toContain('ROOT_MODE_MARKER=/run/openlmnp/run-as-root');
});

it('écrit dans .env des valeurs que Laravel relit telles quelles', function () {
    preg_match('/^env_set\(\) \{.*?^\}$/ms', entrypoint(), $function);
    expect($function)->not->toBeEmpty();

    $dir = sys_get_temp_dir().'/env-set-'.uniqid();
    mkdir($dir);
    file_put_contents("$dir/.env", "APP_NAME=OpenLMNP\nCSP_POLICY=old\n");
    file_put_contents("$dir/f.sh", $function[0]);

    $values = [
        'CSP_POLICY' => "default-src 'self'; img-src 'self' https: data:",
        'MAIL_PASSWORD' => 'a|b&c\\d"e $f #g',
        'APP_URL' => 'https://app.example.test',
        'MAIL_FROM_NAME' => 'OpenLMNP Support',
    ];

    $commands = ['set -e', 'cd '.escapeshellarg($dir), '. ./f.sh'];
    foreach ($values as $var => $value) {
        $commands[] = 'env_set '.$var.' '.escapeshellarg($value);
    }

    $result = Process::run(['bash', '-c', implode("\n", $commands)]);
    expect($result->successful())->toBeTrue($result->errorOutput());

    $parsed = Dotenv::parse(file_get_contents("$dir/.env"));

    expect($parsed['APP_NAME'])->toBe('OpenLMNP')
        ->and(substr_count(file_get_contents("$dir/.env"), 'CSP_POLICY='))->toBe(1);
    foreach ($values as $var => $value) {
        expect($parsed[$var])->toBe($value);
    }
    // Une valeur simple s'écrit comme avant, sans guillemets.
    expect(file_get_contents("$dir/.env"))->toContain("\nAPP_URL=https://app.example.test\n");

    \Illuminate\Support\Facades\File::deleteDirectory($dir);
});
