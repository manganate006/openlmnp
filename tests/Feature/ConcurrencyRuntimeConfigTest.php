<?php

use Illuminate\Support\Facades\DB;

// Le serveur HTTP sert plusieurs requêtes de front (PHP_CLI_SERVER_WORKERS=4 dans
// docker-entrypoint.sh). Ce fichier verrouille les DEUX moitiés du réglage, parce
// qu'aucune ne vaut sans l'autre :
//
//  - côté serveur, `--no-reload`, sans quoi `artisan serve` ignore purement et
//    simplement PHP_CLI_SERVER_WORKERS (il se contente d'un avertissement dans un
//    log que personne ne lit — vérifié : le réglage semble actif et ne l'est pas) ;
//  - côté base, WAL + `transaction_mode` IMMEDIATE, sans quoi plusieurs workers
//    produisent des « database is locked ». Mesuré à 4 processus concurrents :
//    27 % des transactions perdues avec les réglages par défaut, 66 % en ajoutant
//    WAL seul, 0 % avec WAL + IMMEDIATE.
//
// Rappel du piège n°1 de l'image : l'entrypoint ne recopie vers `.env` qu'une
// allowlist fixe. Une variable absente de cette liste est silencieusement ignorée
// en production, `-e` ou pas.

it('serves several requests at once and says so in a way artisan serve honours', function () {
    $entrypoint = file_get_contents(base_path('docker-entrypoint.sh'));

    expect($entrypoint)->toContain('PHP_CLI_SERVER_WORKERS')
        // Le drapeau est la condition d'existence des workers, pas un détail de confort.
        ->and($entrypoint)->toMatch('/artisan serve[^\n]*--no-reload/')
        // Une valeur par défaut d'au moins 2 : à 1 worker, une réponse diffusée en
        // flux gèle l'instance entière pour tous les autres utilisateurs.
        ->and($entrypoint)->toMatch('/PHP_CLI_SERVER_WORKERS="\$\{PHP_CLI_SERVER_WORKERS:-([2-9]|\d{2,})\}"/');
});

it('lets the SQLite concurrency pragmas be tuned at runtime through the entrypoint allowlist', function () {
    $entrypoint = file_get_contents(base_path('docker-entrypoint.sh'));

    // WAL réclame de la mémoire partagée et ne fonctionne pas sur un stockage réseau :
    // une instance auto-hébergée sur un NAS doit pouvoir revenir en arrière sans
    // reconstruire son image. Cette échappatoire n'existe que si l'entrypoint propage
    // les variables jusqu'au .env.
    foreach (['DB_JOURNAL_MODE', 'DB_SYNCHRONOUS', 'DB_BUSY_TIMEOUT', 'DB_TRANSACTION_MODE'] as $variable) {
        expect($entrypoint)->toContain($variable);
    }
});

it('declares the SQLite connection in WAL, with a bounded wait and immediate transactions', function () {
    $sqlite = config('database.connections.sqlite');

    expect(strtoupper((string) $sqlite['journal_mode']))->toBe('WAL')
        ->and(strtoupper((string) $sqlite['synchronous']))->toBe('NORMAL')
        // IMMEDIATE et pas DEFERRED : une transaction DEFERRED commence en lecteur, et
        // si elle écrit ensuite, SQLite rend SQLITE_BUSY sans rappeler le busy handler.
        // `busy_timeout` ne peut rien pour ce cas — seul IMMEDIATE l'évite.
        ->and(strtoupper((string) $sqlite['transaction_mode']))->toBe('IMMEDIATE');

    // Borné et non nul : PDO_SQLITE impose 60 s par défaut, soit un worker sur quatre
    // immobilisé une minute entière derrière un verrou.
    expect($sqlite['busy_timeout'])->toBeNumeric()
        ->and((int) $sqlite['busy_timeout'])->toBeGreaterThan(0)
        ->and((int) $sqlite['busy_timeout'])->toBeLessThanOrEqual(15000);
});

it('actually applies the pragmas to a real database file', function () {
    // La configuration ne prouve rien à elle seule : `SQLiteConnector` ignore en
    // silence toute clé à `null`, et le mode WAL est une propriété du fichier. On
    // ouvre donc une vraie base sur disque et on relit ce que SQLite en dit.
    $base = tempnam(sys_get_temp_dir(), 'olmnp-wal-');
    $path = $base.'.sqlite';
    @unlink($base);
    touch($path);

    config()->set('database.connections.sqlite_probe', array_merge(
        config('database.connections.sqlite'),
        ['database' => $path],
    ));

    try {
        $probe = DB::connection('sqlite_probe');

        expect($probe->select('pragma journal_mode')[0]->journal_mode)->toBe('wal')
            ->and((int) $probe->select('pragma busy_timeout')[0]->timeout)->toBe(5000);
    } finally {
        DB::purge('sqlite_probe');
        foreach ([$base, $path, $path.'-wal', $path.'-shm'] as $file) {
            @unlink($file);
        }
    }
});
