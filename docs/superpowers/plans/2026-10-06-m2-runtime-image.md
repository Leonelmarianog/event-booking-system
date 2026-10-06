# M2 — Production Runtime Image with SSR

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a production `runtime` target to the Docker image. The image runs in four
roles (`web`, `worker`, `scheduler`, `migrate`) with only environment variables. The
`web` role renders the pages on the server (SSR).

**Architecture:** The `Dockerfile` gets three new stages after `base`: `vendor`
(Composer packages without dev packages), `assets` (the browser and SSR bundles) and
`runtime`. An entrypoint script selects the role from the first argument. In the `web`
role, supervisord runs Nginx, PHP-FPM and the Inertia SSR server. A health check script
checks the role that runs.

**Tech Stack:** Docker 27, `php:8.5.11-fpm-alpine3.24`, Alpine `nodejs` (Node 24),
Composer 2.9, Inertia 3 SSR, supervisord.

**Spec:** `docs/superpowers/specs/2026-09-28-event-booking-design.md` (sections 10
and 11).

## Global Constraints

- The image is not published to a registry. CI builds and scans it (a later PR).
- The image contains no dev packages and no dev tools: no Composer, no Xdebug, no
  `node_modules`, no tests.
- All roles run `php artisan optimize` at start. The config is cached at start, not at
  build time, because the environment variables are known only at runtime.
- The `web` role runs the SSR server on `127.0.0.1:13714` (the Inertia default). The
  SSR bundle includes all npm packages (`ssr.noExternal`), so the image needs Node but
  no `node_modules`.
- The health check of the `web` role checks `/up` and the SSR server. The other roles
  pass the health check.
- The base image and the extension installer have exact versions.
- The image runs as the non-root user `app` and listens on port 8080.
- Logs go to stderr (`LOG_CHANNEL=stderr`).
- SSR in development comes from the Vite dev server. The `app` container reaches it at
  `http://vite:5173` (`INERTIA_SSR_HOT_URL`).
- The build context is an allowlist (`.dockerignore`). Local files of a developer
  machine never go into the image.
- `composer ci:check` runs inside the `app` container and must pass.

## Review Focus

1. **Four roles.** Each role starts with only environment variables (no `.env` file).
2. **SSR.** The HTML of the first page load contains the rendered page.
3. **Health.** The `web` container becomes unhealthy when the SSR server stops.
4. **Content of the image.** No dev packages, no secrets, no `.env`.

---

### Task 1: SSR in the local stack

Before this PR, the local stack did not render pages on the server. Laravel sends the
render request to the address in `public/hot` (`http://localhost:5173`). Inside the
`app` container, `localhost` is the `app` container, so the request fails, and Inertia
falls back to client-side rendering without an error.

**Files:**

- Modify: `config/inertia.php`
- Modify: `compose.yaml`
- Modify: `vite.config.ts`
- Modify: `phpunit.xml`

- [ ] **Step 1: Read `INERTIA_SSR_ENABLED` and `INERTIA_SSR_HOT_URL` in `config/inertia.php`**

```php
'enabled' => (bool) env('INERTIA_SSR_ENABLED', true),
'hot_url' => env('INERTIA_SSR_HOT_URL'),
```

Set `INERTIA_SSR_ENABLED` to `false` in `phpunit.xml`. Otherwise the tests in the `app`
container send render requests to Vite.

The package config has this key, but the `ssr` array of the app config replaces the
array of the package config.

- [ ] **Step 2: Set the address in `compose.yaml` (service `app`)**

```yaml
INERTIA_SSR_HOT_URL: http://vite:5173
```

- [ ] **Step 3: Allow the host name `vite` in `vite.config.ts` (`server`)**

```ts
allowedHosts: ['vite'],
```

- [ ] **Step 4: Verify**

Run: `docker compose up -d --wait`, then `curl -s http://localhost:8080/login`.
Expected: the HTML contains `data-server-rendered="true"` and "Log in to your account".

- [ ] **Step 5: Commit**

```bash
git add config/inertia.php compose.yaml vite.config.ts phpunit.xml
git commit -m "fix: render pages on the server in the local stack"
```

### Task 2: Bundle the npm packages into the SSR build

**Files:**

- Modify: `vite.config.ts`

- [ ] **Step 1: Add a plugin that sets `ssr.noExternal` for builds only**

