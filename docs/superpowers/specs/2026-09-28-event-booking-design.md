# Event Booking Demo — Design Spec

Date: 2026-09-28
Status: Approved as first version (2026-10-05)
Revised: 2026-10-05. Actions for reads and writes, one directory per Action (sections 3 and 5).

## 1. Purpose

A portfolio demo. The app itself is secondary. Its job is to show development and
DevOps practices in a realistic Laravel codebase:

- Authentication, authorization, CSRF, rate limiting, queues and database transactions,
  each used for a real reason.
- A rich domain model in idiomatic Laravel (no repositories).
- One production Docker image, Docker Compose for local development, and CI that lints,
  tests, builds and scans the image.

The operator of the deployment provides all external services (PostgreSQL, Redis, SMTP,
hosting). The deliverable is the `Dockerfile` of an image that runs against those
services through environment variables. The image is not published to a registry. The
operator builds it.

### Success criteria

1. `docker compose up` gives a working local stack with seeded demo data and hot reload.
2. Parallel booking requests for the last seat never overbook. An automated check
   proves this against the real stack.
3. CI is green: formatting, static analysis, type-check, tests, image build and image scan.
4. The same image runs as `web`, `worker`, `scheduler` or `migrate`, configured only
   through environment variables.

## 2. Stack

| Concern                                 | Choice                                                             |
| --------------------------------------- | ------------------------------------------------------------------ |
| Backend                                 | Latest stable Laravel, PHP 8.5                                     |
| Frontend                                | Inertia + React + TypeScript (official Laravel React starter kit)  |
| Auth                                    | Starter kit session auth (register, login, logout, password reset) |
| Database                                | PostgreSQL                                                         |
| Queue / cache / sessions / rate limiter | Redis                                                              |
| Local mail                              | Mailpit                                                            |
| Runtime                                 | Nginx + PHP-FPM in one container                                   |
| Tests                                   | Pest                                                               |
| Quality                                 | Pint, Larastan, ESLint, `tsc --noEmit`                             |

## 3. Code structure

Plain Laravel layout. Everything lives under `app/`. There are no repositories and no
feature folders.

Each use case is one Action, for reads and for writes. Each Action has its own
directory directly under `app/Actions/`. The directory holds the Action class and a
`README.md` with the sequence diagrams of the Action (one diagram for each outcome).
The README is written in the same PR as the Action.

```
app/
  Actions/
    CreateEvent/{CreateEvent.php,README.md}
    ReserveSeats/{ReserveSeats.php,README.md}
    GetBookings/{GetBookings.php,README.md}
    ...                    (one directory for each Action, see section 5)
  Enums/{EventStatus,BookingStatus}.php
  Exceptions/Domain/{DomainException,EventNotBookable,NotEnoughSeats,
                     AlreadyBooked,InvalidStateTransition,...}.php
  Http/
    Controllers/{EventController,EventPublicationController,
                 EventCancellationController,BookingController,
                 OrganizerEventController}.php
    Requests/{StoreEventRequest,UpdateEventRequest,StoreBookingRequest}.php
  Models/{User,Event,Booking}.php
  Notifications/{BookingConfirmed,BookingCancelled,EventCancelled,EventReminder}.php
  Policies/{EventPolicy,BookingPolicy}.php
  Console/Commands/SendEventReminders.php
```

### Layer responsibilities

| Layer                                | Owns                                                                                                                                                                           | Must not                                     |
| ------------------------------------ | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ | -------------------------------------------- |
| Model (`Event`, `Booking`) and enums | Business rules and state changes. Throws domain exceptions. Query scopes for reusable reads.                                                                                   | Know about HTTP, auth or transactions        |
| Action                               | One use case. Writes: transaction, row locks, calling model methods, dispatching notifications after commit. Reads: the query, through model scopes, and the data for the page | Contain business `if`s                       |
| Controller                           | HTTP: calls one Action, returns an Inertia response or redirect                                                                                                                | Contain business logic or queries            |
| FormRequest                          | Input validation, and authorization through the Policy                                                                                                                         | Contain business rules                       |
| Policy                               | Who may do what                                                                                                                                                                | Check domain state that belongs in the model |

