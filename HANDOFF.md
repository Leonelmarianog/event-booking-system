# Handoff — Event Booking Demo

Last updated: 2026-10-07

## Where we are

Milestones M1 (Foundation) and M2 (CI and production image) are complete. Milestone M3
(Events) has started.

M3 is split into eight PRs, in this order:

1. `events` table, `Event` model, `EventStatus` enum, `EventPolicy` and `users.is_admin`.
   No routes and no pages.
2. `GetEvent`: the `events/show` page.
3. `CreateEvent`: the `events/create` page.
4. `GetOrganizerEvents`: the "My events" page.
5. `UpdateEvent`: the `events/edit` page, `Event::changeCapacity()`, and the handler in
   `bootstrap/app.php` that turns domain exceptions into flash messages (toasts).
6. `PublishEvent`: `Event::publish()` and `EventStatus::canTransitionTo()`.
7. `DeleteEvent`.
8. `GetUpcomingEvents`: the public `events/index` page.

PRs 1 and 2 are merged. The current PR (`feat/create-event`) is PR 3. Its plan is
`docs/superpowers/plans/2026-10-07-m3-create-event.md`. It waits for the review of the
owner.

- The create form sends the start time in UTC. The browser converts the local time of
  the organizer before it sends the form.
- Field limits: title and venue max 255 characters, description max 5000, capacity
  from 1 to 10,000.
- The event routes for logged-in users have the `auth` middleware only, not
  `verified`, because email verification is off in v1.
- The rate limiter `event-writes` (20 each minute for each user) is in
  `AppServiceProvider`. An Inertia request over the limit goes back with an error
  toast. Other requests get a plain 429. The routes of `UpdateEvent`, `PublishEvent`
  and `CancelEvent` must use it too (`throttle:event-writes`).
- `StoreEventRequest::eventAttributes()` gives the validated values with their types to
  the Action, because PHPStan does not accept the `array<string, mixed>` of
  `validated()` for an array shape.
- The app uses `CarbonImmutable` for dates (`Date::use()` in `AppServiceProvider`). The
  PHPDoc of `Event` uses `CarbonImmutable`. The PHPDoc of `User` still uses
  `Illuminate\Support\Carbon`.
- The sidebar shows "Create event" to logged-in users only.
- A person who cannot see an event gets a 404 page, not a 403 page
  (`Response::denyAsNotFound()` in `EventPolicy::view`).
- Visitors see "Log in" and "Register" links in the sidebar footer.
- The development database has test data: the user `organizer@example.com` (password
  `password`), a published event (ID 1) and a draft event (ID 2).
- The `events` table has only the columns that M3 uses. `cancelled_at` comes in M4,
  `reminder_sent_at` comes with the reminders.
- A model method that changes state comes in the PR of the Action that uses it.
- BR-E16 is not complete: attendees of a cancelled event can see it only after M4 adds
  bookings. The policy tests cover the organizer, admins, other users and visitors.
- BR-E11 is tested only on the policy in M3. `CancelEvent` comes in M4.

