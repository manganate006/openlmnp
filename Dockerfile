FROM php:8.4-cli-alpine@sha256:f80f7fae697397e1a864c3dfbcbdb5cb7ca107da25ebfadffab5022897c1202a

# Runtime + build dependencies
RUN apk add --no-cache \
        bash \
        nodejs \
        npm \
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

# PHP configuration
RUN echo "memory_limit=512M" > /usr/local/etc/php/conf.d/memory.ini \
    && cat > /usr/local/etc/php/conf.d/opcache.ini <<'EOF'
opcache.enable=1
opcache.enable_cli=1
opcache.validate_timestamps=0
opcache.memory_consumption=128
opcache.interned_strings_buffer=16
opcache.max_accelerated_files=20000
EOF

COPY --from=composer:2@sha256:9e446351d4008451e4975358203cbe00509bd6ab494fb2b7cab0113efe91505a /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

COPY . .

# Use Docker-specific env
RUN cp .env.docker .env

RUN COMPOSER_ALLOW_SUPERUSER=1 \
    COMPOSER_MEMORY_LIMIT=-1 \
    composer install \
        --no-dev \
        --optimize-autoloader \
        --no-interaction \
        --prefer-dist

RUN npm ci --no-audit --no-fund && npm run build && rm -rf node_modules

# Création des répertoires nécessaires à Laravel
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

# Copie de référence : le volume monté sur database/ masque le contenu de
# l'image ; l'entrypoint resynchronise migrations/seeders/factories depuis ici.
RUN cp -r database /database-dist

# Pas de key:generate ici : une APP_KEY figée au build serait partagée par toutes
# les installations qui pullent l'image — l'entrypoint la génère par instance.

COPY docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

EXPOSE 8000
ENTRYPOINT ["/usr/local/bin/docker-entrypoint.sh"]
