# M4 — Concurrency Check

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Two automatic checks prove BR-B14 (two users cannot book the same last
seat). A Pest test proves that `ReserveSeats` waits for the row lock. A script sends 20
parallel booking requests for 1 seat to the running stack and expects exactly 1
confirmed booking. The script runs locally (`make concurrency-test`) and in a new CI
job (`concurrency`).

**Architecture:**

- Layer 1: `tests/Concurrency/ReserveSeatsLockTest.php`. A second database connection
  holds a lock on the event row. `ReserveSeats` must wait for it and stop with a lock
  timeout. If the Action does not lock the row, the test fails. The directory has its
  own Pest setup (`DatabaseTruncation`), because the second connection must see
  committed rows.
- Layer 2: `ConcurrencyCheckSeeder` creates the data. `scripts/check-concurrency.sh`
  logs in 20 users with curl, sends 20 booking requests at the same time, checks the
  result in the database, and deletes its data at the end. In CI,
  `compose.concurrency.yaml` starts the runtime image (`web` role) with PostgreSQL and
  Redis.

**Tech Stack:** Laravel 13, Pest, PostgreSQL 18, bash, curl, Docker Compose, GitHub
Actions.

**Spec:** `docs/superpowers/specs/2026-09-28-event-booking-design.md` (sections 9 and
11), `docs/design/business-rules.md` (BR-B14).

## Global Constraints

- The lock test holds a `FOR KEY SHARE` row lock on the event, not `FOR UPDATE`.
  `FOR KEY SHARE` blocks the `SELECT ... FOR UPDATE` of `ReserveSeats`, but not a plain
  `UPDATE` of the event or the `INSERT` of the booking. Thus only the explicit row lock
  waits. With `FOR UPDATE`, the `UPDATE` of the event would also wait, and the test
  would pass also without `lockForUpdate()`.
- The lock test sets `lock_timeout = '1s'` on the default connection and resets it
  after each test.
- The lock test directory uses `DatabaseTruncation` and truncates `bookings`, `events`
  and `users` after each test, so that no committed rows stay for other tests.
- The seeder uses plain models, not factories. Faker is a dev dependency and is not in
  the runtime image.
- The seeder makes a unique tag for each run. The emails are
  `concurrency-<tag>-<n>@example.test` and `concurrency-<tag>-organizer@example.test`.
  The password is `password`. The event is published, has 1 seat and starts in one
  day. The seeder prints `CONCURRENCY_TAG=<tag>` and `CONCURRENCY_EVENT_ID=<id>`.
- The script logs in like a browser: it gets the `XSRF-TOKEN` cookie from `GET /login`
  and sends it back in the `X-XSRF-TOKEN` header. It does not use `Sec-Fetch-Site`.
- The script starts the 20 booking requests as background curl processes, then waits.
  Every request must get `302` (a booking or a redirect back with an error toast). Any
  other status (419, 429, 500) fails the check.
- The script expects exactly 1 confirmed booking and `seats_available = 0`.
- The script deletes the bookings, the event and the users of its run on exit, also
  when the check fails (`trap ... EXIT`).
- `ARTISAN` selects how the script runs Artisan. Default:
  `docker compose exec -T app php artisan`. The first argument is the base URL.
  Default: `http://localhost:8080`.
- The CI job `concurrency` builds the runtime image again from the GitHub Actions cache
  (`cache-from` only; the `image` job writes the cache). It runs in parallel with the
  other jobs.
- `compose.concurrency.yaml` is only for the check. It has its own project name and a
  configurable web port (`WEB_PORT`, default `8080`), so it can run next to the local
  stack.
- Run commands inside the `app` container: `docker compose exec app <command>`.

## Review Focus

1. **The lock mode in the test.** Read the first Global Constraint. Step 3 of Task 1
   shows the test fail without `lockForUpdate()`.
2. **No committed rows leak.** The lock test truncates its tables after each test. The
   other test directories still use `RefreshDatabase`.
3. **False passes of the script.** A login or CSRF error gives 0 bookings or a status
   that is not 302, so the check fails. Exactly 1 booking with all 302 responses is the
   only pass.
4. **The local data.** The script deletes only the rows of its own tag and event.

---

### Task 1: Layer 1 — the lock test

**Files:**

