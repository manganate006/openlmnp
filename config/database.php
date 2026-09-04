<?php

use Illuminate\Support\Str;
use Pdo\Mysql;

return [

    /*
    |--------------------------------------------------------------------------
    | Default Database Connection Name
    |--------------------------------------------------------------------------
    |
    | Here you may specify which of the database connections below you wish
    | to use as your default connection for database operations. This is
    | the connection which will be utilized unless another connection
    | is explicitly specified when you execute a query / statement.
    |
    */

    'default' => env('DB_CONNECTION', 'sqlite'),

    /*
    |--------------------------------------------------------------------------
    | Database Connections
    |--------------------------------------------------------------------------
    |
    | Below are all of the database connections defined for your application.
    | An example configuration is provided for each database system which
    | is supported by Laravel. You're free to add / remove connections.
    |
    */

    'connections' => [

        'sqlite' => [
            'driver' => 'sqlite',
            'url' => env('DB_URL'),
            'database' => env('DB_DATABASE', database_path('database.sqlite')),
            'prefix' => '',
            'foreign_key_constraints' => env('DB_FOREIGN_KEYS', true),

            // ── Concurrence (à lire avec docker-entrypoint.sh) ────────────────────────
            // Le serveur HTTP sert 4 requêtes de front (PHP_CLI_SERVER_WORKERS, réglé
            // dans l'entrypoint). Ces quatre lignes sont ce qui rend cette concurrence
            // tenable pour SQLite : elles vont ENSEMBLE, et l'ordre dans lequel on les
            // ajoute n'est pas neutre (voir la mesure sous `transaction_mode`).
            //
            //   journal_mode : WAL — un écrivain ne bloque plus les lecteurs, et la
            //     transaction n'est plus payée d'un fsync du journal. Mesuré sur le
            //     stockage de production, 200 écritures d'affilée : 7,30 ms par
            //     transaction en `delete`+FULL contre 0,03 ms en WAL+NORMAL.
            //     ⚠️ Le mode est une propriété PERSISTANTE du FICHIER, pas de la
            //     connexion : la bascule a lieu une fois, à la première ouverture.
            //   synchronous : NORMAL — sûr en WAL. Une coupure de courant peut coûter
            //     les dernières transactions, jamais l'intégrité de la base.
            //   busy_timeout : attendre un verrou jusqu'à 5 s au lieu d'échouer.
            //     ⚠️ Ce n'est pas un ajout mais un PLAFOND : PDO_SQLITE impose déjà
            //     60 s par défaut, soit un worker sur quatre immobilisé une minute.
            //   transaction_mode : IMMEDIATE, et c'est la ligne qui fait tout le
            //     travail. Une transaction DEFERRED commence en lecteur ; si elle
            //     écrit ensuite alors qu'un autre a écrit entre-temps, SQLite rend
            //     SQLITE_BUSY **sans jamais rappeler le busy handler** — réessayer ne
            //     pourrait pas aboutir, le lecteur devant d'abord lâcher son instantané.
            //     `busy_timeout` est donc sans effet sur ce cas précis, et WAL l'aggrave.
            //     Mesuré, 4 processus × 50 transactions lecture-puis-écriture :
            //       delete + DEFERRED (l'existant) ...... 55/200 perdues (27 %)
            //       WAL    + DEFERRED .................. 133/200 perdues (66 %)
            //       WAL    + IMMEDIATE .................   0/200 perdues
            //     Passer en WAL sans passer en IMMEDIATE dégraderait la production.
            //
            // ⚠️ Échappatoire volontaire : WAL réclame de la mémoire partagée et ne
            // fonctionne PAS sur un stockage réseau (NFS, SMB). Une instance
            // auto-hébergée dont le volume vit sur un NAS doit pouvoir revenir au
            // journal classique sans reconstruire son image — d'où les `env()`, et
            // d'où la présence de ces variables dans l'allowlist de l'entrypoint.
            'journal_mode' => env('DB_JOURNAL_MODE', 'WAL'),
            'synchronous' => env('DB_SYNCHRONOUS', 'NORMAL'),
            'busy_timeout' => env('DB_BUSY_TIMEOUT', 5000),
            'transaction_mode' => env('DB_TRANSACTION_MODE', 'IMMEDIATE'),
        ],

        'mysql' => [
            'driver' => 'mysql',
            'url' => env('DB_URL'),
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '3306'),
            'database' => env('DB_DATABASE', 'laravel'),
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', ''),
            'unix_socket' => env('DB_SOCKET', ''),
            'charset' => env('DB_CHARSET', 'utf8mb4'),
            'collation' => env('DB_COLLATION', 'utf8mb4_unicode_ci'),
            'prefix' => '',
            'prefix_indexes' => true,
            'strict' => true,
            'engine' => null,
            'options' => extension_loaded('pdo_mysql') ? array_filter([
                (PHP_VERSION_ID >= 80500 ? Mysql::ATTR_SSL_CA : PDO::MYSQL_ATTR_SSL_CA) => env('MYSQL_ATTR_SSL_CA'),
            ]) : [],
        ],

        'mariadb' => [
            'driver' => 'mariadb',
            'url' => env('DB_URL'),
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '3306'),
            'database' => env('DB_DATABASE', 'laravel'),
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', ''),
            'unix_socket' => env('DB_SOCKET', ''),
            'charset' => env('DB_CHARSET', 'utf8mb4'),
            'collation' => env('DB_COLLATION', 'utf8mb4_unicode_ci'),
            'prefix' => '',
            'prefix_indexes' => true,
            'strict' => true,
            'engine' => null,
            'options' => extension_loaded('pdo_mysql') ? array_filter([
                (PHP_VERSION_ID >= 80500 ? Mysql::ATTR_SSL_CA : PDO::MYSQL_ATTR_SSL_CA) => env('MYSQL_ATTR_SSL_CA'),
            ]) : [],
        ],

        'pgsql' => [
            'driver' => 'pgsql',
            'url' => env('DB_URL'),
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '5432'),
            'database' => env('DB_DATABASE', 'laravel'),
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', ''),
            'charset' => env('DB_CHARSET', 'utf8'),
            'prefix' => '',
            'prefix_indexes' => true,
            'search_path' => 'public',
            'sslmode' => env('DB_SSLMODE', 'prefer'),
        ],

        'sqlsrv' => [
            'driver' => 'sqlsrv',
            'url' => env('DB_URL'),
            'host' => env('DB_HOST', 'localhost'),
            'port' => env('DB_PORT', '1433'),
            'database' => env('DB_DATABASE', 'laravel'),
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', ''),
            'charset' => env('DB_CHARSET', 'utf8'),
            'prefix' => '',
            'prefix_indexes' => true,
            // 'encrypt' => env('DB_ENCRYPT', 'yes'),
            // 'trust_server_certificate' => env('DB_TRUST_SERVER_CERTIFICATE', 'false'),
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Migration Repository Table
    |--------------------------------------------------------------------------
    |
    | This table keeps track of all the migrations that have already run for
    | your application. Using this information, we can determine which of
    | the migrations on disk haven't actually been run on the database.
    |
    */

    'migrations' => [
        'table' => 'migrations',
        'update_date_on_publish' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Redis Databases
    |--------------------------------------------------------------------------
    |
    | Redis is an open source, fast, and advanced key-value store that also
    | provides a richer body of commands than a typical key-value system
    | such as Memcached. You may define your connection settings here.
    |
    */

    'redis' => [

        'client' => env('REDIS_CLIENT', 'phpredis'),

        'options' => [
            'cluster' => env('REDIS_CLUSTER', 'redis'),
            'prefix' => env('REDIS_PREFIX', Str::slug((string) env('APP_NAME', 'laravel')).'-database-'),
            'persistent' => env('REDIS_PERSISTENT', false),
        ],

        'default' => [
            'url' => env('REDIS_URL'),
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'username' => env('REDIS_USERNAME'),
            'password' => env('REDIS_PASSWORD'),
            'port' => env('REDIS_PORT', '6379'),
            'database' => env('REDIS_DB', '0'),
            'max_retries' => env('REDIS_MAX_RETRIES', 3),
            'backoff_algorithm' => env('REDIS_BACKOFF_ALGORITHM', 'decorrelated_jitter'),
            'backoff_base' => env('REDIS_BACKOFF_BASE', 100),
            'backoff_cap' => env('REDIS_BACKOFF_CAP', 1000),
        ],

        'cache' => [
            'url' => env('REDIS_URL'),
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'username' => env('REDIS_USERNAME'),
            'password' => env('REDIS_PASSWORD'),
            'port' => env('REDIS_PORT', '6379'),
            'database' => env('REDIS_CACHE_DB', '1'),
            'max_retries' => env('REDIS_MAX_RETRIES', 3),
            'backoff_algorithm' => env('REDIS_BACKOFF_ALGORITHM', 'decorrelated_jitter'),
            'backoff_base' => env('REDIS_BACKOFF_BASE', 100),
            'backoff_cap' => env('REDIS_BACKOFF_CAP', 1000),
        ],

    ],

];
