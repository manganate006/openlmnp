FROM php:8.4-cli

RUN apt-get update && apt-get install -y \
    libicu-dev libzip-dev libpng-dev libjpeg-dev libfreetype6-dev \
    libsqlite3-dev unzip git sqlite3 \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install pdo_sqlite bcmath intl gd zip opcache \
    && rm -rf /var/lib/apt/lists/*

# Increase PHP memory for composer
RUN echo "memory_limit=512M" > /usr/local/etc/php/conf.d/memory.ini

COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html
COPY . .

# Use Docker-specific env
RUN cp .env.docker .env

RUN COMPOSER_ALLOW_SUPERUSER=1 composer install --optimize-autoloader --no-interaction

RUN mkdir -p database storage/app/data storage/logs storage/framework/{sessions,views,cache} \
    && touch database/database.sqlite \
    && chmod -R 775 storage database bootstrap/cache

RUN php artisan key:generate --force
RUN php artisan storage:link 2>/dev/null || true
RUN php artisan migrate:fresh --seed --force

EXPOSE 8000
CMD php artisan optimize:clear 2>/dev/null; php artisan storage:link 2>/dev/null; php artisan serve --host=0.0.0.0 --port=8000