- Create: `tests/Concurrency/ReserveSeatsLockTest.php`
- Modify: `tests/Pest.php`
- Modify: `phpunit.xml`

**Interfaces:**

- Consumes: `ReserveSeats::handle(Event, User, int): Booking`.
- Produces: the test suite `Concurrency`.

- [ ] **Step 1: Register the directory**

In `tests/Pest.php`, import `Illuminate\Foundation\Testing\DatabaseTruncation` and add
after the `Feature` block:

```php
pest()->extend(TestCase::class)
    ->use(DatabaseTruncation::class)
    ->in('Concurrency');
```

In `phpunit.xml`, add after the `Feature` test suite:

```xml
<testsuite name="Concurrency">
    <directory>tests/Concurrency</directory>
</testsuite>
```

- [ ] **Step 2: Write the test**

Create `tests/Concurrency/ReserveSeatsLockTest.php`:

```php
<?php

use App\Actions\ReserveSeats\ReserveSeats;
use App\Models\Booking;
use App\Models\Event;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/*
 * These tests use a second database connection as "another booking request". It holds
 * a FOR KEY SHARE lock on the event row. That lock blocks SELECT ... FOR UPDATE, but
 * not a plain UPDATE of the event or the INSERT of a booking. So ReserveSeats waits
 * only because of its explicit row lock (BR-B14).
 */

beforeEach(function () {
    config(['database.connections.other_request' => config('database.connections.pgsql')]);
    $this->otherRequest = DB::connection('other_request');

    $this->attendee = User::factory()->create();
    $this->event = Event::factory()->published()->create(['capacity' => 1, 'seats_available' => 1]);

    DB::statement("SET lock_timeout = '1s'");
});

afterEach(function () {
    if ($this->otherRequest->transactionLevel() > 0) {
        $this->otherRequest->rollBack();
    }

    DB::purge('other_request');
    DB::statement('RESET lock_timeout');
    DB::statement('TRUNCATE bookings, events, users RESTART IDENTITY CASCADE');
});

test('BR-B14: ReserveSeats waits for the lock of another request on the event row', function () {
    $this->otherRequest->beginTransaction();
    $this->otherRequest->select('SELECT id FROM events WHERE id = ? FOR KEY SHARE', [$this->event->id]);

    expect(fn () => app(ReserveSeats::class)->handle($this->event, $this->attendee, 1))
        ->toThrow(QueryException::class, 'lock timeout');

    expect(Booking::count())->toBe(0)
        ->and($this->event->fresh()->seats_available)->toBe(1);
});

test('BR-B14: ReserveSeats books the seat after the other request ends', function () {
    $this->otherRequest->beginTransaction();
    $this->otherRequest->select('SELECT id FROM events WHERE id = ? FOR KEY SHARE', [$this->event->id]);
    $this->otherRequest->rollBack();

    $booking = app(ReserveSeats::class)->handle($this->event, $this->attendee, 1);

    expect($booking->isConfirmed())->toBeTrue()
        ->and($this->event->fresh()->seats_available)->toBe(0);
});
```

- [ ] **Step 3: Prove that the test catches a missing lock**

In `app/Actions/ReserveSeats/ReserveSeats.php`, remove `->lockForUpdate()` for this step
only.

Run: `docker compose exec app php artisan test --compact tests/Concurrency`
Expected: the first test FAILS (no exception; a booking is made). The second test
passes.

Restore `->lockForUpdate()`. Run `git diff app/Actions` and make sure that it shows no
change.

- [ ] **Step 4: Run the test with the lock**

Run: `docker compose exec app php artisan test --compact tests/Concurrency`
Expected: PASS (2 tests). The first test takes about one second.

Run: `docker compose exec app php artisan test --compact`
Expected: all tests pass. The Feature tests are not affected by the truncation.

- [ ] **Step 5: Commit**

```bash
git add tests/Concurrency tests/Pest.php phpunit.xml
git commit -m "test: prove that ReserveSeats locks the event row"
```

---

### Task 2: `ConcurrencyCheckSeeder`

**Files:**

- Create: `database/seeders/ConcurrencyCheckSeeder.php`
- Test: `tests/Feature/ConcurrencyCheckSeederTest.php`

**Interfaces:**

- Produces: `php artisan db:seed --class=ConcurrencyCheckSeeder`, which prints
  `CONCURRENCY_TAG=<tag>` and `CONCURRENCY_EVENT_ID=<id>`.