Reads and writes always go through an Action. A read Action uses model scopes such as
`Event::published()->upcoming()`.

## 4. Domain model

### User

The starter kit fields plus `is_admin` (boolean, default false). Any user can organize
events and book other users' events.

### Event

| Field                           | Notes                                                                  |
| ------------------------------- | ---------------------------------------------------------------------- |
| `id`                            | bigint                                                                 |
| `organizer_id`                  | FK users                                                               |
| `title`, `description`, `venue` | strings/text                                                           |
| `starts_at`                     | timestamptz                                                            |
| `capacity`                      | int, > 0                                                               |
| `seats_available`               | int, DB `CHECK (seats_available >= 0 AND seats_available <= capacity)` |
| `status`                        | `EventStatus`: `draft`, `published`, `cancelled`                       |
| `published_at`, `cancelled_at`  | nullable timestamps                                                    |

Domain methods:

- `publish()`: only from `draft`, and only if `starts_at` is in the future.
- `cancel()`: from `draft` or `published` → `cancelled`.
- `reserve(User $attendee, int $quantity): Booking`: the event must be `published`
  and not started. The attendee must not be the organizer and must not already hold a
  confirmed booking for the event. Enough seats must remain. Decrements
  `seats_available` and creates a confirmed booking.
- `releaseSeats(int $quantity)`: increments `seats_available`, never above `capacity`.
- `changeCapacity(int $capacity)`: the new capacity may not be lower than the seats
  already booked. Adjusts `seats_available` by the difference.
- `isBookable(): bool`, used by the UI.

### Booking

| Field                 | Notes                                     |
| --------------------- | ----------------------------------------- |
| `id`                  | bigint                                    |
| `reference`           | ULID, unique, shown to the user           |
| `event_id`, `user_id` | FKs                                       |
| `quantity`            | int, 1–4                                  |
| `status`              | `BookingStatus`: `confirmed`, `cancelled` |
| `cancelled_at`        | nullable                                  |

A partial unique index on `(event_id, user_id) WHERE status = 'confirmed'` backs up the
"one active booking per user per event" rule at the database level.

Domain method:

- `cancel()`: only from `confirmed`, and only before the event starts. Releases seats
  on the event.

`EventStatus` and `BookingStatus` are PHP enums with a `canTransitionTo()` method. The
model methods call it before changing state and throw `InvalidStateTransition` on failure.

## 5. Use cases

| Use case                          | Action                                                           | Transaction and locking                                                    | Side effects (queued, after commit)                                  |
| --------------------------------- | ---------------------------------------------------------------- | -------------------------------------------------------------------------- | -------------------------------------------------------------------- |
| Create event (as draft)           | `CreateEvent`                                                    | Single insert                                                              | None                                                                 |
| Update event                      | `UpdateEvent`                                                    | Transaction; `lockForUpdate` on the event row, needed for capacity changes | None                                                                 |
| Publish event                     | `PublishEvent`                                                   | Single update                                                              | None                                                                 |
| Cancel event                      | `CancelEvent`                                                    | Transaction; lock the event row, cancel every confirmed booking            | `EventCancelled` to each attendee                                    |
| Reserve seats                     | `ReserveSeats`                                                   | Transaction; lock the event row, `Event::reserve()`                        | `BookingConfirmed` to the attendee                                   |
| Cancel booking                    | `CancelBooking`                                                  | Transaction; lock the event row, then the booking row, `Booking::cancel()` | `BookingCancelled` to the attendee                                   |
| Delete draft event                | `DeleteEvent`                                                    | Single delete                                                              | None                                                                 |
| Delete account                    | `DeleteAccount`                                                  | Transaction; delete drafts, anonymize the user row                         | None                                                                 |
| Send reminders (daily, scheduler) | `SendEventReminders`, called by the `SendEventReminders` command | Transaction per event; set `reminder_sent_at`                              | `EventReminder` to attendees of events starting in the next 24 hours |

