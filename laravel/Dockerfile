FROM composer:2 AS composer
FROM dunglas/frankenphp:1-php8.4

RUN install-php-extensions curl pdo_pgsql pcntl sockets zip && \
    php -r 'foreach (["curl_exec", "curl_multi_exec", "socket_addrinfo_lookup", "socket_addrinfo_explain"] as $function) { if (!function_exists($function)) { fwrite(STDERR, "Missing PHP function: $function\n"); exit(1); } }'
COPY --from=composer /usr/bin/composer /usr/local/bin/composer

WORKDIR /app
ENV SESSION_DRIVER=file CACHE_STORE=file LOG_CHANNEL=stderr
COPY . .
RUN --mount=type=secret,id=composer_ca \
    if [ -r /run/secrets/composer_ca ]; then export COMPOSER_CAFILE=/run/secrets/composer_ca; fi && \
    composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader && \
    cp vendor/laravel/octane/src/Commands/stubs/frankenphp-worker.php public/frankenphp-worker.php && \
    groupadd --gid 10001 app && useradd --uid 10001 --gid app --create-home app && \
    chmod -R a+rX /app && \
    chown -R app:app storage bootstrap/cache /config/caddy /data/caddy

USER app

EXPOSE 8000
ENTRYPOINT ["php", "artisan", "octane:start", "--server=frankenphp", "--host=0.0.0.0", "--port=8000", "--admin-port=2019"]
