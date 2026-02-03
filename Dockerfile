FROM php:8.5.2-cli

COPY --from=composer:2.9 /usr/bin/composer /usr/bin/composer

WORKDIR /app

COPY . .

RUN composer install --no-dev --optimize-autoloader --no-interaction --prefer-dist

CMD ["bash", "scripts/build.sh"]

