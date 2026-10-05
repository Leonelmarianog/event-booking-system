# M1 — Local Stack with Docker Compose and PostgreSQL

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Run the app locally with Docker Compose, on PostgreSQL, with the tests on
PostgreSQL locally and in CI.

**Architecture:** One Dockerfile with a `base` stage (PHP-FPM, Nginx, supervisord) and
a `dev` target (Xdebug, Composer, Node). Compose runs `app`, `vite`, `postgres` and
`mailpit`. The source code is a bind mount. `node_modules` is a named volume. Commands
run inside the containers.

**Tech Stack:** Docker 27, Compose 2.32, `php:8.5-fpm-alpine`, Node 24,
PostgreSQL 18, Mailpit 1.31, Nginx, supervisord.

**Spec:** `docs/superpowers/specs/2026-09-28-event-booking-design.md` (sections 2, 9
and 10).

## Global Constraints

- The containers run as a user with the UID and GID of the host user (build arguments
  `UID` and `GID`, default `1000`). Files that the containers create belong to the host
  user.
- Nginx listens on port `8080` (the app runs as a non-root user).
- Ports on the host: app `8080`, Vite `5173`, Mailpit web page `8025`, PostgreSQL `5432`.
- Xdebug is installed in `dev` and is off by default (`XDEBUG_MODE=off`).
- Sessions, cache and queue stay on the database driver. Redis comes in the next PR.
- The tests run on PostgreSQL, in the database `event_booking_testing`.
- `composer ci:check` runs inside the `app` container and must pass.

## Review Focus

1. **A fresh clone.** The steps in "Verification on a fresh clone" (Task 2, Step 7)
   must work with no files from an earlier install.
2. **File owner.** Files that the containers create (`storage/logs`, `public/hot`,
   `vendor/`) belong to the host user, not to root.
3. **Hot reload.** The browser loads the assets from `http://localhost:5173`, and a
   change in a `.tsx` file shows without a page reload.
4. **Tests on PostgreSQL.** A test fails if the test database is not PostgreSQL. CI uses
   a PostgreSQL service container.
5. **Emails.** An email that the app sends shows in Mailpit.

---

### Task 1: Docker image (`base` and `dev`)

**Files:**

- Create: `Dockerfile`
- Create: `.dockerignore`
- Create: `docker/nginx/default.conf`
- Create: `docker/supervisor/supervisord.conf`
- Create: `docker/php/xdebug.ini`
- Create: `docker/php/dev.ini`

**Interfaces:**

- Produces: the image target `dev`, used by the `app` and `vite` services in Task 2.
  M2 adds the production stages after `base`.

- [ ] **Step 1: Write `Dockerfile`**

```dockerfile
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
COPY docker/php/dev.ini /usr/local/etc/php/conf.d/zz-dev.ini

# The node_modules volume copies the owner of this directory when Docker creates it.
RUN mkdir -p /var/www/html/node_modules && chown app:app /var/www/html/node_modules

USER app
```

- [ ] **Step 2: Write `docker/nginx/default.conf`**

```nginx
server {
    listen 8080 default_server;
    root /var/www/html/public;
    index index.php;

    charset utf-8;
    client_max_body_size 10m;

    access_log /dev/stdout;
    error_log /dev/stderr warn;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location = /favicon.ico { access_log off; log_not_found off; }
    location = /robots.txt  { access_log off; log_not_found off; }

    location ~ ^/index\.php(/|$) {
        fastcgi_pass 127.0.0.1:9000;
        fastcgi_split_path_info ^(.+\.php)(/.*)$;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        fastcgi_param DOCUMENT_ROOT $realpath_root;
        fastcgi_hide_header X-Powered-By;
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }
}
```

- [ ] **Step 3: Write `docker/supervisor/supervisord.conf`**

```ini
[supervisord]
nodaemon=true
logfile=/dev/null
logfile_maxbytes=0
pidfile=/tmp/supervisord.pid

[program:php-fpm]
command=php-fpm --nodaemonize
autorestart=true
stdout_logfile=/dev/stdout
stdout_logfile_maxbytes=0
redirect_stderr=true

[program:nginx]
command=nginx -g "daemon off;"
autorestart=true
stdout_logfile=/dev/stdout
stdout_logfile_maxbytes=0
redirect_stderr=true
```

- [ ] **Step 4: Write `docker/php/xdebug.ini`**

```ini
; The XDEBUG_MODE environment variable overrides xdebug.mode.
xdebug.mode=off
xdebug.start_with_request=yes
xdebug.client_host=host.docker.internal
xdebug.client_port=9003
```

- [ ] **Step 4b: Write `docker/php/dev.ini`**

```ini
; Development settings. PHPStan needs more than the default 128M.
memory_limit=1G
```

