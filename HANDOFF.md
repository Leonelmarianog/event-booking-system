# Handoff — Event Booking Demo

Last updated: 2026-10-08

## Where we are

Milestones M1 (Foundation), M2 (CI and production image), M3 (Events) and M4
(Bookings) are complete. Each M3 and M4 rule has at least one test. M5 (Notifications)
is complete when its last PR (PR 4, below) is merged.

M5 (Notifications) is split into four PRs, in this order:

1. `BookingConfirmed`: `ReserveSeats` sends it (BR-N1, BR-N6).
2. `BookingCancelled`: `CancelBooking` sends it (BR-N2).
3. `EventCancelled`: `CancelEvent` sends it to each attendee with a confirmed booking
   (BR-N3, the email part of BR-E13). These attendees get no `BookingCancelled` email.
4. Reminders: `events.reminder_sent_at`, the `SendEventReminders` Action and command,
   scheduled each hour, and `EventReminder` (BR-N4, BR-N5).

PRs 1 to 3 are merged (#37, #38, #39). BR-E13 is complete (also the email part). The
current PR (`feat/event-reminders`) is PR 4, the last PR of M5. Its plan is
`docs/superpowers/plans/2026-10-08-m5-event-reminders.md`. It is open as a PR and waits
for the merge. With this PR, M5 is complete: every rule BR-N1 to BR-N7 has a test.

- `CancelBooking` sends `BookingCancelled` (BR-N2). `CancelEvent` sends
  `EventCancelled` to the attendee of each booking that it cancels, and no
  `BookingCancelled` (BR-N3).
- A migration `add_reminder_sent_at_to_events_table` adds `events.reminder_sent_at`
  (`timestampTz`, nullable). The factory state `reminderSent()` sets it.
- An event is due for a reminder when it is published, has not started, starts in the
  next 24 hours (24 hours included) and has no `reminder_sent_at`: the scope
  `Event::dueForReminder()` and `Event::needsReminder()`. `Event::markReminderSent()`
  sets the time and saves nothing.
- `SendEventReminders` reads the due event IDs, then handles each event in its own
  transaction: lock the event row, check `needsReminder()` again, mark, save, and send
  `EventReminder` to the attendee of each confirmed booking. Each event is wrapped in
  `rescue()`, so an error is logged and the run goes on. A due event with no confirmed
  bookings is also marked (owner decision).
- The command `events:send-reminders` runs each hour with `withoutOverlapping(60)`
  (`routes/console.php`). It prints "Reminders sent for N events.". The owner chose
  hourly over daily at 08:00 after the review: with one run each day, some reminders
  came minutes before the start, and an event published after 08:00 that started
  before the next 08:00 got none. Hourly runs send the reminder about 23 to 24 hours
  ahead. The 60-minute lock stops a killed run from blocking the next runs.
- A start time change after the reminder sends no second reminder and no other email
  (BR-N5, BR-N7; owner decision). An "event changed" email stays a future feature.

Each notification of M5 follows the same pattern (PR 1 sets it):

- `implements ShouldQueue`, `use Queueable`, and `$this->afterCommit()` in the
  constructor. The queue connections have `after_commit => false`, so the notification
  must ask for it (BR-N6).
- `#[Tries(3)]` and `#[Backoff([10, 60])]` on the class (spec section 7). The class
  values win over `--tries` of the worker.
- `via()` returns `['mail']`. `toMail()` uses the default `MailMessage` (a subject, a
  greeting, lines, one action button). No custom mail templates.
- Times in the email use `->utc()->format('D j M Y, H:i')` and the suffix ` UTC`. The
  `utc()` call keeps the text correct when the database session uses another time zone.
- The Action calls `notify()` inside its transaction, after the saves. The queue holds
  the job until the commit and drops it on a rollback.
- Tests: `Notification::fake()` for "who gets which email" (BR-N1 to BR-N3).
  `Notification::fake()` ignores `afterCommit()`, so the BR-N6 tests use the real
  `sync` queue of the test environment and `Event::fake([NotificationSent::class])`:
  nothing is sent inside an open transaction, one email after the commit, none after a
  rollback.
- Rollback tests throw and catch a `LogicException` and check its message. Domain
  exceptions extend `RuntimeException`, so an Action that fails cannot make the test
  pass by mistake.

General notes:

- `config/inertia.php` has `ensure_pages_exist` set to `true`, so a feature test that
  renders a page needs the page file.
- `vp check --fix` also formats the code blocks inside Markdown plans, which can break
  a JSX snippet. Copy JSX from a plan with care.

M4 PR 7 notes:

- `events.cancelled_at`; `Event::cancel()` checks the status, then the start time
  (BR-E12). `EventPolicy::cancel`: 404 / allowed for organizer and admins / 403.
- `CancelEvent` locks the event row, then the confirmed bookings, and calls
  `Booking::cancel()` on each (BR-E13). All write Actions on bookings lock the event row
  first.
- `POST /events/{event}/cancellation` with `auth`, `throttle:event-writes` and
  `can:cancel,event`. "My bookings" rows have `event_cancelled`
  (`Booking::wasCancelledWithEvent()`), shown as the "Event cancelled" badge.

PR 6 notes:

- `EventPolicy::viewAttendees` (404 / allowed for organizer and admins / 403) and
  `GET /events/{event}/attendees`. `GetEventAttendees` lists confirmed bookings with
  name and email, first booked first, 50 for each page. `PaginationNav` is shared by
  `/events` and the attendee page.

PR 5 notes:

- `POST /bookings/{booking:reference}/cancellation` (`bookings.cancellation.store`) has
  `auth`, `throttle:bookings` and `can:cancel,booking` (404 when the person cannot see
  the booking, 403 for the organizer, BR-B9).
- `CancelBooking` locks the event row first, then the booking row. `Booking::cancel()`
  checks the status, then the start time (BR-B10), and calls `Event::releaseSeats()`
  (never above the capacity, BR-B11). Success redirects back with a toast.
- `can_cancel` (`Booking::canBeCancelled()`) is on "My bookings" rows and on the
  `booked` state of the booking box. `CancelBookingDialog` names the event for screen
  readers.

PR 3 notes:

- BR-B14 has two automatic checks. The owner chose automatic checks over a manual
  proof that the check catches a missing lock.
- Layer 1: `tests/Concurrency/ReserveSeatsLockTest.php`. A second database connection
  holds a `FOR KEY SHARE` lock on the event row, and `ReserveSeats` must stop with a
  lock timeout (`lock_timeout = '1s'`). `FOR KEY SHARE` blocks only
  `SELECT ... FOR UPDATE`, not the plain `UPDATE` of the event. With `FOR UPDATE`, the
  test would also pass without `lockForUpdate()`. The test fails when the lock is
  removed (checked once while writing it).
- The `Concurrency` test suite (`tests/Pest.php`, `phpunit.xml`) uses
  `DatabaseTruncation`, because the second connection must see committed rows. It
  truncates `bookings`, `events` and `users` after each test.
- Layer 2: `scripts/check-concurrency.sh [base-url]` (`make concurrency-test`). It seeds
  the data with `ConcurrencyCheckSeeder`, logs in 20 users with curl (the
  `XSRF-TOKEN` cookie goes back in the `X-XSRF-TOKEN` header), sends 20 booking
  requests at the same time, and expects 20 responses with 302, 1 confirmed booking and
  0 available seats. It deletes the rows of its run on exit, also after a failure.
  `ARTISAN` selects how it runs Artisan (default `docker compose exec -T app php
artisan`).
- `ConcurrencyCheckSeeder` uses plain models, because the runtime image has no Faker.
  Each run has a unique tag in the emails (`concurrency-<tag>-<n>@example.test`).
- `compose.concurrency.yaml` runs the runtime image (`web` role) with PostgreSQL and
  Redis, for CI. `WEB_PORT` (default 8080) lets it run next to the local stack. It
  needs `APP_KEY`, also for `down` (`APP_KEY=x` is enough).
- The CI job `concurrency` builds the runtime image again from the GitHub Actions cache
  (read only) and runs the script. On failure, it prints the logs of the web role.
- PHP-FPM has 5 workers (the default), so up to 5 booking requests run at the same
  time.

PR 2 notes:

- The booking route is `POST /events/{event}/bookings` (`events.bookings.store`,
  `BookingController@store`). It has `auth` (BR-B1), `throttle:bookings` and
  `can:view,event`, so hidden events give a 404 before the booking rules run.
- The rate limiter `bookings` allows 10 requests each minute for each user.
  `CancelBooking` (PR 5) must use it too. `event-writes` and `bookings` share
  `AppServiceProvider::tooManyRequests()`.
- `Event::reserve()` checks, in this order: published, not started (both
  `EventNotBookable`), not the organizer (`EventNotBookable::ownEvent()`), no confirmed
  booking (`AlreadyBooked`), enough seats (`NotEnoughSeats`, field `quantity`). It
  decreases the seats and returns an unsaved confirmed booking.
- `ReserveSeats` locks the event row in a transaction and saves the event and the
  booking. The concurrency check (PR 3) proves BR-B14.
- After a booking, the user goes back to the event page. The toast shows the reference.
- `GetEvent::handle(Event, ?User)` returns `['event' => ..., 'booking' => ...]`. The
  `booking` prop has the states `booked`, `available` (with `max_quantity`), `login`,
  `sold_out`, or `null`. The organizer of a sold-out event also sees "Sold out". The
  edit page uses only `event`.
- Run `php artisan wayfinder:generate --with-form` if you generate the routes by hand.
  Without `--with-form`, the `.form()` helpers are missing and `tsc` fails.

PR 1 notes:

- `bookings.reference` is a ULID (lowercase, 26 characters). The model makes it on
  create (`HasUlids` with `uniqueIds()`). The primary key stays a `bigint`.
- A partial unique index allows one confirmed booking for each user and event (BR-B4).
  Cancelled bookings do not count, so a user can book again after a cancel (BR-B12).
- The database also checks the quantity (1 to 4, BR-B5), the status and the unique
  reference (BR-B8). Events and users with bookings cannot be deleted (`RESTRICT`).
- `Booking` has no `#[Fillable]`, like `Event`. The relation to the user is
  `attendee` (`user_id`).
- `BookingFactory` makes a confirmed booking of one seat for a published event. It
  does not change `events.seats_available`. Tests that need the right number of seats
  set it themselves.
- BR-E16 is complete: a user with any booking (confirmed or cancelled) for a cancelled
  event can see it (`Event::hasBookingBy()`).
- `BookingPolicy::view` allows the attendee and the organizer of the event (BR-B13).
  All others, also admins, get a 404. Admins see attendees through the attendee list
  (BR-A2, PR 6).
- `BookingPolicy::cancel` allows only the attendee (BR-B9). The organizer gets a 403,
  all others a 404. The model checks the state in PR 5 (BR-B10).

M3 notes:

- The model has the query scopes `published()` and `upcoming()` (with the `#[Scope]`
  attribute). `upcoming()` uses the same rule as `Event::hasStarted()`.
- The public list is `GET /events` (`events.index`): upcoming published events,
  earliest first, 12 on each page (`?page=N`), as cards. Sold-out events stay in the
  list with a "Sold out" badge. The list is the same for visitors, users and admins.
- `/` redirects to `/events`. The route name `home` stays, because the auth layouts and
  the logout redirect use it. The starter kit welcome page is deleted, and
  `ExampleTest` now checks the redirect.
- The sidebar has "Events" for everyone, and the logo opens the list.
  `app-header.tsx` still links to the dashboard, but the app does not use the header
  layout.
- `Paginated<T>` in `resources/js/types/pagination.ts` describes a Laravel
  `paginate()` result.

- BR-E17 is checked twice. `EventPolicy::delete` allows only the organizer of a draft
  (404 for hidden events, 403 for the rest). `Event::ensureCanBeDeleted()` throws
  `InvalidStateTransition::cannotDelete()` as a last line of defense.
- The delete route is REST: `DELETE /events/{event}` (`EventController@destroy`). It
  has the `event-writes` limit, and the spec now lists delete for that limit.
- A draft that has started can be deleted. BR-E17 does not limit the start time.
- The event page gets `can.delete` from the policy. The red "Delete" button opens a
  confirm dialog. After the delete, the user sees "My events" and the toast
  "Event deleted.".

- State changes use REST sub-resource routes: publish is
  `POST /events/{event}/publication` (`EventPublicationController@store`). Cancel in M4
  follows the same pattern (`POST /events/{event}/cancellation`).
- `EventStatus::canTransitionTo()` follows the state diagram in
  `docs/design/business-rules.md`.
- `InvalidStateTransition` and `EventHasStarted` have one named constructor for each
  change (`cannotPublish()`). Cancel in M4 adds `cannotCancel()`.
- `Event::publish()` checks the status, then the start time, and sets `published_at`.
  `Event::canBePublished()` gives the same answer as a bool, for the button.
- `EventPolicy::publish` returns a `Response`: 404 for hidden events, 403 for other
  users.
- The event page gets `can.publish` (policy and `canBePublished()`). The "Publish"
  button opens a confirm dialog with a controlled `open` state, which closes when the
  request finishes.
- Tests that compare a stored time with `now()` use `freezeSecond()`, because the
  `datetime` cast drops the microseconds.

- `EventPolicy::update` returns a `Response`: 404 when the person cannot see the event,
  403 for all other refusals. An admin gets 403 on the draft of another user, because
  admins can see drafts.
- Domain exceptions extend `App\Exceptions\Domain\DomainException` (a
  `RuntimeException`). The model throws them and knows nothing about HTTP. One handler
  in `bootstrap/app.php` turns them into a redirect back with an error toast, plus a
  field error when `field()` names a field. A JSON request gets 422 with
  `{ "message": "..." }`. The owner chose this design over
  `ValidationException::withMessages()` in the model.
- `Event::changeCapacity()` (BR-E6 to BR-E8) throws `CapacityBelowBookedSeats`. There
  are no bookings in M3, so the tests set `seats_available` directly.
- `UpdateEvent` locks the event row in a transaction. The status and `published_at` do
  not change.
- `App\Concerns\EventValidationRules` holds the event rules and `eventAttributes()`.
  `StoreEventRequest` and `UpdateEventRequest` use it.
- The create and edit pages share `EventForm`. The start time input is empty on the
  first render and gets the local time after the page loads (no hydration error).
- The event page gets `can.update` from the controller. Each "My events" row gets
  `can_update` from `GetOrganizerEvents`. Both use `EventPolicy::update`. The query of
  `GetOrganizerEvents` selects `organizer_id`, because the policy needs it.

- The "My events" page (`/organizer/events`) shows only the events of the user, also
  for admins, with all statuses. It has two tables: "Upcoming" (earliest first) and
  "Past" (most recent first). An event that starts now is in "Past"
  (`Event::hasStarted()`). No pagination.
- `Event::seatsBooked()` gives capacity − available seats.
- `EventStatusBadge` shows a badge for each status. The event page still has its own
  badges and shows no badge for a published event.
- The sidebar shows "My events" and "Create event" to logged-in users only.
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
- `eventAttributes()` gives the validated values with their types to the Action,
  because PHPStan does not accept the `array<string, mixed>` of `validated()` for an
  array shape.
- The app uses `CarbonImmutable` for dates (`Date::use()` in `AppServiceProvider`). The
  PHPDoc of `Event` uses `CarbonImmutable`. The PHPDoc of `User` still uses
  `Illuminate\Support\Carbon`.
- A person who cannot see an event gets a 404 page, not a 403 page
  (`Response::denyAsNotFound()` in `EventPolicy::view`).
- Visitors see "Log in" and "Register" links in the sidebar footer.
- The development database has test data: the user `organizer@example.com` (password
  `password`), a published event (ID 1) and a draft event (ID 2).
- The `events` table has only the columns that M3 uses. `cancelled_at` comes in M4,
  `reminder_sent_at` comes with the reminders.
- A model method that changes state comes in the PR of the Action that uses it.
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
  coverage in the job summary), `image` (build, role check, Trivy) and `concurrency`
  (the parallel booking check) run in parallel. `main` has
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

1. The owner reviews the `feat/event-reminders` PR, merges it, and asks for a sync.
2. After the merge, M5 is complete. Split M6 (user accounts) into PRs with the owner.
3. Email verification: the v1 scope says that it is off, but the dashboard of the
   starter kit sends a new user to `/email/verify`. The owner decides later when to
   change it.
4. The `users` column `anonymized_at` comes in M6.
5. Optional, for the owner to decide: `Model::shouldBeStrict()` outside production, so
   that reading a column that the query did not select throws an error.
6. Later, for the owner to decide: rewrite `scripts/check-concurrency.sh` in Python
   (standard library only: `urllib`, `http.cookiejar`, `threading.Barrier`,
   `subprocess`), for readability. `check-image-roles.sh` can stay in bash.

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