```ts
{
    // The production SSR server runs without node_modules, so the SSR
    // build must include all packages. Development keeps the default.
    name: 'ssr-bundle-packages',
    apply: 'build',
    config: () => ({ ssr: { noExternal: true } }),
},
```

With `noExternal` in development, the Vite dev server cannot load some packages
("module is not defined"). For this reason, the setting applies to builds only.

- [ ] **Step 2: Verify**

Expected: SSR in development still works (Task 1, Step 4). The SSR bundle of the image
imports only Node modules (Task 3, Step 8).

- [ ] **Step 3: Commit**

```bash
git add vite.config.ts
git commit -m "build: bundle npm packages into the SSR build"
```

### Task 3: Runtime image

**Files:**

- Modify: `Dockerfile`
- Modify: `.dockerignore`
- Create: `docker/entrypoint.sh`
- Create: `docker/healthcheck.sh`
- Create: `docker/supervisor/runtime.conf`
- Create: `docker/php/production.ini`

- [ ] **Step 1: Pin the versions in the `base` stage**

```dockerfile
FROM php:8.5.11-fpm-alpine3.24 AS base

COPY --from=mlocati/php-extension-installer:2.12.0 /usr/bin/install-php-extensions /usr/local/bin/
```

- [ ] **Step 2: Add the `vendor`, `assets` and `runtime` stages after `base`**

```dockerfile
FROM base AS vendor

USER root
COPY --from=composer:2.9 /usr/bin/composer /usr/bin/composer
RUN chown app:app /var/www/html
USER app

COPY --chown=app:app composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction

COPY --chown=app:app . .
RUN composer dump-autoload --optimize --no-dev \
    && php artisan package:discover

FROM vendor AS assets

USER root
RUN apk add --no-cache nodejs npm
USER app

RUN npm ci && npm run build:ssr

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
```

The `dev` stage stays as it is, after `base`. Compose builds `--target dev`, so the
new stages do not change the local stack.

- [ ] **Step 3: Write `docker/entrypoint.sh`**

```sh
#!/bin/sh
set -e

role="${1:-web}"

case "$role" in
    web | worker | scheduler | migrate)
        echo "$role" > /tmp/role
        php artisan optimize
        ;;
esac

case "$role" in
    web) exec supervisord -c /etc/supervisord.conf ;;
    worker) exec php artisan queue:work redis --tries=3 --max-time=3600 ;;
    scheduler) exec php artisan schedule:work ;;
    migrate) exec php artisan migrate --force ;;
    *) exec "$@" ;;
esac
```

Any other argument runs as a command, for example
`docker run <image> php artisan about`.

- [ ] **Step 4: Write `docker/healthcheck.sh`**

```sh
#!/bin/sh
set -e

if [ "$(cat /tmp/role 2>/dev/null)" = "web" ]; then
    wget -q -O /dev/null http://127.0.0.1:8080/up
    php artisan inertia:check-ssr > /dev/null
fi
```

- [ ] **Step 5: Write `docker/supervisor/runtime.conf`**

The same as `docker/supervisor/supervisord.conf`, plus a control socket (so that
`supervisorctl` can stop and start a program) and the SSR server:

```ini
[unix_http_server]
file=/tmp/supervisor.sock

[supervisorctl]
serverurl=unix:///tmp/supervisor.sock

[rpcinterface:supervisor]
supervisor.rpcinterface_factory=supervisor.rpcinterface:make_main_rpcinterface

[program:ssr]
command=php artisan inertia:start-ssr
directory=/var/www/html
autorestart=true
stdout_logfile=/dev/stdout
stdout_logfile_maxbytes=0
redirect_stderr=true
```

- [ ] **Step 6: Write `docker/php/production.ini`**

```ini
; Production settings. The code does not change while the container runs.
expose_php=Off
opcache.enable=1
opcache.validate_timestamps=0
opcache.memory_consumption=128
opcache.max_accelerated_files=20000
```

- [ ] **Step 7: Change `.dockerignore` to an allowlist**

```text
# Allowlist: the build context contains only the files that the image needs.
*
!app
!artisan
!bootstrap
!config
!database
!docker
!public
!resources
!routes
!storage
!.npmrc
!composer.json
!composer.lock
!package.json
!package-lock.json
!tsconfig.json
!vite.config.ts

# Local build output and runtime files inside the allowed directories.
bootstrap/cache/*.php
bootstrap/ssr
public/build
public/hot
storage/logs/*
storage/framework/cache/*
storage/framework/sessions/*
storage/framework/views/*
```

