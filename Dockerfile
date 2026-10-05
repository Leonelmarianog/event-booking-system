# syntax=docker/dockerfile:1

FROM php:8.5-fpm-alpine AS base

COPY --from=mlocati/php-extension-installer:latest /usr/bin/install-php-extensions /usr/local/bin/

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

# The node_modules volume copies the owner of this directory when Docker creates it.
RUN mkdir -p /var/www/html/node_modules && chown app:app /var/www/html/node_modules

USER app