- [ ] **Step 5: Write `.dockerignore`**

```gitignore
.git
.env
node_modules
vendor
public/build
public/hot
storage/logs/*
storage/framework/cache/*
storage/framework/sessions/*
storage/framework/views/*
bootstrap/cache/*.php
```

- [ ] **Step 6: Build the image and check its contents**

```bash
docker build --target dev --build-arg UID=$(id -u) --build-arg GID=$(id -g) -t event-booking:dev .
docker run --rm event-booking:dev php -v
docker run --rm event-booking:dev php -m
docker run --rm event-booking:dev node -v
docker run --rm event-booking:dev npm -v
docker run --rm event-booking:dev id
```

Expected: PHP 8.5. The modules list has `pdo_pgsql`, `redis`, `Zend OPcache`, `intl`,
`pcntl`, `zip` and `xdebug`. Node 24. `id` shows the UID and GID of the host user.

- [ ] **Step 7: Commit**

```bash
git add Dockerfile .dockerignore docker/
git commit -m "build: add dev Docker image"
```

### Task 2: Compose stack on PostgreSQL

**Files:**

- Create: `compose.yaml`
- Create: `docker/postgres/init/01-create-testing-database.sql`
- Modify: `.env.example` (`APP_URL`, `DB_*`, `MAIL_*`)
- Modify: `vite.config.ts` (`server`)

**Interfaces:**

- Consumes: the image target `dev` from Task 1.
- Produces: the services `app`, `vite`, `postgres` and `mailpit`. Task 3 runs the tests
  with `docker compose exec app`.

- [ ] **Step 1: Write `compose.yaml`**

```yaml
name: event-booking

x-app: &app
    build:
        context: .
        target: dev
        args:
            UID: ${UID:-1000}
            GID: ${GID:-1000}
    image: event-booking:dev
    volumes:
        - .:/var/www/html
        - node_modules:/var/www/html/node_modules
    extra_hosts:
        - host.docker.internal:host-gateway

services:
    app:
        <<: *app
        ports:
            - 8080:8080
        environment:
            XDEBUG_MODE: ${XDEBUG_MODE:-off}
        depends_on:
            postgres:
                condition: service_healthy
            mailpit:
                condition: service_healthy
        healthcheck:
            test: [CMD, wget, -q, -O, /dev/null, http://127.0.0.1:8080/up]
            interval: 5s
            timeout: 3s
            retries: 20

    vite:
        <<: *app
        command: sh -c "[ -x node_modules/.bin/vp ] || npm ci; npm run dev"
        ports:
            - 5173:5173
        depends_on:
            app:
                condition: service_healthy

    postgres:
        image: postgres:18-alpine
        environment:
            POSTGRES_DB: event_booking
            POSTGRES_USER: event_booking
            POSTGRES_PASSWORD: secret
        ports:
            - 5432:5432
        volumes:
            - postgres:/var/lib/postgresql
            - ./docker/postgres/init:/docker-entrypoint-initdb.d:ro
        healthcheck:
            test: [CMD, pg_isready, -U, event_booking, -d, event_booking]
            interval: 5s
            timeout: 3s
            retries: 20

    mailpit:
        image: axllent/mailpit:v1.31.4
        ports:
            - 8025:8025
        healthcheck:
            test: [CMD, /mailpit, readyz]
            interval: 5s
            timeout: 3s
            retries: 20

volumes:
    node_modules:
    postgres:
```

- [ ] **Step 2: Write `docker/postgres/init/01-create-testing-database.sql`**

```sql
-- Runs only when the postgres volume is empty.
CREATE DATABASE event_booking_testing OWNER event_booking;
```

- [ ] **Step 3: Update `.env.example`**

Replace these lines:

```dotenv
APP_URL=http://localhost:8080

DB_CONNECTION=pgsql
DB_HOST=postgres
DB_PORT=5432
DB_DATABASE=event_booking
DB_USERNAME=event_booking
DB_PASSWORD=secret

MAIL_MAILER=smtp
MAIL_SCHEME=null
MAIL_HOST=mailpit
MAIL_PORT=1025
```

- [ ] **Step 4: Update `vite.config.ts`**

Add these keys to `server`, next to `watch`:

```ts
host: '0.0.0.0',
port: 5173,
strictPort: true,
hmr: {
    host: 'localhost',
},
```

The dev server listens on all interfaces inside the container. The browser connects to
`localhost:5173`.

- [ ] **Step 5: Start the stack and check each service**

