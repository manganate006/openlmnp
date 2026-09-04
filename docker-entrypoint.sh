#!/bin/bash
set -e

cd /var/www/html

# Mode commande alternative (`docker run … openlmnp <cmd>`) : la préparation
# s'exécute quand même, mais stdout doit rester vierge pour les protocoles
# parlés sur stdio (ex. JSON-RPC de `php artisan mcp:start openlmnp`) —
# on détourne stdout vers stderr le temps de la préparation.
if [ "$#" -gt 0 ]; then
    exec 3>&1 1>&2
fi

# Propage les variables d'environnement runtime (docker run -e …) vers .env :
# `php artisan serve` ne transmet pas l'environnement du processus aux workers
# du serveur intégré PHP (variables_order sans E) — seul .env est lu par le web.
for var in APP_KEY APP_LOCALE APP_FALLBACK_LOCALE DEMO_MODE DEMO_TTL_HOURS DEMO_MAX_ACCOUNTS DEMO_EMAIL \
    MCP_ENABLED MCP_DEMO_ENABLED MCP_DEMO_TOKEN MCP_DEMO_RATE_LIMIT \
    GITHUB_TOKEN GITHUB_REPO GTM_CONTAINER_ID GTM_SERVER_URL GTM_SCRIPT_PATH \
    ALLOW_REGISTRATION PROVISION_TOKEN APP_URL UPDATE_SELF_APPLY UPDATE_DOCKER_IMAGE \
    LOG_CHANNEL LOG_LEVEL LOG_DAILY_DAYS \
    FEEDBACK_ENABLED FEEDBACK_AUDIENCES FEEDBACK_MIN_SECONDS FEEDBACK_MIN_ACTIONS FEEDBACK_ACTIONS \
    FEEDBACK_VARIANTS FEEDBACK_MIN_SAMPLE \
    FEEDBACK_RETURN_DAYS FEEDBACK_COOLDOWN_DAYS FEEDBACK_FORWARD_EMAIL FEEDBACK_CONTACT_EMAIL \
    DVF_ENABLED DVF_BASE_URL DVF_GEO_API_URL DVF_TIMEOUT DVF_MIN_SAMPLE DVF_CACHE_DAYS DVF_RATE_LIMIT \
    FEEDBACK_URL_STAR FEEDBACK_URL_SPONSOR FEEDBACK_URL_DISCUSSIONS FEEDBACK_URL_ISSUES FEEDBACK_URL_PRO \
    MAIL_MAILER MAIL_SCHEME MAIL_HOST MAIL_PORT MAIL_USERNAME MAIL_PASSWORD MAIL_FROM_ADDRESS MAIL_FROM_NAME; do
    value="${!var-}"
    if [ -n "$value" ]; then
        if grep -q "^${var}=" .env 2>/dev/null; then
            sed -i "s|^${var}=.*|${var}=${value}|" .env
        else
            echo "${var}=${value}" >> .env
        fi
    fi
done

