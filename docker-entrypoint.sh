#!/bin/bash
set -e

cd /var/www/html

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
