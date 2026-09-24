FROM php:8.3-cli

WORKDIR /app

RUN apt-get update \
    && apt-get install -y --no-install-recommends libzip-dev libxml2-dev unzip \
    && docker-php-ext-install curl mbstring xml zip \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

COPY composer.json /app/composer.json
RUN composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader

COPY . /app

RUN mkdir -p /app/queues /app/logs /app/locks

EXPOSE 10000

CMD ["sh", "-c", "php -S 0.0.0.0:${PORT:-10000} -t /app"]
