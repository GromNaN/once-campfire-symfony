# syntax=docker/dockerfile:1

FROM dunglas/frankenphp:1-php8.5-alpine AS base

# Extensions required by the application: SQLite for storage, intl for
# translations and formatting, gd for image variants, and the usual Symfony
# runtime helpers.
RUN install-php-extensions \
        pdo_sqlite \
        sqlite3 \
        intl \
        gd \
        zip \
        opcache \
        pcntl \
        sockets

# ffmpeg produces video poster frames and compressed variants, exactly like the
# original app does with the ffmpeg binary.
RUN apk add --no-cache ffmpeg

COPY --link --from=composer:2 /usr/bin/composer /usr/bin/composer

# APP_ENV is set before the build commands run, so that the assets and the cache
# are built for production and not for the default development environment.
ENV COMPOSER_ALLOW_SUPERUSER=1 \
    COMPOSER_HOME=/composer \
    APP_ENV=prod \
    APP_DEBUG=0 \
    SERVER_NAME=:80 \
    CADDY_GLOBAL_OPTIONS=""

WORKDIR /app

# Install dependencies first so the layer is cached across code changes.
COPY --link composer.json composer.lock symfony.lock ./
RUN composer install --no-dev --no-scripts --no-interaction --prefer-dist --no-progress \
    && composer clear-cache

COPY --link . .

# The image is ready to run. The autoloader, the vendor assets of the import map,
# the compiled assets and the production cache are built here, so the container
# has nothing to compile when it starts. Warming the cache boots the application,
# which is why it runs after the whole source tree is in place.
RUN mkdir -p storage/db storage/files storage/backups var \
    && composer dump-autoload --no-dev --classmap-authoritative \
    && php bin/console importmap:install --no-interaction \
    && php bin/console asset-map:compile --no-interaction \
    && php bin/console cache:warmup --no-interaction \
    && chown -R www-data:www-data storage var

COPY --link Caddyfile /etc/caddy/Caddyfile
COPY --link docker/entrypoint.sh /usr/local/bin/entrypoint
RUN chmod +x /usr/local/bin/entrypoint

EXPOSE 80 443

HEALTHCHECK --interval=30s --timeout=5s --start-period=30s --retries=3 \
    CMD curl -fsS http://localhost/up || exit 1

ENTRYPOINT ["/usr/local/bin/entrypoint"]
