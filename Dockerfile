FROM php:8.3-bookworm

WORKDIR /app

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        libonig-dev \
        libzip-dev \
        libpng-dev \
        libjpeg62-turbo-dev \
        libfreetype6-dev \
        libxml2-dev \
        unzip \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" mbstring xml gd zip \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

COPY composer.json /app/composer.json
RUN composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader

COPY . /app
RUN mkdir -p /app/data/queues

EXPOSE 10000
CMD ["sh", "-c", "php -S 0.0.0.0:${PORT:-10000} -t /app"]
