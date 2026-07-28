FROM php:8.5-apache

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        git \
        libcurl4-openssl-dev \
        libonig-dev \
        libsqlite3-dev \
        unzip \
    && docker-php-ext-install curl mbstring pdo pdo_sqlite \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

COPY composer.json composer.lock ./
RUN composer install --no-dev --prefer-dist --no-interaction --no-progress --optimize-autoloader

COPY . .
COPY deploy/apache-formflow.conf /etc/apache2/sites-available/000-default.conf

RUN mkdir -p storage/uploads \
    && chown -R www-data:www-data storage

USER www-data
