# Images de base épinglées par digest d'INDEX (manifest list) et non par digest de
# plateforme : l'image officielle est publiée en amd64 + arm64 (docker-publish.yml), un
# digest mono-plateforme ferait échouer le leg arm64 avec « no match for platform ».
ARG PHP_IMAGE=php:8.4-cli-alpine@sha256:26e3f1de7f6aa3e8ea15584d803c5e088c57df89ff02a3ecf2dc855a4282d8d7
ARG COMPOSER_IMAGE=composer:2@sha256:4d71c3c2109c61d5415544264b59ad4087e4c5b7244481723664138fd36d5040
ARG NODE_IMAGE=node:22-alpine@sha256:c610fcdfb1d5b4740dd70c284ed3cb16bb857e0f7166196e36a5501df7a3aa32

# ============================================================
# 1. PHP BUILD STAGE
# ============================================================
FROM ${PHP_IMAGE} AS php-builder

RUN apk add --no-cache \
        libzip-dev \
        libpng-dev \
        libjpeg-turbo-dev \
        freetype-dev \
        sqlite-dev \
        icu-dev \
    && docker-php-ext-configure gd \
        --with-freetype \
        --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" \
        pdo_sqlite \
        bcmath \
        intl \
        gd \
        zip \
        opcache

# ============================================================
# 2. COMPOSER DEPENDENCIES STAGE
# ============================================================
FROM ${COMPOSER_IMAGE} AS composer-binary
FROM php-builder AS composer-builder

COPY --from=composer-binary \
    /usr/bin/composer \
    /usr/bin/composer

WORKDIR /var/www/html

COPY composer.json composer.lock ./

RUN COMPOSER_ALLOW_SUPERUSER=1 \
    COMPOSER_MEMORY_LIMIT=-1 \
    composer install \
        --no-dev \
        --no-scripts \
        --no-interaction \
        --prefer-dist \
        --optimize-autoloader

# ============================================================
# 3. FRONTEND BUILD STAGE
# ============================================================
FROM ${NODE_IMAGE} AS frontend-builder

WORKDIR /app

COPY package.json package-lock.json ./

RUN npm ci --no-audit --no-fund

COPY . .

# resources/css/app.css scanne `vendor/laravel/framework/…/Pagination/*.blade.php` via
# @source : sans vendor/, Tailwind n'y trouve rien et retire silencieusement les classes
# de pagination du CSS compilé (aucune erreur levée).
COPY --from=composer-builder /var/www/html/vendor ./vendor

RUN npm run build

# ============================================================
# 4. APPLICATION BUILD STAGE
# ============================================================
FROM composer-builder AS app-builder

WORKDIR /var/www/html

# vendor/ est déjà en place (hérité du stage composer) et absent du contexte de build
# (.dockerignore) : ce COPY ne peut donc pas l'écraser.
COPY . .

RUN cp .env.docker .env

# Autoload optimisé. ⚠️ Ne JAMAIS ajouter --no-scripts ici : le hook post-autoload-dump
# de composer.json exécute `package:discover` ET `filament:upgrade`, seul producteur de
# public/css/filament/** et public/js/filament/** (non committés). Sans lui, panel nu.
RUN composer dump-autoload \
        --no-dev \
        --optimize \
        --classmap-authoritative

# Assets compilés.
COPY --from=frontend-builder /app/public/build ./public/build

# Répertoires nécessaires à Laravel.
RUN mkdir -p \
    database \
    storage/app/public \
    storage/app/data \
    storage/logs \
    storage/framework/sessions \
    storage/framework/views \
    storage/framework/cache \
    bootstrap/cache \
    && chmod -R 775 storage database bootstrap/cache

# Copie de référence : le volume monté sur database/ masque le contenu de l'image ;
# l'entrypoint resynchronise migrations/seeders/factories depuis ici.
RUN rm -rf /database-dist && cp -a database /database-dist

# ============================================================
# 5. RUNTIME STAGE
# ============================================================
FROM ${PHP_IMAGE} AS runtime

RUN apk add --no-cache \
    bash \
    libzip \
    libpng \
    libjpeg-turbo \
    freetype \
    sqlite-libs \
    icu-libs

# Extensions PHP compilées.
COPY --from=php-builder /usr/local/lib/php/extensions/ /usr/local/lib/php/extensions/

# Configuration générée par docker-php-ext-install
COPY --from=php-builder /usr/local/etc/php/conf.d/ /usr/local/etc/php/conf.d/

# Configuration PHP applicative (préfixe zz- : chargée après les docker-php-ext-*.ini).
COPY docker/php.ini /usr/local/etc/php/conf.d/zz-openlmnp.ini

WORKDIR /var/www/html

# Application complète déjà préparée.
COPY --from=app-builder /var/www/html /var/www/html

# Copie de référence database/ utilisée par l'entrypoint.
COPY --from=app-builder /database-dist /database-dist

# Pas de key:generate ici : une APP_KEY figée au build serait partagée par toutes
# les installations qui pullent l'image — l'entrypoint la génère par instance.

COPY docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

EXPOSE 8000
ENTRYPOINT ["/usr/local/bin/docker-entrypoint.sh"]
