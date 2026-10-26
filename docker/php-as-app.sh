#!/bin/sh
# Relais installé en /usr/local/sbin/php, qui passe AVANT /usr/local/bin/php dans le PATH
# de l'image (GHSA-j4gm-g8m8-93x2, point 20).
#
# L'application tourne sous « www-data ». Un `docker exec openlmnp php artisan …` s'exécute
# pourtant en root par défaut : les fichiers qu'il crée (journal du jour, cache, base
# SQLite et ses fichiers -wal/-shm) appartiendraient à root, et l'application ne pourrait
# plus y écrire — erreurs 500 jusqu'au redémarrage suivant. Ce relais rend la main à
# www-data quand php est lancé en root.
#
# Il s'efface si l'entrypoint a dû se replier en root (volumes impossibles à remettre à
# www-data), ou si OPENLMNP_AS_ROOT=1 est posé pour une opération qui exige vraiment root.
if [ "$(id -u)" = "0" ] && [ -z "${OPENLMNP_AS_ROOT:-}" ] && [ ! -e /run/openlmnp/run-as-root ] \
    && command -v su-exec >/dev/null 2>&1 && id www-data >/dev/null 2>&1; then
    exec su-exec www-data /usr/local/bin/php "$@"
fi
exec /usr/local/bin/php "$@"
