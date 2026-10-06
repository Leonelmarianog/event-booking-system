# syntax=docker/dockerfile:1

FROM php:8.5.11-fpm-alpine3.24 AS base

COPY --from=mlocati/php-extension-installer:2.12.0 /usr/bin/install-php-extensions /usr/local/bin/

RUN install-php-extensions pdo_pgsql redis opcache intl pcntl zip \
    && apk add --no-cache nginx supervisor

ARG UID=1000
ARG GID=1000

RUN addgroup -g "${GID}" app \
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

# The browser bundle (public/build) and the SSR bundle (bootstrap/ssr). The Vite
# plugin of Wayfinder runs php artisan, so this stage starts from the vendor stage.
FROM vendor AS assets

USER root
RUN apk add --no-cache nodejs npm
USER app

RUN npm ci && npm run build:ssr

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
