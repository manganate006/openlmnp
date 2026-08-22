ARG PHP_IMAGE=php:8.4-cli-alpine@sha256:f80f7fae697397e1a864c3dfbcbdb5cb7ca107da25ebfadffab5022897c1202a
ARG COMPOSER_IMAGE=composer:2@sha256:9e446351d4008451e4975358203cbe00509bd6ab494fb2b7cab0113efe91505a
ARG NODE_IMAGE=node:22-alpine@sha256:c610fcdfb1d5b4740dd70c284ed3cb16bb857e0f7166196e36a5501df7a3aa32

# ============================================================
# 1. PHP BUILD STAGE
# ============================================================
FROM ${PHP_IMAGE} AS PHP-BUILDER

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
FROM ${COMPOSER_IMAGE} AS COMPOSER_BINARY
FROM PHP-BUILDER AS COMPOSER-BUILDER

COPY --from=COMPOSER_BINARY \
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
FROM ${NODE_IMAGE} AS FRONTEND-BUILDER

WORKDIR /app

COPY package.json package-lock.json ./

RUN npm ci --no-audit --no-fund

COPY . .

RUN npm run build

# ============================================================
# 4. APPLICATION BUILD STAGE
# ============================================================
FROM COMPOSER-BUILDER AS app-builder

WORKDIR /var/www/html

COPY . .

RUN cp .env.docker .env

# Récupération des vendor déjà calculés dans le stage Composer.
# Le stage précédent contient déjà vendor/, donc COPY . . ne doit
# pas écraser celui-ci si vendor/ est présent dans le contexte.
COPY --from=COMPOSER-BUILDER /var/www/html/vendor ./vendor

# Package discovery Laravel + autoload optimisé.
RUN php artisan package:discover --ansi \
    && composer dump-autoload \
        --no-dev \
        --optimize \
        --classmap-authoritative

# Assets compilés.
COPY --from=FRONTEND-BUILDER /app/public/build ./public/build

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

RUN rm -rf /database-dist && cp -a database /database-dist

# ============================================================
# 5. RUNTIME STAGE
# ============================================================
FROM ${PHP_IMAGE} AS RUNTIME

RUN apk add --no-cache \
    bash \
    libzip \
    libpng \
    libjpeg-turbo \
    freetype \
    sqlite-libs \
    icu-libs

# Extensions PHP compilées.
COPY --from=PHP-BUILDER /usr/local/lib/php/extensions/ /usr/local/lib/php/extensions/

# Configuration générée par docker-php-ext-install
COPY --from=PHP-BUILDER /usr/local/etc/php/conf.d/ /usr/local/etc/php/conf.d/

# Configuration PHP applicative.
RUN echo "memory_limit=512M" > /usr/local/etc/php/conf.d/memory.ini \
    && cat > /usr/local/etc/php/conf.d/opcache.ini <<'EOF'
opcache.enable=1
opcache.enable_cli=1
opcache.validate_timestamps=0
opcache.memory_consumption=128
opcache.interned_strings_buffer=16
opcache.max_accelerated_files=20000
EOF

WORKDIR /var/www/html

# Application complète déjà préparée.
COPY --from=app-builder /var/www/html /var/www/html

# Copie de référence database/ utilisée par l'entrypoint.
COPY --from=app-builder /database-dist /database-dist

COPY docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

EXPOSE 8000
ENTRYPOINT ["/usr/local/bin/docker-entrypoint.sh"]
