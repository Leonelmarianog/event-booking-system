# Milestones — v1

This document gives the order of the work for v1. Each milestone has a goal, a scope
and the conditions that make it complete.

The spec is `docs/superpowers/specs/2026-09-28-event-booking-design.md`. The design is in
`docs/design/`. The future features in `docs/design/future-features.md` are not part
of v1.

## How the work is done

1. Each PR has a detailed plan in `docs/superpowers/plans/`. The plan gives the files,
   the tests and the steps. The reviewer approves the plan before the work starts.
2. The tests come first. Each test name gives the ID of the business rule that it
   covers, for example `BR-B4`.
3. Each Action PR has one Action, its `README.md` with the sequence diagrams, its
   tests, its route and its page.

## M1 — Foundation

**Goal:** A Laravel app that runs locally with Docker Compose.

**Scope:**

- The official Laravel React starter kit, installed without changes in its own PR.
- Our changes to the starter kit, in later PRs: PostgreSQL and Redis, removal of the
  unused migrations (`sessions`, `cache`, `cache_locks`, `jobs`, `job_batches`), and the
  columns `is_admin` and `anonymized_at` on `users`.
- The Docker image (`dev` target) and `compose.yaml` with `app`, `worker`, `scheduler`,
  `vite`, `postgres`, `redis` and `mailpit`.
- The quality tools: Pest, Pint, Larastan, ESLint and `tsc --noEmit`.
- A `Makefile` with `up`, `down`, `test`, `lint` and `fresh`.

**Complete when:**

- `make up` starts the stack, and a user can register and log in.
- `make test` and `make lint` pass.

## M2 — Continuous integration and production image

**Goal:** Each PR gets automatic checks, and `main` publishes a production image.

**Scope:**

- The `runtime` target of the Docker image, with the roles `web`, `worker`,
  `scheduler` and `migrate`.
- GitHub Actions: lint, test (with PostgreSQL and Redis), image build, Trivy scan,
  and the push to GHCR on `main`.

**Complete when:**

- A PR shows the results of lint, test, build and scan.
- A merge to `main` publishes an image to GHCR.
- The image runs in each of the four roles with only environment variables.

## M3 — Events

**Goal:** An organizer can manage events, and everyone can see published events.

**Scope:**

- The `Event` model, the `EventStatus` enum, the migration and the `EventPolicy`.
- Write Actions: `CreateEvent`, `UpdateEvent`, `PublishEvent`, `DeleteEvent`.
- Read Actions: `GetUpcomingEvents`, `GetEvent`, `GetOrganizerEvents`.
- The pages for these Actions.
- One place in `bootstrap/app.php` that turns domain exceptions into flash messages.

**Rules:** BR-E1 to BR-E11, BR-E14 to BR-E17, BR-A3, BR-A4.

**Complete when:** Each rule in the list has a test that passes.

## M4 — Bookings

**Goal:** A user can book seats, and two users can never get the same last seat.

**Scope:**

- The `Booking` model, the `BookingStatus` enum, the migration and the `BookingPolicy`.
- Write Actions: `ReserveSeats`, `CancelBooking`, `CancelEvent`.
- Read Actions: `GetBookings`, `GetEventAttendees`.
- The pages for these Actions.
- The concurrency check (`make concurrency-test`), locally and in CI.

**Rules:** BR-B1 to BR-B14, BR-E12, BR-E13, BR-A1, BR-A2.

**Complete when:**

- Each rule in the list has a test that passes.
- The concurrency check passes: 20 parallel requests for 1 seat give exactly 1
  confirmed booking.

## M5 — Notifications

**Goal:** Users get emails for the important changes, and only after the commit.

**Scope:**

- The notifications `BookingConfirmed`, `BookingCancelled`, `EventCancelled` and
  `EventReminder`.
- The Action `SendEventReminders` and its daily schedule.

**Rules:** BR-N1 to BR-N7.

**Complete when:**

- Each rule in the list has a test that passes.
- A test shows that no email goes out when the transaction fails.
- In local development, the emails arrive in Mailpit.

## M6 — User accounts

**Goal:** A user can delete their account without damage to events and bookings.

**Scope:**

- The Action `DeleteAccount`, connected to the "Delete account" page of the starter kit.

**Rules:** BR-U1 to BR-U5.

**Complete when:** Each rule in the list has a test that passes.

## M7 — Demo ready

**Goal:** Another person can run the demo and understand it.

**Scope:**

- The rate limits `bookings` and `event-writes`, with the flash message for Inertia
  requests.
- Tests for CSRF rejection and for each rate limit.
- The seed data: the demo users and about 10 events in different states. The seeder
  stops when `APP_ENV=production`.
- The `README.md`: what the demo shows, how to run it, and the environment variables.

**Complete when:**

- A new person can clone the repository, run `make up` and `make fresh`, and use the
  demo.
- All the success criteria in section 1 of the spec are true.
