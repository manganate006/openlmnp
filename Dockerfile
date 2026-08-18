FROM php:8.4-cli

RUN apt-get update && apt-get install -y \
    libicu-dev libzip-dev libpng-dev libjpeg-dev libfreetype6-dev \
    libsqlite3-dev unzip git sqlite3 rsync \
    curl ca-certificates \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install pdo_sqlite bcmath intl gd zip opcache \
    && rm -rf /var/lib/apt/lists/*

# Node.js (pour le build CSS Tailwind)
COPY --from=node:22-slim /usr/local/bin/node /usr/local/bin/node
COPY --from=node:22-slim /usr/local/lib/node_modules /usr/local/lib/node_modules
RUN ln -s /usr/local/lib/node_modules/npm/bin/npm-cli.js /usr/local/bin/npm

# Increase PHP memory for composer
RUN echo "memory_limit=512M" > /usr/local/etc/php/conf.d/memory.ini

COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html
COPY . .

# Use Docker-specific env
RUN cp .env.docker .env

# Build des dépendances dans l'image (DNS fonctionnel sur le LXC)
RUN COMPOSER_ALLOW_SUPERUSER=1 composer install --no-dev --optimize-autoloader --no-interaction
RUN npm install --no-audit --no-fund && npm run build && rm -rf node_modules

# Création des répertoires nécessaires à Laravel
RUN mkdir -p \
    database \
    storage/app/public \
    storage/app/data \
    storage/app \
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
ENTRYPOINT ["docker-entrypoint.sh"]