Read use cases. Each one is an Action that the controller calls:

| Use case                         | Action               |
| -------------------------------- | -------------------- |
| Browse upcoming published events | `GetUpcomingEvents`  |
| Event detail                     | `GetEvent`           |
| My bookings                      | `GetBookings`        |
| My events (organizer view)       | `GetOrganizerEvents` |
| Attendee list of one event       | `GetEventAttendees`  |

## 6. Security features

- **Authentication:** starter kit session auth. Email verification is off, to keep
  the demo frictionless.
- **CSRF:** Laravel's `VerifyCsrfToken` middleware plus the `XSRF-TOKEN` cookie that
  Inertia's HTTP client sends back. A test asserts that a POST without the token is rejected.
- **Authorization:**
    - `EventPolicy`: `view` (published, or the viewer is the organizer or an admin;
      attendees can also see a cancelled event), `update` (organizer, and the event is
      not cancelled and has not started), `publish` (organizer; the model checks the
      state), `cancel` (organizer or admin), `delete` (organizer, and the event is a
      draft), `viewAttendees` (organizer or admin).
    - `BookingPolicy`: `view` (booking owner or event organizer), `cancel` (booking owner).
    - Admins pass `cancel` and `viewAttendees` on any event, which covers moderation.
- **Rate limiting:** named limiters in `AppServiceProvider`, stored in Redis:
    - `login`: the starter kit default (5 per minute per email + IP).
    - `bookings`: 10 per minute per user, on reserve and cancel.
    - `event-writes`: 20 per minute per user, on create, update, publish, delete and
      cancel.
      When the limit is exceeded, Inertia requests are redirected back with a flash error,
      so the user sees a toast instead of an error page. All other requests get a plain 429.
- **Production hardening:** `TrustProxies` configured through the environment,
  secure and HTTP-only session cookies, `APP_DEBUG=false`.

## 7. Error handling

- All domain exceptions extend `App\Exceptions\Domain\DomainException`.
- `bootstrap/app.php` renders them in one place:
    - Inertia or web request: redirect back with a flash error. The form field error is
      set where one applies, for example `quantity` for `NotEnoughSeats`.
    - JSON request: 422 with `{ message }`.
- Authorization failures → 403 page. Missing models → 404 page. An event that the
  viewer cannot see → 404 page, so that hidden events stay unknown. Rate limit → see
  section 6.
- Queued notifications retry 3 times with backoff. Failed jobs go to the
  `failed_jobs` table.

## 8. Frontend

Inertia pages, using the starter kit layout and shadcn/ui components:

- `events/index`: upcoming published events, paginated.
- `events/show`: details, remaining seats, booking form with quantity 1–4. Organizer
  controls (edit, publish, cancel) are shown based on policy results passed as props.
- `events/create`, `events/edit`: event form.
- `bookings/index`: my bookings, with a cancel button.
- `organizer/events/index`: my events with status and seats booked.
- `organizer/events/attendees`: attendee list for one event.
- Flash messages appear as toasts.

## 9. Testing

- **Unit (Pest):** domain rules on `Event`, `Booking` and the enums. Every rule in
  section 4 gets a passing and a failing case.
- **Feature (Pest), against PostgreSQL:** every route. Covers authorization (403s),
  validation, rate limiting (429 for plain requests, redirect with flash error for
  Inertia requests), CSRF rejection, notifications
  queued after commit (`Notification::fake()`), and no notification when the
  transaction rolls back.
- **Concurrency check:** a script runs against the running Compose stack. It seeds an
  event with 1 seat and 20 users, fires 20 booking requests in parallel, and asserts
  exactly 1 confirmed booking and `seats_available = 0`. It runs locally
  (`make concurrency-test`) and in CI.
- **Frontend:** `tsc --noEmit` and ESLint. There are no JS unit tests (out of scope).

## 10. Docker

### Image (`Dockerfile`, multi-stage)