- [ ] **Step 1: Write the failing test**

Create the file with `php artisan make:test --pest ConcurrencyCheckSeederTest --no-interaction`,
then replace its content:

```php
<?php

use App\Enums\EventStatus;
use App\Models\Event;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;

test('the seeder makes a published event with 1 seat and 20 users who can log in', function () {
    Artisan::call('db:seed', ['--class' => 'ConcurrencyCheckSeeder', '--no-interaction' => true]);
    $output = Artisan::output();

    preg_match('/CONCURRENCY_TAG=([a-z0-9]+)/', $output, $tag);
    preg_match('/CONCURRENCY_EVENT_ID=(\d+)/', $output, $eventId);

    $event = Event::findOrFail((int) $eventId[1]);
    $users = User::where('email', 'like', "concurrency-{$tag[1]}-%")->get();
    $attendees = $users->reject(fn (User $user) => $user->is($event->organizer));

    expect($event->status)->toBe(EventStatus::Published)
        ->and($event->capacity)->toBe(1)
        ->and($event->seats_available)->toBe(1)
        ->and($event->isBookable())->toBeTrue()
        ->and($event->organizer->email)->toBe("concurrency-{$tag[1]}-organizer@example.test")
        ->and($attendees)->toHaveCount(20)
        ->and(Hash::check('password', $attendees->first()->password))->toBeTrue();
});

test('each run of the seeder has its own tag', function () {
    Artisan::call('db:seed', ['--class' => 'ConcurrencyCheckSeeder', '--no-interaction' => true]);
    $first = Artisan::output();

    $this->travel(1)->seconds();

    Artisan::call('db:seed', ['--class' => 'ConcurrencyCheckSeeder', '--no-interaction' => true]);
    $second = Artisan::output();

    preg_match('/CONCURRENCY_TAG=([a-z0-9]+)/', $first, $firstTag);
    preg_match('/CONCURRENCY_TAG=([a-z0-9]+)/', $second, $secondTag);

    expect($firstTag[1])->not->toBe($secondTag[1])
        ->and(User::count())->toBe(42);
});
```

- [ ] **Step 2: Run the test to make sure it fails**

Run: `docker compose exec app php artisan test --compact tests/Feature/ConcurrencyCheckSeederTest.php`
Expected: FAIL. The class `ConcurrencyCheckSeeder` does not exist.

- [ ] **Step 3: Write the seeder**

Run: `docker compose exec app php artisan make:seeder ConcurrencyCheckSeeder --no-interaction`

```php
<?php

namespace Database\Seeders;

use App\Enums\EventStatus;
use App\Models\Event;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * The data of the concurrency check (scripts/check-concurrency.sh): a published event
 * with 1 seat and 20 users. It uses no factories, because the runtime image has no
 * Faker. Each run has its own tag, so that the script can delete only its own rows.
 */
class ConcurrencyCheckSeeder extends Seeder
{
    /**
     * The number of users who try to book the last seat.
     */
    public const ATTENDEES = 20;

    /**
     * Seed the data and print the tag and the event ID for the script.
     */
    public function run(): void
    {
        $tag = now()->format('YmdHis').Str::lower(Str::random(4));
        $password = Hash::make('password');

        $organizer = User::create([
            'name' => 'Concurrency organizer',
            'email' => "concurrency-{$tag}-organizer@example.test",
            'password' => $password,
        ]);

        foreach (range(1, self::ATTENDEES) as $number) {
            User::create([
                'name' => "Concurrency attendee {$number}",
                'email' => "concurrency-{$tag}-{$number}@example.test",
                'password' => $password,
            ]);
        }

        $event = new Event;
        $event->organizer_id = $organizer->id;
        $event->title = "Concurrency check {$tag}";
        $event->description = 'An event with one seat for the concurrency check.';
        $event->venue = 'Nowhere';
        $event->starts_at = now()->addDay();
        $event->capacity = 1;
        $event->seats_available = 1;
        $event->status = EventStatus::Published;
        $event->published_at = now();
        $event->save();

        $this->command->line("CONCURRENCY_TAG={$tag}");
        $this->command->line("CONCURRENCY_EVENT_ID={$event->id}");
    }
}
```

