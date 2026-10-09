# syntax=docker/dockerfile:1

FROM php:8.5.11-fpm-alpine3.24 AS base

COPY --from=mlocati/php-extension-installer:2.12.0 /usr/bin/install-php-extensions /usr/local/bin/

RUN install-php-extensions pdo_pgsql redis opcache intl pcntl zip \
    && apk add --no-cache nginx supervisor

ARG UID=1000
ARG GID=1000

# On macOS the GID is 20, which Alpine gives to the dialout group. The app group takes
# the GID of the host user, so an Alpine group with the same GID is removed first.
RUN existing_group="$(getent group "${GID}" | cut -d: -f1)" \
    && if [ -n "${existing_group}" ]; then delgroup "${existing_group}"; fi \
    && addgroup -g "${GID}" app \
    && adduser -D -u "${UID}" -G app app \
    && mkdir -p /run/nginx /var/lib/nginx/tmp /var/log/nginx \
    && chown -R app:app /run/nginx /var/lib/nginx /var/log/nginx

COPY docker/nginx/default.conf /etc/nginx/http.d/default.conf
COPY docker/supervisor/supervisord.conf /etc/supervisord.conf

WORKDIR /var/www/html

EXPOSE 8080

USER app

CMD ["supervisord", "-c", "/etc/supervisord.conf"]

# Composer packages without dev packages, and the optimized autoloader.
FROM base AS vendor

USER root
COPY --from=composer:2.9 /usr/bin/composer /usr/bin/composer
RUN chown app:app /var/www/html
USER app

COPY --chown=app:app composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction

COPY --chown=app:app . .
RUN composer dump-autoload --optimize --no-dev

# The browser bundle (public/build) and the SSR bundle (bootstrap/ssr). The npm
# packages are installed before the app is copied, so this layer stays in the cache
# until package-lock.json changes. The Vite plugin of Wayfinder runs php artisan, so
# the build needs the app and vendor from the vendor stage.
FROM base AS assets

USER root
RUN apk add --no-cache nodejs npm \
    && chown app:app /var/www/html
USER app

COPY --chown=app:app package.json package-lock.json .npmrc ./
RUN npm ci

COPY --from=vendor --chown=app:app /var/www/html ./
RUN npm run build:ssr

# Production image. The roles are web (default), worker, scheduler and migrate.
FROM base AS runtime

USER root

RUN apk add --no-cache nodejs \
    && cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

COPY docker/php/production.ini /usr/local/etc/php/conf.d/zz-production.ini
COPY docker/supervisor/runtime.conf /etc/supervisord.conf
COPY docker/entrypoint.sh docker/healthcheck.sh /usr/local/bin/

COPY --from=vendor --chown=app:app /var/www/html /var/www/html
COPY --from=assets --chown=app:app /var/www/html/public/build /var/www/html/public/build
COPY --from=assets --chown=app:app /var/www/html/bootstrap/ssr /var/www/html/bootstrap/ssr

ENV APP_ENV=production \
    APP_DEBUG=false \
    LOG_CHANNEL=stderr

USER app

HEALTHCHECK --interval=10s --timeout=5s --start-period=30s --retries=3 \
    CMD ["healthcheck.sh"]

ENTRYPOINT ["entrypoint.sh"]
CMD ["web"]

FROM base AS dev

USER root

RUN install-php-extensions xdebug \
    && apk add --no-cache libstdc++ libgcc

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY --from=node:24-alpine /usr/local/bin/node /usr/local/bin/node
COPY --from=node:24-alpine /usr/local/lib/node_modules /usr/local/lib/node_modules

RUN ln -s ../lib/node_modules/npm/bin/npm-cli.js /usr/local/bin/npm \
    && ln -s ../lib/node_modules/npm/bin/npx-cli.js /usr/local/bin/npx

COPY docker/php/xdebug.ini /usr/local/etc/php/conf.d/zz-xdebug.ini
COPY docker/php/dev.ini /usr/local/etc/php/conf.d/zz-dev.ini

# The node_modules volume copies the owner of this directory when Docker creates it.
RUN mkdir -p /var/www/html/node_modules && chown app:app /var/www/html/node_modules

USER app
