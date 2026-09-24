FROM php:8.3-cli

WORKDIR /app

# Official php:8.3-cli already contains the curl, mbstring and XML
# extensions used by the project. We only build the extensions that
# PhpSpreadsheet requires but are not enabled in the base image.
RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        libzip-dev \
        libpng-dev \
        libjpeg62-turbo-dev \
        libfreetype6-dev \
        unzip \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" gd zip \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

COPY composer.json /app/composer.json

RUN composer install \
    --no-dev \
    --no-interaction \
    --prefer-dist \
    --optimize-autoloader

COPY . /app

RUN mkdir -p /app/queues /app/logs /app/locks

EXPOSE 10000

CMD ["sh", "-c", "php -S 0.0.0.0:${PORT:-10000} -t /app /app/router.php"]