- [ ] **Step 8: Build**

Run: `docker build --target runtime -t event-booking:runtime .`
Expected: the build passes. `docker image ls event-booking:runtime` shows the size.
`docker run --rm event-booking:runtime sh -c 'ls vendor/bin; ls tests; which composer'`
shows no dev tools, no tests and no Composer. `docker run --rm event-booking:runtime ls -A`
shows only the files of the allowlist and `vendor`.

- [ ] **Step 9: Commit**

```bash
git add Dockerfile .dockerignore docker/
git commit -m "build: add production runtime image with SSR"
```

### Task 4: Manual verification of the four roles

Use the PostgreSQL, Redis and Mailpit of the local stack (`make up`), on the Compose
network `event-booking_default`. Write the variables to a file outside the repository:

```dotenv
APP_KEY=<the key from .env>
APP_URL=http://localhost:8081
DB_CONNECTION=pgsql
DB_HOST=postgres
DB_DATABASE=event_booking
DB_USERNAME=event_booking
DB_PASSWORD=secret
REDIS_HOST=redis
SESSION_DRIVER=redis
CACHE_STORE=redis
QUEUE_CONNECTION=redis
MAIL_MAILER=smtp
MAIL_HOST=mailpit
MAIL_PORT=1025
```

`RUN` below is `docker run --rm --network event-booking_default --env-file <file>`.

- [ ] **Step 1: `migrate`**

Run: `RUN event-booking:runtime migrate`
Expected: "Nothing to migrate" (or the migrations), then exit code 0.

- [ ] **Step 2: `web` and SSR**

Run: `RUN -d --name eb-web -p 8081:8080 event-booking:runtime`
Expected: after about 30 seconds, `docker inspect eb-web` shows `healthy`.
`curl -s http://localhost:8081/login` contains `data-server-rendered`. A user can
register and log in at `http://localhost:8081`. In production, the password rules are
strict: at least 12 characters, mixed case, a number, a symbol, and not in a known data
leak (`uncompromised()` needs internet access).

- [ ] **Step 3: SSR failure makes the container unhealthy**

Run: `docker exec eb-web supervisorctl -c /etc/supervisord.conf stop ssr`
Expected: the state becomes `unhealthy` after the retries, and the page still loads
(Inertia falls back to client-side rendering). Then start `ssr` again.

- [ ] **Step 4: `worker`**

Run: `RUN -d --name eb-worker event-booking:runtime worker`. Queue an email from
`eb-web` with `php artisan tinker`.
Expected: the `eb-worker` log shows the job as `DONE`, and Mailpit shows the email.

- [ ] **Step 5: `scheduler`**

Run: `RUN -d --name eb-scheduler event-booking:runtime scheduler`
Expected: the log shows "No scheduled commands are ready to run".

- [ ] **Step 6: Clean up**

Remove the containers `eb-web`, `eb-worker` and `eb-scheduler`.

### Task 5: Documents

**Files:**

- Modify: `docs/superpowers/specs/2026-09-28-event-booking-design.md`
- Modify: `docs/superpowers/plans/milestones.md`
- Modify: `docs/design/c4-context.md`
- Modify: `HANDOFF.md`

- [ ] **Step 1: Remove the image registry**

- Spec, section 1: CI lints, tests, builds and scans the image. The operator provides
  PostgreSQL, Redis, SMTP and hosting, and builds the image from the `Dockerfile`.
- Spec, section 11: `build` builds the image with the GitHub Actions cache. It does not
  push the image. The operator builds the image.
- `milestones.md` (M2): remove the push to GHCR from the scope and from "Complete
  when".
- `c4-context.md`: "GitHub and GitHub Actions build, test and scan the system."

- [ ] **Step 2: Update section 10 of the spec**

The stages `vendor`, `assets` (`npm run build:ssr`), `runtime` and `dev` (`dev` is
`base` plus dev tools). The `web` role also runs the SSR server. All roles run
`php artisan optimize`. The health check script.

- [ ] **Step 3: Update `HANDOFF.md`**

The decisions: no image registry, SSR in production in the `web` role. The CI row in
the decisions table. The state of M1 (complete) and the next steps of M2.

- [ ] **Step 4: Format and commit**

```bash
npm run check:fix
git add docs/ HANDOFF.md
git commit -m "docs: remove image registry and add runtime image plan"
```
