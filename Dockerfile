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

RUN COMPOSER_ALLOW_SUPERUSER=1 composer install --optimize-autoloader --no-interaction

# Build des assets CSS/JS
RUN npm ci && npm run build

RUN mkdir -p database storage/app/public storage/app/data storage/logs storage/framework/{sessions,views,cache} \
    && chmod -R 775 storage database bootstrap/cache

RUN php artisan key:generate --force

# Volumes pour persister les données entre rebuilds
VOLUME ["/var/www/html/database", "/var/www/html/storage"]

COPY docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

EXPOSE 8000
ENTRYPOINT ["docker-entrypoint.sh"]