Build times of the `image` job (measured on PR #19): 308 s with an empty cache, 64 s for
a re-run with the same code, 129 s and 141 s after a change to one comment. The commit
`7942f5a` installs the npm packages before the app is copied, so the cache upload is
12 s instead of 125 s. But `composer install`, `npm ci` and the copy of the entrypoint
scripts run again on each run, also when their inputs do not change. The cause is not
known. The owner and Claude agreed to stop the investigation. A later PR can try an
other cache method (for example `actions/cache` with a local BuildKit cache).

- The Laravel React starter kit is installed without changes: Laravel 13, Inertia 3,
  React 19, Fortify, Wayfinder, Pest, Pint, Larastan, Laravel Boost. The `README.md` is the one of the starter kit.
- Boost files are local to each machine (`AGENTS.md`, `boost.json`, `.mcp.json`,
  `.claude/`, `.agents/`, `.codex/`). Run `php artisan boost:install --no-interaction`
  after a fresh clone. Use the Boost guidelines and skills to write Laravel code.
- CI: `.github/workflows/ci.yml` runs on each PR and on each push to `main`. The jobs
  `lint` (Pint, PHPStan, `vp check`, `tsc`, `npm audit`), `test` (Pest on PostgreSQL,
  coverage in the job summary) and `image` (build, role check, Trivy) run in parallel. `main` has
  no branch protection rule.
- `scripts/check-image-roles.sh <image> <env-file>` checks the four roles of the
  runtime image. Locally, set `DOCKER_NETWORK=event-booking_default`.
- Trivy cannot see the npm packages inside the SSR bundle. The `lint` job runs
  `npm audit --omit=dev --audit-level=high` for them.
- `package.json` has an npm override for `tinypool` (`^2.1.2`), because `oxfmt` in
  `vite-plus` 0.3.0 pins the vulnerable version 2.1.0. Remove the override when you
  upgrade `vite-plus` to 1.0 or later: that version uses a fixed `tinypool`.
- The local stack runs with Docker Compose: `app` (port 8080), `vite` (5173),
  `worker` (`queue:listen`), `scheduler` (`schedule:work`), `postgres` 18 (5432),
  `redis` 8 (6379) and `mailpit` (web page on 8025). The app and the tests use
  PostgreSQL. CI uses a PostgreSQL service container.
- Sessions, cache, rate limits and the queue use Redis. Failed jobs and job batches use
  PostgreSQL. The tests use the `array` and `sync` drivers, so CI needs no Redis.
- Use the `Makefile` for the usual tasks: `make setup` (first start after a clone),
  `make up`, `make down`, `make test`, `make lint` and `make fresh`. Run `make` to see
  the list. The `Makefile` passes the UID and GID of the host user to Compose.
- Run other commands inside the `app` container, for example
  `docker compose exec app composer ci:check`. On the host, the tests cannot reach the
  host name `postgres`.
- The `vite` service runs `npm ci` again when `package-lock.json` changes.
- SSR works in the local stack: the `app` container sends the render requests to
  `http://vite:5173` (`INERTIA_SSR_HOT_URL`).
- Build the production image with
  `docker build --target runtime -t event-booking:runtime .`. The roles are `web`
  (default), `worker`, `scheduler` and `migrate`. Task 4 of the runtime image plan shows
  how to run each role against the local stack.
- The design spec is approved as a first version:
  `docs/superpowers/specs/2026-09-28-event-booking-design.md`
- Repository: `git@github.com:Leonelmarianog/event-booking-system.git` (public).
- The system design is in `docs/design/`. The milestones are in
  `docs/superpowers/plans/milestones.md`.

### Design progress

| #   | Section                                                                                                                             | Status                                                            |
| --- | ----------------------------------------------------------------------------------------------------------------------------------- | ----------------------------------------------------------------- |
| 1   | Glossary                                                                                                                            | Done                                                              |
| 2   | Business rules                                                                                                                      | Done                                                              |
| 3   | C4 level 1: system context                                                                                                          | Done                                                              |
| 4   | C4 level 2: containers                                                                                                              | Done                                                              |
| 5   | ERD, with space for future features                                                                                                 | Done                                                              |
| 6   | Sequence diagrams                                                                                                                   | Moved to implementation: one `README.md` in each Action directory |
| 7   | Future features, in three PRs: (a) payments and refunds, (b) PDF tickets and cover image, (c) event change emails and announcements | (a) and (b) done. (c) in review (PR #10)                          |

## Decisions from the design sessions

- Visitors can see published events. Booking requires a login.
- Nobody can edit or cancel a started event.
- Attendees can see a cancelled event.
- The organizer can change the start time to a time in the future. No notification in v1.
- No "past" event status.
- The reminder for an event goes out one time only (`reminder_sent_at`).
- Admins can see draft and cancelled events of all users.
- C4 diagrams show future external systems and connections with dashed lines.
- C4 diagrams use Mermaid flowcharts in C4 style, not the Mermaid C4 syntax.
- C4 level 2: the Web Frontend is a separate container. Migrate is shown as a one-off
  task. Redis is one container with four uses. Vite and Mailpit are not shown.
- ERD: one diagram with v1 and future tables. Future tables end with "(future)".
  Laravel tables `password_reset_tokens` and `failed_jobs` are in the diagram.
  The tables `sessions`, `cache`, `cache_locks`, `jobs` and `job_batches` are not in
  the diagram. Their migrations stay: the first four tables stay empty with the Redis
  drivers, and Laravel keeps job batches in the database with all queue drivers.
- PDF tickets (future): one `tickets` row per seat, each with a check-in code.
  One PDF per booking.
- Payments (future): one price per event, in cents, with a currency code. New booking
  status `pending_payment`. A `payments` table with one row per payment attempt.
- File uploads (future): only the event cover image. No `files` table. The path is in
  `events.cover_image_path`. The PDF path is in `bookings.tickets_pdf_path`.
- Every use case is an Action, for reads and for writes. Controllers call one Action
  and contain no queries.
- Each Action has its own directory directly under `app/Actions/` (no group folders).
  The directory holds the class and a `README.md` with the sequence diagrams.
- The Action README is written in the same PR as the Action code, never before.
  One diagram for each outcome. No `alt` blocks. No diagrams for future features.
- Payments (future): Stripe Checkout in test mode. Seats held 30 minutes (the minimum
  time of a Stripe Checkout page). The price changes only while the event is a draft.
  A late payment gets an automatic refund.
- Refunds (future): full refund when the event is cancelled, or when the attendee
  cancels 7 days or more before the start. Later cancellations free the seats with no
  refund. Refunds are tracked as `refund_pending`, then `refunded` or `refund_failed`.
  Admins see failed refunds.
- PDF tickets (future): the Worker makes the PDF when the booking is confirmed.
  Attached to the confirmation email and downloadable from "My bookings" (attendee
  only). One page per seat with a QR code. On cancel, the tickets are cancelled and
  the PDF is deleted. Ticket check-in is out of scope.
- Cover image (future): JPEG, PNG or WebP, max 2 MB. The organizer can
  replace or remove it until the event starts or is cancelled. No resize.
- Event change emails (future, agreed, PR (c)): a change of start time or venue emails
  all attendees with a confirmed booking, after the commit.
- Announcements (future, agreed, PR (c)): the organizer writes a subject and a
  plain-text body. Emailed to attendees with a confirmed booking, and shown on the event
  page to the organizer, the attendees and admins. Only for published events, until
  24 hours after the start. Max 3 for each event each day. No approval step.
- Announcements cannot be edited or deleted. An admin can hide one (`hidden_at`,
  `hidden_by_id`). Hidden announcements are not shown to attendees.
- The organizer can delete a draft event. No other deletes. No soft deletes (BR-E17).
- Account deletion: blocked while the user has upcoming published events or confirmed
  bookings. Otherwise the system deletes the drafts and anonymizes the user row
  (BR-U1 to BR-U5, `users.anonymized_at`).
- The image is not published to a registry (no GHCR). CI builds and scans the image.
  The operator builds the image from the `Dockerfile`.
- SSR is on in development and in production. In production, the `web` role runs the
  Inertia SSR server next to Nginx and PHP-FPM. The SSR bundle includes all npm
  packages, so the image has Node but no `node_modules`.
- The default Laravel migrations stay (`sessions`, `cache`, `cache_locks`, `jobs`,
  `job_batches`), also when the tables stay empty.

## Next steps

1. The owner reviews the `feat/create-event` PR, merges it, and asks for a sync.
2. Then M3 PR 4 (`GetOrganizerEvents`). Write its plan first.
3. When you plan PR 5 (`UpdateEvent`): if the update rules are the same as the rules
   of `StoreEventRequest`, put them in a trait `app/Concerns/EventValidationRules.php`,
   in the same way as the starter kit traits in `app/Concerns`.
4. Email verification: the v1 scope says that it is off, but the dashboard of the
   starter kit sends a new user to `/email/verify`. The owner decides later when to
   change it.
5. The `users` column `anonymized_at` comes in M6.

Write the plan of each PR first and get the owner's approval.

Execution: Claude writes each PR. The owner reviews. Tests come first, and each test
name gives its rule ID.

Do not scaffold the project, install dependencies or write app code before the owner
approves the design and the plan.

## Working rules (from the owner)

- Work on branches. Do not commit directly to `main`.
- Keep each PR small, with one topic, so that the owner can review it quickly.
- Use semantic commit messages and PR titles (`feat:`, `fix:`, `docs:`, `chore:`, `ci:`,
  `refactor:`, `test:`).
- Write PR descriptions in plain text, with the same structure every time.
  Each description tells clearly what the PR changes.
- Do not add "Generated with Claude Code" or "Co-Authored-By" lines to commits or PRs.
- Do not push or open a PR before the owner approves the local changes. Commit on a
  local branch, show the changes, and wait.
- Each `git push` asks the owner for a code. Tell the owner before each push.
- The owner must own and understand the code. Design each part with the owner before
  you write code.
- Write all design documents in Simple English (ASD-STE100 rules).
- Format Markdown with `npm run check:fix` before you commit. CI runs `vp check`, and it
  checks the Markdown files too.
- Write diagrams in Mermaid, inside Markdown files. GitHub shows them in the PR.
  Render each diagram locally (`npx -y @mermaid-js/mermaid-cli`) before you push it.
- Ask open questions in chat. Do not put open questions in a PR or a document.
- Work on one PR at a time. Always branch from an up-to-date `main`. Do not stack PRs.
- Update this handoff in the same PR as the work. Then stop.
- Wait for the owner. The owner reviews, merges, and then asks you to sync.
- When you sync, pull `main` and make sure that the merged content is on `main`.
  Then start a new branch.

## Decisions already made (do not reopen)

| Topic          | Decision                                                                                                                                                                                                                                |
| -------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Goal           | Portfolio demo. Shows development and DevOps practices. The app itself is secondary.                                                                                                                                                    |
| App            | Event booking with limited seats (free tickets).                                                                                                                                                                                        |
| Stack          | Laravel + Inertia + React (TypeScript), official React starter kit, session auth.                                                                                                                                                       |
| Structure      | Plain Laravel layout, everything under `app/`. No repositories.                                                                                                                                                                         |
| Business logic | Rich domain model: rules live in Eloquent models and enums. Each use case, read or write, is one Action in its own directory `app/Actions/<Name>/`. Actions control the flow (transactions, locks, dispatch). Controllers do HTTP only. |
| Runtime        | Nginx + PHP-FPM + Inertia SSR server (Node) in one container (supervisord).                                                                                                                                                             |
| Database       | PostgreSQL. Redis for queue, cache, sessions and rate limits.                                                                                                                                                                           |
| Deployment     | One Docker image with roles `web`, `worker`, `scheduler`, `migrate`. The owner supplies all external services. Docker Compose for local development.                                                                                    |
| CI             | GitHub Actions: lint, test, build, Trivy scan, concurrency check. No image registry: CI builds and scans the image, but does not publish it.                                                                                            |
| Scope of v1    | Free tickets, no payments. Any user can organize and book. One `is_admin` flag for moderation. Max 4 seats per booking. One active booking per user per event. Email verification is off.                                               |

## Local environment

Docker 27.5 with Compose v2.32, PHP 8.5.1, Composer 2.9.3 and Node 24.13 are installed
(WSL2, Linux).
