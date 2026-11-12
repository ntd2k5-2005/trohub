FROM node:22-bookworm-slim@sha256:5ec23b7c622b00547753bc6ffd78085dd868a68aeb41c8e0a1418f033e13d96d AS frontend
WORKDIR /build
COPY package.json package-lock.json ./
RUN npm ci --no-audit --no-fund
COPY resources ./resources
COPY app ./app
COPY config ./config
COPY public ./public
COPY vite.config.js ./
RUN npm run build

FROM php:8.3-apache-bookworm@sha256:a14446c32a3c8aeb9b4dac7fb73b03dfd8006e670d9b43ea7947e4030d3cbae6 AS runtime
RUN apt-get update && apt-get install -y --no-install-recommends \
    libicu-dev libonig-dev libzip-dev libsqlite3-dev libxml2-dev libssl-dev unzip git \
    && docker-php-ext-install -j2 pdo_mysql pdo_sqlite mbstring intl zip bcmath pcntl opcache \
    && pecl install mongodb-2.1.3 && docker-php-ext-enable mongodb \
    && a2enmod rewrite && rm -rf /var/lib/apt/lists/*
COPY --from=composer:2@sha256:ca11f643d7791ebdd90a34fc20caf11380d434007ae539a9ea75cd9212e13dca /usr/bin/composer /usr/local/bin/composer
WORKDIR /var/www/html
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction
COPY . .
COPY --from=frontend /build/public/build ./public/build
RUN composer dump-autoload --no-dev --optimize --no-scripts \
    && mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && php artisan package:discover --ansi \
    && chown -R www-data:www-data storage bootstrap/cache \
    && chmod +x docker/entrypoint.sh
COPY docker/apache.conf /etc/apache2/sites-available/000-default.conf
COPY docker/php.ini /usr/local/etc/php/conf.d/trohub.ini
EXPOSE 80
ENTRYPOINT ["/var/www/html/docker/entrypoint.sh"]
CMD ["apache2-foreground"]