If the `password` cast of `User` hashes the value again, the login test of Task 3 fails.
Laravel's `hashed` cast does not hash a value that is already hashed.

- [ ] **Step 4: Run the test to make sure it passes**

Run: `docker compose exec app php artisan test --compact tests/Feature/ConcurrencyCheckSeederTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add database/seeders/ConcurrencyCheckSeeder.php tests/Feature/ConcurrencyCheckSeederTest.php
git commit -m "test: add the seeder of the concurrency check"
```

---

### Task 3: Layer 2 — the script and `make concurrency-test`

**Files:**

- Create: `scripts/check-concurrency.sh`
- Modify: `Makefile`

**Interfaces:**

- Consumes: `ConcurrencyCheckSeeder`, the routes `login` and `events.bookings.store`.
- Produces: `scripts/check-concurrency.sh [base-url]`, `make concurrency-test`.

- [ ] **Step 1: Write the script**

Create `scripts/check-concurrency.sh` and make it executable (`chmod +x`):

```bash
#!/usr/bin/env bash
# Sends parallel booking requests for the last seat of an event to the running app,
# and checks that exactly one booking is confirmed (BR-B14).
#
# Usage: scripts/check-concurrency.sh [base-url]
#
# The base URL defaults to http://localhost:8080. ARTISAN is the command that runs
# Artisan next to the app (default: docker compose exec -T app php artisan).
# The script deletes its users, event and bookings when it ends.
set -euo pipefail

base_url="${1:-http://localhost:8080}"
read -r -a artisan <<< "${ARTISAN:-docker compose exec -T app php artisan}"
work="$(mktemp -d)"
tag=""
event_id=""

fail() {
    echo "FAIL: $1"
    exit 1
}

tinker() {
    "${artisan[@]}" tinker --execute "$1"
}

cleanup() {
    if [ -n "$event_id" ]; then
        tinker "App\\Models\\Booking::where('event_id', $event_id)->delete(); App\\Models\\Event::whereKey($event_id)->delete();" > /dev/null \
            || echo "WARN: could not delete the event $event_id"
    fi
    if [ -n "$tag" ]; then
        tinker "App\\Models\\User::where('email', 'like', 'concurrency-$tag-%')->delete();" > /dev/null \
            || echo "WARN: could not delete the users of $tag"
    fi
    rm -rf "$work"
}
trap cleanup EXIT

# The value of the XSRF-TOKEN cookie in a cookie jar, URL-decoded.
xsrf_token() {
    local value
    value="$(awk '$6 == "XSRF-TOKEN" { print $7 }' "$1")"
    printf '%b' "${value//%/\\x}"
}

echo "== seed"
seed_output="$("${artisan[@]}" db:seed --class=ConcurrencyCheckSeeder --force --no-interaction)"
tag="$(grep -o 'CONCURRENCY_TAG=[a-z0-9]*' <<< "$seed_output" | cut -d= -f2 || true)"
event_id="$(grep -o 'CONCURRENCY_EVENT_ID=[0-9]*' <<< "$seed_output" | cut -d= -f2 || true)"
[ -n "$tag" ] && [ -n "$event_id" ] || fail "the seeder did not print the tag and the event ID: $seed_output"
attendees="$(tinker "echo Database\\Seeders\\ConcurrencyCheckSeeder::ATTENDEES;" | tail -n 1)"

echo "== log in $attendees users"
for number in $(seq 1 "$attendees"); do
    jar="$work/$number.jar"
    curl -sS -o /dev/null -c "$jar" -b "$jar" "$base_url/login"
    redirect="$(curl -sS -o /dev/null -w '%{redirect_url}' -c "$jar" -b "$jar" \
        -H "X-XSRF-TOKEN: $(xsrf_token "$jar")" -H 'Accept: text/html' \
        --data-urlencode "email=concurrency-$tag-$number@example.test" \
        --data-urlencode 'password=password' \
        "$base_url/login")"
    [ -n "$redirect" ] && [ "$redirect" != "$base_url/login" ] \
        || fail "the login of user $number did not work (redirect: '$redirect')"
    xsrf_token "$jar" > "$work/$number.token"
done

echo "== send $attendees booking requests at the same time"
for number in $(seq 1 "$attendees"); do
    curl -sS -o /dev/null -w '%{http_code}' -b "$work/$number.jar" \
        -H "X-XSRF-TOKEN: $(cat "$work/$number.token")" -H 'Accept: text/html' \
        -d quantity=1 "$base_url/events/$event_id/bookings" > "$work/$number.status" &
done
wait

statuses="$(cat "$work"/*.status | sort | uniq -c | tr -s ' ' | tr '\n' ',')"
other="$(cat "$work"/*.status | grep -cv '^302$' || true)"
[ "$other" = 0 ] || fail "some requests did not get 302 (count status:$statuses)"

echo "== check the database"
result="$(tinker "echo App\\Models\\Booking::where('event_id', $event_id)->where('status', 'confirmed')->count().' '.App\\Models\\Event::findOrFail($event_id)->seats_available;" | tail -n 1)"
read -r confirmed seats <<< "$result"
[ "$confirmed" = 1 ] || fail "expected 1 confirmed booking, found $confirmed"
[ "$seats" = 0 ] || fail "expected 0 available seats, found $seats"

echo "PASS: $attendees parallel requests for 1 seat gave 1 confirmed booking."
```

