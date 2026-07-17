#!/bin/bash
set -e

cd /var/www/html

# Propage les variables d'environnement runtime (docker run -e …) vers .env :
# `php artisan serve` ne transmet pas l'environnement du processus aux workers
# du serveur intégré PHP (variables_order sans E) — seul .env est lu par le web.
for var in DEMO_MODE DEMO_TTL_HOURS DEMO_MAX_ACCOUNTS MCP_ENABLED GITHUB_TOKEN GITHUB_REPO GTM_CONTAINER_ID GTM_SERVER_URL GTM_SCRIPT_PATH \
    ALLOW_REGISTRATION PROVISION_TOKEN APP_URL \
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

# Permissions
chmod -R 775 storage database bootstrap/cache 2>/dev/null || true

echo "[entrypoint] Démarrage du scheduler en arrière-plan..."
while true; do php artisan schedule:run --quiet 2>/dev/null; sleep 60; done &

echo "[entrypoint] Démarrage du serveur..."
exec php artisan serve --host=0.0.0.0 --port=8000
