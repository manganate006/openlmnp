#!/bin/bash
set -e

cd /var/www/html

# --- Utilisateur non privilégié (GHSA-j4gm-g8m8-93x2, point 20) ---------------------------
# Le serveur web, le planificateur et toutes les commandes artisan tournent sous
# « www-data » (UID/GID 82, l'utilisateur de l'image PHP officielle). Le conteneur démarre
# pourtant en root, pour trois choses seulement : écrire .env (code de l'image, à root),
# créer le lien public/storage, et remettre à www-data les volumes database/ et storage/
# — ceux d'une installation antérieure appartiennent entièrement à root. Il passe ensuite
# la main par su-exec. Aucune action manuelle à la mise à jour.
#
# Démarré directement sous un autre utilisateur (`--user`, Kubernetes runAsUser…), le
# script ne touche à aucun droit et exécute tout sous celui-ci.
#
# Repli : si les volumes ne peuvent pas être rendus inscriptibles pour www-data (chown
# refusé : partage NFS en root_squash, podman sans privilège sur certains stockages…),
# l'application tourne en root comme avant, avec un avertissement, plutôt que de ne pas
# démarrer.
APP_USER=www-data
DROP_PRIVILEGES=
if [ "$(id -u)" = "0" ] && id "$APP_USER" >/dev/null 2>&1 && command -v su-exec >/dev/null 2>&1; then
    DROP_PRIVILEGES=1
fi
# Relu par le relais /usr/local/sbin/php (docker/php-as-app.sh) pour un `docker exec`.
ROOT_MODE_MARKER=/run/openlmnp/run-as-root
mkdir -p /run/openlmnp 2>/dev/null || true
rm -f "$ROOT_MODE_MARKER" 2>/dev/null || true

# Commande exécutée sous l'utilisateur de l'application (ou telle quelle, sans privilèges
# à abandonner). /usr/local/bin/php est le vrai binaire, pas le relais.
as_app() {
    if [ -n "$DROP_PRIVILEGES" ]; then
        su-exec "$APP_USER" "$@"
    else
        "$@"
    fi
}

# Remet à l'utilisateur de l'application ce qui ne lui appartient pas : volumes d'une
# version antérieure, fichier créé par un `docker exec` en root. `! -user` ne touche que ce
# qu'il faut corriger : rapide même sur un gros volume.
# ⚠️ `chown -h` et `find -P` : un lien symbolique posé dans storage/ par l'application
# change de propriétaire LUI-MÊME. Suivi, il ferait donner à www-data, par root, le fichier
# visé (n'importe où dans l'image) au redémarrage suivant.
fix_ownership() {
    [ -n "$DROP_PRIVILEGES" ] || return 0
    find -P storage database bootstrap/cache ! -user "$APP_USER" \
        -exec chown -h "$APP_USER:$APP_USER" {} + 2>/dev/null || true
}

