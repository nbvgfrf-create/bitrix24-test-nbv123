FROM php:8.3-cli

RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip libzip-dev libxml2-dev \
    && docker-php-ext-install zip xml \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

RUN composer require phpoffice/phpspreadsheet --no-interaction --prefer-dist

EXPOSE 8080
