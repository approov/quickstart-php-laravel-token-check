FROM php:8.5.2-cli

COPY --from=composer:2.9 /usr/bin/composer /usr/bin/composer

WORKDIR /app

RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip libzip-dev \
    && docker-php-ext-install zip \
    && rm -rf /var/lib/apt/lists/*

COPY . .

RUN mkdir -p bootstrap/cache \
    storage/framework/cache \
    storage/framework/sessions \
    storage/framework/testing \
    storage/framework/views \
    storage/logs \
    && composer install --no-dev --optimize-autoloader --no-interaction --prefer-dist

CMD ["bash", "scripts/build.sh"]