# Écrit VAR=valeur dans .env, entre guillemets si la valeur contient autre chose que des
# caractères sûrs (espace, quote, point-virgule : CSP_POLICY en est pleine). Les valeurs
# simples s'écrivent exactement comme avant. Pas de sed : un « | » ou un « & » dans la
# valeur (mot de passe) y était interprété.
env_set() {
    local var=$1 value=$2 line
    if [[ "$value" =~ ^[A-Za-z0-9_./:@+=,*-]*$ ]]; then
        line="${var}=${value}"
    else
        value=${value//\\/\\\\}
        value=${value//\"/\\\"}
        line="${var}=\"${value}\""
    fi
    if grep -q "^${var}=" .env 2>/dev/null; then
        local tmp
        tmp=$(mktemp)
        V="$var" L="$line" awk 'index($0, ENVIRON["V"] "=") == 1 { print ENVIRON["L"]; next } { print }' .env > "$tmp"
        cat "$tmp" > .env
        rm -f "$tmp"
    else
        printf '%s\n' "$line" >> .env
    fi
}

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
for var in APP_KEY APP_NAME APP_LOCALE APP_FALLBACK_LOCALE DEMO_MODE DEMO_TTL_HOURS DEMO_MAX_ACCOUNTS DEMO_EMAIL \
    DEMO_REMINDERS DEMO_REMINDER_MIN_GAP_HOURS DEMO_EXTENDED_TTL_DAYS DEMO_URL_PRO DEMO_IFRAME_TIMEOUT_MS DEMO_LEAD_URL \
    MCP_ENABLED MCP_DEMO_ENABLED MCP_DEMO_TOKEN MCP_DEMO_RATE_LIMIT MCP_RATE_LIMIT MCP_AUDIT_RETENTION \
    GITHUB_TOKEN GITHUB_REPO GTM_CONTAINER_ID GTM_SERVER_URL GTM_SCRIPT_PATH CONSENT_COOKIE_DOMAIN \
    OPENLMNP_WEBSITE_URL OPENLMNP_SIGNUP_URL \
    ALLOW_REGISTRATION INVITATION_TTL_DAYS PROVISION_TOKEN APP_URL TRUSTED_PROXIES TRUSTED_HOSTS UPDATE_SELF_APPLY UPDATE_SELF_APPLY_HINT UPDATE_DOCKER_IMAGE UPDATE_CHECK_ENABLED \
    CSP_ENABLED CSP_REPORT_ONLY CSP_EXTRA_SOURCES CSP_POLICY HSTS_MAX_AGE \
    LOG_CHANNEL LOG_LEVEL LOG_DAILY_DAYS \
    DB_JOURNAL_MODE DB_SYNCHRONOUS DB_BUSY_TIMEOUT DB_TRANSACTION_MODE \
    DOCUMENTS_VAULT_ENABLED DOCUMENTS_VAULT_KDF DOCUMENTS_VAULT_TTL \
    SESSION_SECURE_COOKIE SESSION_SAME_SITE SESSION_LIFETIME \
    FEEDBACK_ENABLED FEEDBACK_AUDIENCES FEEDBACK_MIN_SECONDS FEEDBACK_MIN_ACTIONS FEEDBACK_ACTIONS \
    FEEDBACK_VARIANTS FEEDBACK_MIN_SAMPLE \
    FEEDBACK_RETURN_DAYS FEEDBACK_COOLDOWN_DAYS FEEDBACK_FORWARD_EMAIL FEEDBACK_CONTACT_EMAIL \
    DVF_ENABLED DVF_BASE_URL DVF_GEO_API_URL DVF_TIMEOUT DVF_MIN_SAMPLE DVF_CACHE_DAYS DVF_RATE_LIMIT \
    ADDRESS_LOOKUP_ENABLED ADDRESS_LOOKUP_URL ADDRESS_LOOKUP_TIMEOUT ADDRESS_LOOKUP_MIN_LENGTH \
    ADDRESS_LOOKUP_DEBOUNCE_MS ADDRESS_LOOKUP_LIMIT ADDRESS_LOOKUP_RATE_LIMIT \
    SIRENE_ENABLED SIRENE_BASE_URL SIRENE_TIMEOUT SIRENE_CACHE_DAYS SIRENE_RATE_LIMIT \
    DPE_ENABLED DPE_BASE_URL DPE_GEOCODE_URL DPE_TIMEOUT DPE_CACHE_MINUTES DPE_RATE_LIMIT \
    RENT_MAP_ENABLED RENT_MAP_API_BASE_URL RENT_MAP_PAGE_BASE_URL RENT_MAP_TIMEOUT \
    FEEDBACK_URL_STAR FEEDBACK_URL_SPONSOR FEEDBACK_URL_DISCUSSIONS FEEDBACK_URL_ISSUES FEEDBACK_URL_PRO \
    TELEMETRY_ENABLED TELEMETRY_INSTALL_TYPE TELEMETRY_URL \
    LINKED_DOSSIERS_ENABLED LINKED_DOSSIERS_QUOTA LINKED_DOSSIER_INVITATION_TTL_DAYS \
    AI_ENABLED AI_PROVIDER AI_BASE_URL AI_API_KEY AI_ALLOW_USER_KEYS AI_OAUTH_ENABLED \
    AI_MODEL_DOCUMENTS AI_MODEL_DOCUMENTS_FALLBACK AI_FALLBACK_CONFIDENCE AI_REANALYSIS_THRESHOLD \
    AI_PDF_ENGINE AI_PDF_TEXT_FALLBACK AI_MODEL_CHAT \
    AI_DOCS_MONTHLY_QUOTA AI_CHAT_DAILY_QUOTA AI_DAILY_BUDGET_USD AI_QUOTA_APPLIES_TO_USER_KEYS \
    AI_CHAT_MAX_TURNS AI_CHAT_TOOL_CALLS_MAX AI_CHAT_REASONING AI_DOCS_REASONING \
    AI_TIMEOUT AI_DATA_COLLECTION AI_APP_REFERER AI_APP_TITLE AI_USAGE_RETENTION AI_MODEL_PRICES \
    MAIL_MAILER MAIL_SCHEME MAIL_HOST MAIL_PORT MAIL_USERNAME MAIL_PASSWORD MAIL_FROM_ADDRESS MAIL_FROM_NAME; do
    value="${!var-}"
    if [ -n "$value" ]; then
        # ⚠️ TOUJOURS entre guillemets. Écrite nue, une valeur contenant un ESPACE casse
        # le parsing de Dotenv — « Failed to parse dotenv file. Encountered unexpected
        # whitespace » — et l'application ne démarre plus DU TOUT. Ce n'est pas un cas
        # tordu : APP_NAME, MAIL_FROM_NAME et AI_APP_TITLE contiennent naturellement des
        # espaces (« OpenLMNP Support »). Constaté le 2026-09-21 sur un déploiement de
        # préproduction avec APP_NAME="OpenLMNP (préprod)" : conteneur en boucle de
        # redémarrage, aucun message côté déploiement.
        #
        # ⚠️ EXACTEMENT trois caractères à échapper, ni plus ni moins. phpdotenv
        # n'accepte entre guillemets doubles que \" \\ \$ et \f \n \r \t \v ; tout
        # autre antislash lève « an unexpected escape sequence » et l'instance ne démarre
        # pas davantage qu'avec le bug d'origine. Échapper l'accent grave — réflexe de
        # shell — casse donc le fichier : vérifié contre le vrai parseur, pas contre l'idée
        # qu'on s'en fait.
        escaped=$(printf '%s' "$value" | sed 's/[\\"$]/\\&/g')
        for_sed=$(printf '%s' "$escaped" | sed 's/[\\&|]/\\&/g')

        if grep -q "^${var}=" .env 2>/dev/null; then
            sed -i "s|^${var}=.*|${var}=\"${for_sed}\"|" .env
        else
            printf '%s="%s"\n' "$var" "$escaped" >> .env
        fi
    fi
done

# Même chose pour storage/ : un volume (nommé ou bind-mount) tout juste créé
# masque les répertoires de l'image. Sans ça, le premier boot plante avant
# même d'atteindre les migrations ("Please provide a valid cache path", faute
# de storage/framework/views).
mkdir -p storage/app/public storage/app/data storage/logs \
    storage/framework/sessions storage/framework/views storage/framework/cache \
    bootstrap/cache database

# APP_KEY par instance : l'image publiée n'embarque aucune clé (une clé commune
# à toutes les installations permettrait de déchiffrer sessions/cookies d'autrui).
# Priorité : -e APP_KEY (propagé ci-dessus) > clé persistée dans le volume
# storage/ > génération au premier démarrage, puis persistance.
# Étape faite en root : elle écrit .env, qui fait partie du code de l'image.
if ! grep -q '^APP_KEY=.\+' .env; then
    keyfile="storage/app/.app_key"
    # Un lien symbolique à cet endroit ferait lire, ou écrire, par root un fichier choisi
    # par qui contrôle le volume.
    [ -L "$keyfile" ] && rm -f "$keyfile"
    if [ -s "$keyfile" ]; then
        # Le fichier vit dans un volume que l'application peut écrire : on n'en recopie
        # dans .env qu'une clé de forme attendue, jamais du texte arbitraire.
        key=$(head -n 1 "$keyfile")
        if [[ "$key" =~ ^base64:[A-Za-z0-9+/=]+$ ]]; then
            env_set APP_KEY "$key"
            echo "[entrypoint] APP_KEY restaurée depuis ${keyfile}."
        else
            echo "[entrypoint] ${keyfile} illisible ou mal formé : APP_KEY non restaurée." >&2
            exit 1
        fi
    else
        /usr/local/bin/php artisan key:generate --force
        grep '^APP_KEY=' .env | cut -d= -f2- > "$keyfile"
        chmod 600 "$keyfile"
        echo "[entrypoint] APP_KEY générée et persistée dans ${keyfile}."
    fi
fi

# Lien public/storage : public/ fait partie du code de l'image (à root).
/usr/local/bin/php artisan storage:link 2>/dev/null || true

# Droits des volumes, AVANT toute commande lancée sous l'utilisateur de l'application.
# Les deux commandes ci-dessus, en root, ont pu y créer des fichiers (cache, journal).
fix_ownership

# Les volumes sont-ils vraiment inscriptibles pour lui ? Sinon : repli en root.
if [ -n "$DROP_PRIVILEGES" ] && ! su-exec "$APP_USER" sh -c \
    'test -w database && test -w storage && test -w storage/logs && test -w bootstrap/cache \
     && { [ ! -e database/database.sqlite ] || test -w database/database.sqlite; }'; then
    echo "[entrypoint] ⚠️ database/ ou storage/ ne peut pas être rendu inscriptible pour ${APP_USER}" \
         "(chown refusé ?) : l'application tourne en root, comme dans les versions précédentes." >&2
    DROP_PRIVILEGES=
    touch "$ROOT_MODE_MARKER" 2>/dev/null || true
fi

if [ -n "$DROP_PRIVILEGES" ]; then
    echo "[entrypoint] Serveur et planificateur lancés sous ${APP_USER} (UID $(id -u "$APP_USER"))."
fi

# Le volume monté sur database/ masque les fichiers de l'image : resynchroniser
# migrations/seeders/factories depuis la copie de référence (sinon les nouvelles
# migrations n'atteignent jamais la prod). Sous l'utilisateur de l'application : il
# écrit dans un dossier qui lui appartient.
if [ -d /database-dist/migrations ]; then
    as_app sh -c 'mkdir -p database/migrations database/seeders database/factories
        cp -f /database-dist/migrations/*.php database/migrations/ 2>/dev/null || true
        cp -f /database-dist/seeders/*.php database/seeders/ 2>/dev/null || true
        cp -f /database-dist/factories/*.php database/factories/ 2>/dev/null || true'
fi

# Nettoyage des caches
as_app /usr/local/bin/php artisan optimize:clear 2>/dev/null || true

# Créer la base SQLite si elle n'existe pas
if [ ! -f database/database.sqlite ]; then
    echo "[entrypoint] Base de données absente, création initiale..."
    as_app touch database/database.sqlite
    as_app chmod 664 database/database.sqlite
    as_app /usr/local/bin/php artisan migrate --force
    as_app /usr/local/bin/php artisan db:seed --force
    echo "[entrypoint] Base initialisée avec seed."
else
    echo "[entrypoint] Base existante détectée, migration incrémentale..."
    as_app /usr/local/bin/php artisan migrate --force
    echo "[entrypoint] Migrations appliquées."
fi

# Index documentaire de l'assistant. Obligatoirement ICI et pas seulement au build de
# l'image : le volume monté sur storage/ masque les fichiers de l'image, donc un index
# écrit au `docker build` serait invisible (même piège que database/ → /database-dist).
as_app /usr/local/bin/php artisan ai:build-help-index 2>/dev/null || echo "[entrypoint] ai:build-help-index ignoré"

# Token MCP démo public (idempotent ; no-op si MCP_DEMO_ENABLED=false)
as_app /usr/local/bin/php artisan openlmnp:mcp-demo-token 2>/dev/null || echo "[entrypoint] mcp-demo-token ignoré"

# Permissions (sous l'utilisateur de l'application : un chmod récursif lancé en root
# suivrait ce qu'il trouve dans des dossiers qu'il ne contrôle pas)
as_app chmod -R ug+rwX storage database bootstrap/cache 2>/dev/null || true

# Commande alternative : restaurer stdout puis exécuter (remplace serveur web + scheduler)
if [ "$#" -gt 0 ]; then
    exec 1>&3 3>&-
    if [ -n "$DROP_PRIVILEGES" ]; then
        exec su-exec "$APP_USER" "$@"
    fi
    exec "$@"
fi

echo "[entrypoint] Démarrage du scheduler en arrière-plan..."
# su-exec en tête de la commande mise en arrière-plan, pas la fonction as_app : appelée
# avec &, une fonction s'exécute dans un sous-shell qui resterait vivant, en root, à
# attendre la boucle.
SCHEDULER_LOOP='while true; do /usr/local/bin/php artisan schedule:run --quiet 2>/dev/null; sleep 60; done'
if [ -n "$DROP_PRIVILEGES" ]; then
    su-exec "$APP_USER" sh -c "$SCHEDULER_LOOP" &
else
    sh -c "$SCHEDULER_LOOP" &
fi

# Le serveur HTTP intégré de PHP est MONO-PROCESSUS par défaut : il sert une
# requête à la fois et met les autres en file. Mesuré le 2026-09-01 sur 10 requêtes
# parallèles, temps par requête : 0,049 → 0,324 s avec 1 worker (escalier régulier
# de ~31 ms, la signature d'une sérialisation) contre 0,071 → 0,170 s avec 4.
#
# Ce que ça change quand une requête est longue (flux SSE de l'assistant, gros PDF,
# déverrouillage du coffre) : mesuré avec une requête de 5 s en cours, les requêtes
# rapides concurrentes répondent en 4,6 s avec 1 worker contre 0,04 s avec 4.
#
# 4 et pas davantage : la machine de référence n'a que 2 cœurs et 1 Go, partagés avec d'autres
# conteneurs. Chaque worker est un processus PHP complet, mais l'essentiel de son
# empreinte est partagé avec le maître par copie sur écriture : mesuré en production,
# 44 Mo de RSS apparent par worker pour 4,5 Mo réellement privés. Une instance
# auto-hébergée plus grosse peut surcharger la valeur par `-e PHP_CLI_SERVER_WORKERS`.
#
# ⚠️ NE JAMAIS augmenter ce nombre sans les réglages SQLite de config/database.php
# (WAL + transaction_mode IMMEDIATE) : plusieurs workers sur les réglages par défaut,
# c'est « database is locked » sur une écriture concurrente sur quatre.
#
# ⚠️ DEUX pièges, tous deux silencieux :
#  1. CETTE variable-ci se règle ICI et pas dans l'allowlist ci-dessus, contrairement
#     aux `DB_*` : elle est lue par le binaire PHP au démarrage du serveur (donc
#     directement dans l'environnement du processus) et n'a rien à faire dans `.env`.
#  2. `--no-reload` est OBLIGATOIRE : sans lui, `artisan serve` surveille les
#     fichiers, refuse de forker, et se contente d'écrire « Unable to respect the
#     PHP_CLI_SERVER_WORKERS environment variable » dans un log que personne ne lit.
#     Le réglage semblerait actif sans l'être. Sans inconvénient ici : le code est
#     figé dans l'image (`opcache.validate_timestamps=0`), il ne change pas à chaud.
export PHP_CLI_SERVER_WORKERS="${PHP_CLI_SERVER_WORKERS:-4}"

echo "[entrypoint] Démarrage du serveur (${PHP_CLI_SERVER_WORKERS} workers)..."
# Toujours le serveur intégré de PHP (`artisan serve`), désormais sous l'utilisateur de
# l'application : le remplacer (FrankenPHP, nginx + php-fpm) est un autre chantier.
if [ -n "$DROP_PRIVILEGES" ]; then
    exec su-exec "$APP_USER" /usr/local/bin/php artisan serve --host=0.0.0.0 --port=8000 --no-reload
fi
exec /usr/local/bin/php artisan serve --host=0.0.0.0 --port=8000 --no-reload