```bash
cp .env.example .env
docker compose build
docker compose run --rm app composer install
docker compose run --rm app php artisan key:generate
docker compose up -d
docker compose ps
docker compose exec app php artisan migrate
curl -s -o /dev/null -w "%{http_code}\n" http://localhost:8080/login
curl -s -o /dev/null -w "%{http_code}\n" http://localhost:8080/register
curl -s -o /dev/null -w "%{http_code}\n" http://localhost:5173/@vite/client
cat public/hot
curl -s -o /dev/null -w "%{http_code}\n" http://localhost:8025/
docker compose exec app php artisan tinker --execute "Mail::raw('Test', fn (\$m) => \$m->to('test@example.com')->subject('Stack check'));"
curl -s http://localhost:8025/api/v1/messages | grep -o '"Subject":"Stack check"'
ls -ln storage/logs public/hot
```

Expected: `docker compose ps` shows all four services as healthy or running. Each URL
gives `200`. `public/hot` contains `http://localhost:5173`. Mailpit has the message
"Stack check". The files belong to the UID of the host user.

- [ ] **Step 6: Commit**

```bash
git add compose.yaml docker/postgres .env.example vite.config.ts
git commit -m "build: add Docker Compose stack on PostgreSQL"
```

- [ ] **Step 7: Verification on a fresh clone**

```bash
git clone . "$SCRATCHPAD/fresh-clone" && cd "$SCRATCHPAD/fresh-clone"
cp .env.example .env
docker compose -p event-booking-fresh build
docker compose -p event-booking-fresh run --rm --no-deps app composer install
docker compose -p event-booking-fresh run --rm --no-deps app php artisan key:generate
```

Expected: each command ends without errors. `--no-deps` keeps the second stack from
starting its own `postgres` and `mailpit`, because their ports are the same as the
first stack.

### Task 3: Tests on PostgreSQL

**Files:**

- Create: `tests/Feature/DatabaseConnectionTest.php`
- Modify: `phpunit.xml` (`DB_CONNECTION`, `DB_DATABASE`)
- Modify: `.github/workflows/tests.yml` (PostgreSQL service and environment)

**Interfaces:**

- Consumes: the `postgres` service and the database `event_booking_testing` from Task 2.

- [ ] **Step 1: Write the failing test**

```php
<?php

use Illuminate\Support\Facades\DB;

test('the test suite runs on PostgreSQL', function () {
    expect(DB::connection()->getDriverName())->toBe('pgsql')
        ->and(DB::connection()->getDatabaseName())->toBe('event_booking_testing');
});
```

- [ ] **Step 2: Run the test and make sure that it fails**

```bash
docker compose exec app php artisan test --filter='runs on PostgreSQL'
```

Expected: FAIL. The driver is `sqlite`.

- [ ] **Step 3: Update `phpunit.xml`**

```xml
<env name="DB_CONNECTION" value="pgsql"/>
<env name="DB_DATABASE" value="event_booking_testing"/>
```

- [ ] **Step 4: Run all tests**

```bash
docker compose exec app php artisan test
```

Expected: all tests pass (40 from the starter kit and the new test).

- [ ] **Step 5: Add PostgreSQL to the CI workflow**

In `.github/workflows/tests.yml`, add to the `ci` job, before `steps`:

```yaml
services:
    postgres:
        image: postgres:18-alpine
        env:
            POSTGRES_DB: event_booking_testing
            POSTGRES_USER: event_booking
            POSTGRES_PASSWORD: secret
        ports:
            - 5432:5432
        options: >-
            --health-cmd "pg_isready -U event_booking -d event_booking_testing"
            --health-interval 5s
            --health-timeout 3s
            --health-retries 20

env:
    DB_CONNECTION: pgsql
    DB_HOST: 127.0.0.1
    DB_PORT: 5432
    DB_DATABASE: event_booking_testing
    DB_USERNAME: event_booking
    DB_PASSWORD: secret
```

The job variables have priority over `.env`, so `composer setup` and the tests use this
database.

- [ ] **Step 6: Run the full CI check in the container**

```bash
docker compose exec app composer ci:check
```

Expected: all checks pass.

- [ ] **Step 7: Commit**

```bash
git add tests/Feature/DatabaseConnectionTest.php phpunit.xml .github/workflows/tests.yml
git commit -m "test: run the test suite on PostgreSQL"
```

### Task 4: Handoff

**Files:**

- Modify: `HANDOFF.md`
- Create: `docs/superpowers/plans/2026-10-05-m1-local-stack.md` (this plan)

- [ ] **Step 1: Update `HANDOFF.md`**

"Where we are": the local stack runs with Docker Compose on PostgreSQL. Commands run in
the `app` container. "Next steps": Redis for sessions, cache and queue, the removal of
the unused migrations, and the `worker` and `scheduler` services.

- [ ] **Step 2: Format, check and commit**

```bash
docker compose exec app npx vp fmt HANDOFF.md docs/superpowers/plans
docker compose exec app composer ci:check
git add HANDOFF.md docs/superpowers/plans
git commit -m "docs: add local stack plan and update handoff"
```
