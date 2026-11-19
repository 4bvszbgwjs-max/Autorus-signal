FROM composer:2 AS composer
FROM dunglas/frankenphp:1-php8.4

RUN install-php-extensions pdo_pgsql pcntl sockets zip
COPY --from=composer /usr/bin/composer /usr/local/bin/composer

WORKDIR /app
ENV SESSION_DRIVER=file CACHE_STORE=file LOG_CHANNEL=stderr
COPY . .
RUN --mount=type=secret,id=composer_ca \
    if [ -r /run/secrets/composer_ca ]; then export COMPOSER_CAFILE=/run/secrets/composer_ca; fi && \
    composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader

EXPOSE 8000
ENTRYPOINT ["php", "artisan", "octane:start", "--server=frankenphp", "--host=0.0.0.0", "--port=8000", "--admin-port=2019"]