# Le volume monté sur database/ masque les fichiers de l'image : resynchroniser
# migrations/seeders/factories depuis la copie de référence (sinon les nouvelles
# migrations n'atteignent jamais la prod).
if [ -d /database-dist/migrations ]; then
    mkdir -p database/migrations database/seeders database/factories
    cp -f /database-dist/migrations/*.php database/migrations/ 2>/dev/null || true
    cp -f /database-dist/seeders/*.php database/seeders/ 2>/dev/null || true
    cp -f /database-dist/factories/*.php database/factories/ 2>/dev/null || true
fi

# Même chose pour storage/ : un volume (nommé ou bind-mount) tout juste créé
# masque les répertoires de l'image. Sans ça, le premier boot plante avant
# même d'atteindre les migrations ("Please provide a valid cache path", faute
# de storage/framework/views).
mkdir -p storage/app/public storage/app/data storage/logs \
    storage/framework/sessions storage/framework/views storage/framework/cache \
    bootstrap/cache

# APP_KEY par instance : l'image publiée n'embarque aucune clé (une clé commune
# à toutes les installations permettrait de déchiffrer sessions/cookies d'autrui).
# Priorité : -e APP_KEY (propagé ci-dessus) > clé persistée dans le volume
# storage/ > génération au premier démarrage, puis persistance.
if ! grep -q '^APP_KEY=.\+' .env; then
    keyfile="storage/app/.app_key"
    if [ -s "$keyfile" ]; then
        sed -i "s|^APP_KEY=.*|APP_KEY=$(cat "$keyfile")|" .env
        echo "[entrypoint] APP_KEY restaurée depuis ${keyfile}."
    else
        php artisan key:generate --force
        mkdir -p storage/app
        grep '^APP_KEY=' .env | cut -d= -f2- > "$keyfile"
        chmod 600 "$keyfile"
        echo "[entrypoint] APP_KEY générée et persistée dans ${keyfile}."
    fi
fi

# Nettoyage des caches
php artisan optimize:clear 2>/dev/null || true
php artisan storage:link 2>/dev/null || true

# Créer la base SQLite si elle n'existe pas
if [ ! -f database/database.sqlite ]; then
    echo "[entrypoint] Base de données absente, création initiale..."
    touch database/database.sqlite
    chmod 775 database/database.sqlite
    php artisan migrate --force
    php artisan db:seed --force
    echo "[entrypoint] Base initialisée avec seed."
else
    echo "[entrypoint] Base existante détectée, migration incrémentale..."
    php artisan migrate --force
    echo "[entrypoint] Migrations appliquées."
fi

# Token MCP démo public (idempotent ; no-op si MCP_DEMO_ENABLED=false)
php artisan openlmnp:mcp-demo-token 2>/dev/null || echo "[entrypoint] mcp-demo-token ignoré"

# Permissions
chmod -R 775 storage database bootstrap/cache 2>/dev/null || true

# Commande alternative : restaurer stdout puis exécuter (remplace serveur web + scheduler)
if [ "$#" -gt 0 ]; then
    exec 1>&3 3>&-
    exec "$@"
fi

echo "[entrypoint] Démarrage du scheduler en arrière-plan..."
while true; do php artisan schedule:run --quiet 2>/dev/null; sleep 60; done &

# Le serveur HTTP intégré de PHP est MONO-PROCESSUS par défaut : il sert une
# requête à la fois et met les autres en file. Mesuré le 2026-09-01 sur 10 requêtes
# parallèles, temps par requête : 0,049 → 0,324 s avec 1 worker (escalier régulier
# de ~31 ms, la signature d'une sérialisation) contre 0,071 → 0,170 s avec 4.
#
# 4 et pas davantage : le LXC 147 n'a que 2 cœurs et 1 Go, partagés avec le
# conteneur de la vitrine. Chaque worker est un processus PHP complet. Une instance
# auto-hébergée plus grosse peut surcharger la valeur par `-e PHP_CLI_SERVER_WORKERS`.
#
# ⚠️ DEUX pièges, tous deux silencieux :
#  1. La variable se règle ICI, pas dans l'allowlist ci-dessus : elle est lue par le
#     binaire PHP au démarrage du serveur et n'a rien à faire dans le `.env`.
#  2. `--no-reload` est OBLIGATOIRE : sans lui, `artisan serve` surveille les
#     fichiers, refuse de forker, et se contente d'écrire « Unable to respect the
#     PHP_CLI_SERVER_WORKERS environment variable » dans un log que personne ne lit.
#     Le réglage semblerait actif sans l'être. Sans inconvénient ici : le code est
#     figé dans l'image (`opcache.validate_timestamps=0`), il ne change pas à chaud.
export PHP_CLI_SERVER_WORKERS="${PHP_CLI_SERVER_WORKERS:-4}"

echo "[entrypoint] Démarrage du serveur (${PHP_CLI_SERVER_WORKERS} workers)..."
exec php artisan serve --host=0.0.0.0 --port=8000 --no-reload
