# Event Booking

A demo app for event bookings. Organizers publish events with a number of seats.
Attendees book seats and get emails. The app is a portfolio demo: it shows development
and DevOps practices in a realistic Laravel codebase.

## What the demo shows

| Practice                           | Where to look                                                                                                           |
| ---------------------------------- | ----------------------------------------------------------------------------------------------------------------------- |
| Rich domain model, no repositories | `app/Models/Event.php`, `app/Models/Booking.php`, `app/Models/User.php`                                                 |
| One Action class for each use case | `app/Actions/<Name>/`. Each directory has a `README.md` with sequence diagrams, except `Fortify/` from the starter kit. |
| Authorization with policies        | `app/Policies/`. A hidden event gives 404, so that other users cannot know that it exists.                              |
| Row locks against overbooking      | `app/Actions/ReserveSeats/`, `tests/Concurrency/`, `scripts/check-concurrency.sh`                                       |
| Queued emails after the commit     | `app/Notifications/`. A rollback sends no email.                                                                        |
| Scheduled reminders                | `app/Actions/SendEventReminders/`, `routes/console.php` (each hour)                                                     |
| Rate limits and CSRF               | `app/Providers/AppServiceProvider.php`, `tests/Feature/*RateLimitTest.php`, `tests/Feature/CsrfProtectionTest.php`      |
| Account deletion                   | `app/Actions/DeleteAccount/`. The user is anonymized, and past events stay.                                             |
| One production image, four roles   | `Dockerfile`, `docker/entrypoint.sh`                                                                                    |
| CI                                 | `.github/workflows/ci.yml`: lint, tests, image build and scan, concurrency check                                        |

The stack is Laravel 13, PHP 8.5, Inertia with React and TypeScript, PostgreSQL 18 and
Redis 8.

## Run the demo on your computer

You need Docker with Compose v2, `make` and `git`. The stack uses these host ports:

| Port               | Service                          |
| ------------------ | -------------------------------- |
| 8080               | The app                          |
| 5173               | Vite (hot reload)                |
| 8025               | Mailpit, the inbox for all email |
| 5432 (`127.0.0.1`) | PostgreSQL                       |
| 6379 (`127.0.0.1`) | Redis                            |

If one of these ports is in use, stop the program that uses it.

1. Clone the repository and go into its directory:

    ```bash
    git clone https://github.com/Leonelmarianog/event-booking-system.git
    cd event-booking-system
    ```

2. Run the first start:

    ```bash
    make setup
    ```

    This command builds the image, installs the packages and starts the stack. Then it
    creates the tables and seeds the demo data.

    The first run builds the image from nothing and can take several minutes. If you
    run `make setup` again, it keeps your data.

3. Open <http://localhost:8080>.
4. Open <http://localhost:8025> to see the emails that the app sends.

### Demo accounts

All accounts use the password `password`.

| Email                   | What you can do                                                                      |
| ----------------------- | ------------------------------------------------------------------------------------ |
| `organizer@example.com` | Manage events. "Jazz Night at the Harbour" has 175 attendees on four pages.          |
| `attendee@example.com`  | See bookings in all states: upcoming, past, cancelled, and cancelled with the event. |
| `admin@example.com`     | See all events, also drafts. Cancel any event and see its attendees.                 |

The seed data also has 3 generated organizers and 180 generated attendees.

If you register a new account, verify its email address before you use the account
settings. The verification email is in Mailpit.

### Things to try

- As `attendee@example.com`, book seats of an event. Then open Mailpit to see the
  confirmation email.
- Cancel the booking on "My bookings". The seats go back to the event.
- As `organizer@example.com`, create an event. It stays a draft until you publish it.
- Cancel one of your events. Each attendee gets an email.
- Open an event of another organizer. You can see it, but you cannot edit it.
- As `attendee@example.com`, try to delete the account under Settings. The app refuses,
  because the account holds bookings for upcoming events.

### Commands

| Command                 | What it does                                                                        |
| ----------------------- | ----------------------------------------------------------------------------------- |
| `make setup`            | The first start after a clone: install, start, migrate and seed                     |
| `make up`               | Start the stack and wait until it is ready                                          |
| `make down`             | Stop the stack. The data stays.                                                     |
| `make fresh`            | Erase all data, then create the tables and seed the demo data again                 |
| `make test`             | Run the test suite                                                                  |
| `make lint`             | Run the static checks of CI                                                         |
| `make concurrency-test` | Send parallel bookings for the last seat and make sure that there is no overbooking |
| `make help`             | Show all commands                                                                   |

All times of the demo data are relative to the day of the seed. After a few days, the
first events are in the past and `/events` has fewer events. To get the full demo data
again, run `make fresh`.

CAUTION: `make fresh` erases all data in your local database, also the data that you
added.

## Production image

The `runtime` target of the `Dockerfile` is the production image. The operator of the
deployment builds it and gives PostgreSQL, Redis and an SMTP server.

```bash
docker build --target runtime -t event-booking:runtime .
```

The first argument of the container selects its role:

| Role            | What it runs                                                    |
| --------------- | --------------------------------------------------------------- |
| `web` (default) | Nginx, PHP-FPM and the Inertia SSR server on port 8080          |
| `worker`        | The queue worker, which sends the emails                        |
| `scheduler`     | The scheduler, which sends the reminders each hour              |
| `migrate`       | `php artisan migrate --force`. Run it once before each rollout. |

### Environment variables

The image sets `APP_ENV=production`, `APP_DEBUG=false` and `LOG_CHANNEL=stderr`. Give
these variables to each role:

| Variable                                                          | Value                                        |
| ----------------------------------------------------------------- | -------------------------------------------- |
| `APP_KEY`                                                         | A key from `php artisan key:generate --show` |
| `APP_URL`                                                         | The public URL of the app                    |
| `DB_CONNECTION`                                                   | `pgsql`                                      |
| `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` | The PostgreSQL server                        |
| `REDIS_HOST`, `REDIS_PORT`, `REDIS_PASSWORD`                      | The Redis server                             |
| `SESSION_DRIVER`, `CACHE_STORE`, `QUEUE_CONNECTION`               | `redis`                                      |
| `MAIL_MAILER`                                                     | `smtp`                                       |
| `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD`        | The SMTP server                              |
| `MAIL_FROM_ADDRESS`                                               | The sender address of the emails             |
| `SESSION_SECURE_COOKIE`                                           | `true` when the app runs on HTTPS            |

`compose.concurrency.yaml` runs the image with a minimal set of these variables. CI
uses it.

## Design documents

- `docs/superpowers/specs/2026-09-28-event-booking-design.md`: the design spec of v1.
- `docs/design/`: the glossary, the business rules, the C4 diagrams, the ERD and the
  future features.
- `docs/superpowers/plans/milestones.md`: the order of the work for v1.