1. `base`: `php:8.5-fpm-alpine` (exact version) + Nginx + supervisord, required PHP
   extensions (`pdo_pgsql`, `redis`, `opcache`, `intl`, `pcntl`, `zip`), the non-root
   user `app`, port 8080.
2. `vendor`: `composer install --no-dev` and an optimized autoloader.
3. `assets`: Node + PHP, `npm ci && npm run build:ssr`. PHP is present because the
   starter kit's Vite plugin generates route types through `php artisan`. The output is
   the browser bundle (`public/build`) and the SSR bundle (`bootstrap/ssr`). The SSR
   bundle includes all npm packages, so the runtime needs no `node_modules`.
4. `runtime` (production): `base` + Node (Alpine package) + production PHP settings.
   It copies the app, vendor and both bundles, and has a `HEALTHCHECK` script.
5. `dev`: `base` plus Composer, Node, npm and Xdebug (off by default). Used by Compose
   with a bind mount.

The build context is an allowlist (`.dockerignore`).

### Roles (entrypoint argument)

| Command         | Runs                                                        |
| --------------- | ----------------------------------------------------------- |
| `web` (default) | supervisord: Nginx, PHP-FPM and the Inertia SSR server      |
| `worker`        | `php artisan queue:work redis --tries=3 --max-time=3600`    |
| `scheduler`     | `php artisan schedule:work`                                 |
| `migrate`       | `php artisan migrate --force` (one-off task before rollout) |

Each role runs `php artisan optimize` first. Config is cached at container start, not at
build time, because environment variables are only known at runtime. Any other argument
runs as a command. Logs go to stderr (`LOG_CHANNEL=stderr`).

The SSR server listens on `127.0.0.1:13714` inside the `web` container. The health
check of the `web` role checks `/up` and the SSR server (`php artisan inertia:check-ssr`).
If the SSR server stops, the pages still load (client-side rendering), but the container
is unhealthy. The health check of the other roles always passes.

### Local development (`compose.yaml`)

Services: `app` (dev target, bind mount, port 8080), `worker`, `scheduler`, `vite`
(HMR on 5173), `postgres`, `redis`, `mailpit` (UI on 8025). Health checks gate
startup with `depends_on: condition: service_healthy`. A `Makefile` wraps the common
commands: `up`, `down`, `test`, `lint`, `fresh` (migrate + seed), `concurrency-test`.

### Seed data

Demo users (`organizer@example.com`, `attendee@example.com`, `admin@example.com`, all
with password `password`) and about 10 events in mixed states. The seeder refuses to run
when `APP_ENV=production`.

## 11. CI (GitHub Actions)

The workflow (`.github/workflows/ci.yml`) runs on each pull request and on each push to
`main`. A new push to a pull request cancels the run of the older commit.

1. `lint`: Pint `--test`, Larastan, `vp check` (ESLint, formatting and Markdown),
   `tsc --noEmit`. Each check is a separate step.
2. `test`: Pest with a PostgreSQL service container. The coverage report (PCOV) goes to
   the job summary, with no minimum percentage. The tests use the `array` and `sync`
   drivers, so there is no Redis service. A Redis service comes with the first test
   that needs Redis.
3. `image`: builds the `runtime` image with Docker Buildx and the GitHub Actions cache.
   The image is not pushed to a registry. `scripts/check-image-roles.sh` starts each
   role against PostgreSQL and Redis service containers, with only environment
   variables. Then Trivy scans the image. The full report goes to the job summary. The
   job fails on HIGH and CRITICAL vulnerabilities that have a fix. Trivy cannot see the
   npm packages inside the SSR bundle.
4. `concurrency`: boots the stack with Compose using the built image, runs the
   concurrency check.

Deployment itself is out of scope. The operator builds the image and runs the four roles
on their infrastructure. The README documents the required environment variables.

## 12. Out of scope

Payments and prices, PDF tickets, public API and tokens, email verification,
Kubernetes/Terraform manifests, JS unit tests, multi-tenancy, event images/uploads.