- [ ] **Step 2: Add the Make target**

In `Makefile`:

- add `concurrency-test` to `.PHONY`;
- change the `help` pattern to `'^[a-z-]+:.*## '` and the column width to `%-18s`, so
  that the target name with a hyphen shows in the list;
- add the target after `fresh`:

```make
concurrency-test: ## Check that parallel bookings never overbook (needs a running stack)
	scripts/check-concurrency.sh
```

- [ ] **Step 3: Run the check against the local stack**

Run: `make concurrency-test`
Expected: the four steps (seed, log in, send, check) and
`PASS: 20 parallel requests for 1 seat gave 1 confirmed booking.`

Run: `docker compose exec -T app php artisan tinker --execute 'echo App\Models\User::where("email", "like", "concurrency-%")->count();'`
Expected: `0` (the script deleted its data).

Run: `make help`
Expected: `concurrency-test` is in the list.

Run: `shellcheck scripts/check-concurrency.sh` if `shellcheck` is installed.
Expected: no warnings.

- [ ] **Step 4: Commit**

```bash
git add scripts/check-concurrency.sh Makefile
git commit -m "test: add the concurrency check script and make target"
```

---

### Task 4: The `concurrency` CI job

**Files:**

- Create: `compose.concurrency.yaml`
- Modify: `.github/workflows/ci.yml`

**Interfaces:**

- Consumes: `scripts/check-concurrency.sh`, the `runtime` target of the `Dockerfile`.
- Produces: the CI job `concurrency`.

- [ ] **Step 1: Write the Compose file**

Create `compose.concurrency.yaml`:

```yaml
# The stack of the concurrency check (scripts/check-concurrency.sh): the runtime image
# in the web role, with PostgreSQL and Redis. CI uses it. Build the image first:
#   docker build --target runtime -t event-booking:runtime .
#   APP_KEY=base64:$(openssl rand -base64 32) docker compose -f compose.concurrency.yaml up -d --wait
name: event-booking-concurrency

x-app: &app
    image: event-booking:runtime
    environment:
        APP_KEY: ${APP_KEY:?set APP_KEY}
        APP_ENV: production
        APP_URL: http://localhost:${WEB_PORT:-8080}
        DB_CONNECTION: pgsql
        DB_HOST: postgres
        DB_DATABASE: event_booking
        DB_USERNAME: event_booking
        DB_PASSWORD: secret
        REDIS_HOST: redis
        SESSION_DRIVER: redis
        CACHE_STORE: redis
        QUEUE_CONNECTION: redis
        MAIL_MAILER: log

services:
    migrate:
        <<: *app
        command: migrate
        depends_on:
            postgres:
                condition: service_healthy

    web:
        <<: *app
        command: web
        ports:
            - ${WEB_PORT:-8080}:8080
        depends_on:
            migrate:
                condition: service_completed_successfully
            redis:
                condition: service_healthy

    postgres:
        image: postgres:18-alpine
        environment:
            POSTGRES_DB: event_booking
            POSTGRES_USER: event_booking
            POSTGRES_PASSWORD: secret
        healthcheck:
            test: [CMD, pg_isready, -U, event_booking, -d, event_booking]
            interval: 5s
            timeout: 3s
            retries: 20

    redis:
        image: redis:8-alpine
        healthcheck:
            test: [CMD, redis-cli, ping]
            interval: 5s
            timeout: 3s
            retries: 20
```

- [ ] **Step 2: Run the CI stack locally**

Run: `docker build --target runtime -t event-booking:runtime .`
Run: `APP_KEY=base64:$(openssl rand -base64 32) WEB_PORT=8090 docker compose -f compose.concurrency.yaml up -d --wait`
Run: `ARTISAN="docker compose -f compose.concurrency.yaml exec -T web php artisan" scripts/check-concurrency.sh http://localhost:8090`
Expected: `PASS: 20 parallel requests for 1 seat gave 1 confirmed booking.`
Run: `APP_KEY=x docker compose -f compose.concurrency.yaml down -v`

The local stack on port 8080 keeps running during this step.

- [ ] **Step 3: Add the CI job**

In `.github/workflows/ci.yml`, add after the `image` job:

```yaml
# 20 parallel booking requests for 1 seat must give exactly 1 booking (BR-B14).
concurrency:
    runs-on: ubuntu-latest

    steps:
        - name: Checkout code
          uses: actions/checkout@3d3c42e5aac5ba805825da76410c181273ba90b1 # v7.0.1
          with:
              persist-credentials: false

        - name: Setup Buildx
          uses: docker/setup-buildx-action@f87e5991a6d7451dcb8d9637bfbc97413f497069 # v4.4.1

        # Reads the cache that the image job writes. It does not write the cache.
        - name: Build the runtime image
          uses: docker/build-push-action@c3c9e263c25d99ce0380d002d59b67737d91b0dc # v7.4.0
          with:
              context: .
              target: runtime
              tags: event-booking:runtime
              load: true
              push: false
              cache-from: type=gha

        # One key for all steps: the later steps read APP_KEY from the job environment.
        - name: Start the stack
          run: |
              app_key="base64:$(openssl rand -base64 32)"
              echo "::add-mask::$app_key"
              echo "APP_KEY=$app_key" >> "$GITHUB_ENV"
              APP_KEY="$app_key" docker compose -f compose.concurrency.yaml up -d --wait

        - name: Run the concurrency check
          env:
              ARTISAN: docker compose -f compose.concurrency.yaml exec -T web php artisan
          run: scripts/check-concurrency.sh http://localhost:8080

        - name: Logs of the web role
          if: failure()
          run: docker compose -f compose.concurrency.yaml logs web
```

- [ ] **Step 4: Check the workflow file**

Run: `npm run check:fix`
Run: `npx -y @action-validator/cli .github/workflows/ci.yml` if it is available, or
`docker run --rm -v "$PWD":/repo rhysd/actionlint:latest -color /repo/.github/workflows/ci.yml`.
Expected: no errors.

- [ ] **Step 5: Commit**

```bash
git add compose.concurrency.yaml .github/workflows/ci.yml
git commit -m "ci: run the concurrency check in its own job"
```

---

### Task 5: Checks, handoff and plan

**Files:**

- Modify: `HANDOFF.md`
- Add: `docs/superpowers/plans/2026-10-07-m4-concurrency-check.md`

- [ ] **Step 1: Format and run all checks**

Run: `docker compose exec app vendor/bin/pint --dirty --format agent`
Run: `npm run check:fix`
Run: `docker compose exec app composer ci:check`
Expected: Pint, PHPStan, `vp check`, `tsc` and all tests pass, also the `Concurrency`
suite.

- [ ] **Step 2: Update `HANDOFF.md`**

"Where we are": PR 2 is merged. The current PR is M4 PR 3 (`test/concurrency-check`),
with the path of this plan. Add the decisions: the two layers; the `FOR KEY SHARE`
lock in the lock test and why; the `Concurrency` test suite with `DatabaseTruncation`;
the seeder without factories and its tag; the script, its `ARTISAN` variable and the
cleanup; `make concurrency-test`; `compose.concurrency.yaml` with `WEB_PORT`; the CI
job that rebuilds the image from the cache. Add `concurrency` to the list of CI jobs.
"Next steps": the owner reviews this PR. Then plan PR 4 (`GetBookings`).

- [ ] **Step 3: Commit**

```bash
git add HANDOFF.md docs/superpowers/plans/2026-10-07-m4-concurrency-check.md
git commit -m "docs: plan the concurrency check and update handoff"
```
