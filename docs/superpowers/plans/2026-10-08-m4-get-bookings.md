# M4 — GetBookings: the "My bookings" Page

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A logged-in user can see all their bookings on the page `/bookings`. The page
has two tables: "Upcoming" and "Past". Each row shows the event, the start time, the
venue, the number of seats, the booking reference and the status.

**Architecture:** The route `bookings.index` has the `auth` middleware.
`BookingController@index` calls the `GetBookings` Action. The Action reads the bookings
of the user, sorted by the start time of the event, and splits them into upcoming and
past bookings with `Event::hasStarted()`. The React page `bookings/index` shows the two
tables in the sidebar layout of the starter kit.

**Tech Stack:** Laravel 13, Inertia 3, React 19, TypeScript, Wayfinder, Pest.

**Spec:** `docs/superpowers/specs/2026-09-28-event-booking-design.md` (sections 5 and
8), `docs/design/erd.md` (index `bookings(user_id)`), `docs/design/business-rules.md`
(BR-B13).

## Global Constraints

- The page shows only the bookings of the logged-in user. Admins also see only their
  own bookings on this page.
- The page shows confirmed and cancelled bookings. Each row shows the status.
- "Upcoming" has the bookings of events that have not started, earliest event first.
  "Past" has the bookings of events that have started, most recent event first. The
  Action uses `Event::hasStarted()` for the split. An event that starts now has
  started.
- When two bookings have events with the same start time, the booking with the lower
  `id` comes first in "Upcoming" and last in "Past".
- Columns: Event (link to the event page), Starts, Venue, Seats, Reference (all 26
  characters), Status.
- No pagination.
- The page has no cancel button. `CancelBooking` (PR 5) adds it.
- The route has the `auth` middleware only, not `verified`. It needs no policy check,
  because the Action reads only the bookings of the user.
- The controller calls one Action and contains no queries.
- The Action directory is `app/Actions/GetBookings/` with `GetBookings.php` and
  `README.md`. The README has one sequence diagram for each outcome and no `alt`
  blocks. It is in Simple English.
- Page props use the snake_case names of the columns.
- The sidebar shows the link "My bookings" only to logged-in users, after "My events".
- When the user has no bookings, the page shows "You have no bookings yet." and a link
  to the events page.
- Run commands inside the `app` container: `docker compose exec app <command>`. The
  tests use the test database. Do not run `migrate:fresh` against the development
  database.

## Review Focus

1. **Bookings of other users.** They do not show, also for an admin and also for the
   organizer of the event. Pinned by a test in Task 1.
2. **The split between upcoming and past.** A booking for an event that starts now is
   in "Past". Pinned by a test in Task 1.
3. **Two bookings for the same event.** A user can have a cancelled booking and a new
   confirmed booking for the same event (BR-B12). Both rows show, with a stable order.
   Pinned by a test in Task 1.
4. **The table on a phone.** The table has six columns and a long reference. The table
   scrolls horizontally, and the page does not. Checked by the owner in Task 2.
5. **The empty state.** A new user sees the message and the link to the events page.
   Checked by the owner in Task 2.

---

### Task 1: Action, route and page

**Files:**

- Create: `app/Actions/GetBookings/GetBookings.php`
- Modify: `app/Http/Controllers/BookingController.php`
- Modify: `routes/web.php`
- Create: `resources/js/types/booking.ts`
- Modify: `resources/js/types/index.ts`
- Create: `resources/js/components/booking-status-badge.tsx`
- Create: `resources/js/pages/bookings/index.tsx`
- Test: `tests/Feature/GetBookingsTest.php`

**Interfaces:**

- Consumes: `Booking` model (relations `event`, `attendee`), `Event::hasStarted()`,
  `BookingFactory` (state `cancelled()`), `EventFactory` (state `published()`),
  `UserFactory` (state `admin()`), `LocalDateTime`, `Badge`, `show` and `index` from
  `@/routes/events`.
- Produces:
    - Route `bookings.index`: `GET /bookings`. Wayfinder function `index` in
      `@/routes/bookings`.
    - `GetBookings::handle(User $user): array` with the keys `upcoming` and `past`.
      Each one is a list of rows with the keys `reference`, `quantity`, `status`
      (`confirmed` or `cancelled`) and `event`. `event` has the keys `id`, `title`,
      `venue` and `starts_at` (ISO 8601).
    - Inertia page `bookings/index` with the props `upcoming` and `past` of type
      `BookingRow[]`.
    - Types `BookingStatus` and `BookingRow`; component `BookingStatusBadge` with the
      prop `status`.

- [ ] **Step 1: Write the failing tests**

Create the file with `docker compose exec app php artisan make:test --pest GetBookingsTest --no-interaction`,
then replace its content:

```php
<?php

use App\Models\Booking;
use App\Models\Event;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->freezeSecond();

    $this->attendee = User::factory()->create();
});

/**
 * Create a booking of the attendee for a published event with the given title and start
 * time.
 */
function bookingOf(User $attendee, string $title, DateTimeInterface $startsAt, bool $cancelled = false): Booking
{
    $event = Event::factory()->published()->create(['title' => $title, 'starts_at' => $startsAt]);

    $factory = Booking::factory()->for($event)->for($attendee, 'attendee');

    if ($cancelled) {
        $factory = $factory->cancelled();
    }

    return $factory->create();
}

test('a visitor is sent to the login page', function () {
    $this->get(route('bookings.index'))->assertRedirect(route('login'));
});

test('the attendee sees the data of each booking', function () {
    $event = Event::factory()->published()->create([
        'title' => 'Laravel Meetup',
        'venue' => 'Main Hall',
        'starts_at' => now()->addDays(2),
    ]);
    $booking = Booking::factory()->for($event)->for($this->attendee, 'attendee')->create(['quantity' => 3]);

    $this->actingAs($this->attendee)
        ->get(route('bookings.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('bookings/index')
            ->where('upcoming', [[
                'reference' => $booking->reference,
                'quantity' => 3,
                'status' => 'confirmed',
                'event' => [
                    'id' => $event->id,
                    'title' => 'Laravel Meetup',
                    'venue' => 'Main Hall',
                    'starts_at' => now()->addDays(2)->toIso8601String(),
                ],
            ]])
            ->where('past', [])
        );
});

test('upcoming bookings come earliest first and past bookings most recent first', function () {
    bookingOf($this->attendee, 'In ten days', now()->addDays(10));
    bookingOf($this->attendee, 'In two days', now()->addDays(2));
    bookingOf($this->attendee, 'In five days', now()->addDays(5), cancelled: true);
    bookingOf($this->attendee, 'Three days ago', now()->subDays(3));
    bookingOf($this->attendee, 'Now', now());
    bookingOf($this->attendee, 'One day ago', now()->subDay(), cancelled: true);

    $this->actingAs($this->attendee)
        ->get(route('bookings.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('upcoming', fn ($rows) => collect($rows)->pluck('event.title')->all()
                === ['In two days', 'In five days', 'In ten days'])
            ->where('past', fn ($rows) => collect($rows)->pluck('event.title')->all()
                === ['Now', 'One day ago', 'Three days ago'])
        );
});

test('cancelled bookings show with their status', function () {
    bookingOf($this->attendee, 'Kept', now()->addDay());
    bookingOf($this->attendee, 'Dropped', now()->addDays(2), cancelled: true);

    $this->actingAs($this->attendee)
        ->get(route('bookings.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('upcoming', fn ($rows) => collect($rows)->pluck('status', 'event.title')->all()
                === ['Kept' => 'confirmed', 'Dropped' => 'cancelled'])
        );
});

test('BR-B12: a cancelled booking and a new booking for the same event both show', function () {
    $event = Event::factory()->published()->create(['starts_at' => now()->addDay()]);
    $cancelled = Booking::factory()->for($event)->for($this->attendee, 'attendee')->cancelled()->create();
    $confirmed = Booking::factory()->for($event)->for($this->attendee, 'attendee')->create();

    $this->actingAs($this->attendee)
        ->get(route('bookings.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('upcoming', fn ($rows) => collect($rows)->pluck('reference')->all()
                === [$cancelled->reference, $confirmed->reference])
        );
});

test('the page does not show the bookings of other users', function () {
    bookingOf($this->attendee, 'Mine', now()->addDay());
    bookingOf(User::factory()->create(), 'Not mine', now()->addDay());

    $this->actingAs($this->attendee)
        ->get(route('bookings.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('upcoming', fn ($rows) => collect($rows)->pluck('event.title')->all() === ['Mine'])
        );
});

test('the organizer does not see the bookings of their event on this page', function () {
    $organizer = User::factory()->create();
    $event = Event::factory()->for($organizer, 'organizer')->published()->create(['starts_at' => now()->addDay()]);
    Booking::factory()->for($event)->for($this->attendee, 'attendee')->create();

    $this->actingAs($organizer)
        ->get(route('bookings.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('upcoming', [])
            ->where('past', [])
        );
});

test('an admin sees only their own bookings', function () {
    $admin = User::factory()->admin()->create();
    bookingOf($this->attendee, 'Not mine', now()->addDay());

    $this->actingAs($admin)
        ->get(route('bookings.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('upcoming', [])
            ->where('past', [])
        );
});
```

- [ ] **Step 2: Run the tests and check that they fail**

Run: `docker compose exec app php artisan test --compact tests/Feature/GetBookingsTest.php`
Expected: FAIL with `Route [bookings.index] not defined.`

- [ ] **Step 3: Write the Action**

Create `app/Actions/GetBookings/GetBookings.php`:

```php
<?php

namespace App\Actions\GetBookings;

use App\Models\Booking;
use App\Models\User;

class GetBookings
{
    /**
     * Get the bookings of the user, split into upcoming bookings (earliest event first)
     * and past bookings (most recent event first).
     *
     * @return array{upcoming: array<int, array{reference: string, quantity: int, status: string, event: array{id: int, title: string, venue: string, starts_at: string}}>, past: array<int, array{reference: string, quantity: int, status: string, event: array{id: int, title: string, venue: string, starts_at: string}}>}
     */
    public function handle(User $user): array
    {
        $bookings = Booking::query()
            ->select('bookings.*')
            ->join('events', 'events.id', '=', 'bookings.event_id')
            ->whereBelongsTo($user, 'attendee')
            ->orderBy('events.starts_at')
            ->orderBy('bookings.id')
            ->with('event:id,title,venue,starts_at')
            ->get();

        [$past, $upcoming] = $bookings->partition(fn (Booking $booking): bool => $booking->event->hasStarted());

        return [
            'upcoming' => $upcoming->map(fn (Booking $booking): array => $this->row($booking))->values()->all(),
            'past' => $past->reverse()->map(fn (Booking $booking): array => $this->row($booking))->values()->all(),
        ];
    }

    /**
     * Get the data of one row of the table.
     *
     * @return array{reference: string, quantity: int, status: string, event: array{id: int, title: string, venue: string, starts_at: string}}
     */
    private function row(Booking $booking): array
    {
        return [
            'reference' => $booking->reference,
            'quantity' => $booking->quantity,
            'status' => $booking->status->value,
            'event' => [
                'id' => $booking->event->id,
                'title' => $booking->event->title,
                'venue' => $booking->event->venue,
                'starts_at' => $booking->event->starts_at->toIso8601String(),
            ],
        ];
    }
}
```

The join is only for the order. The `event` relation is loaded with a second query.
`whereBelongsTo($user, 'attendee')` filters on `bookings.user_id`; the `select` keeps
the columns of the join out of the `Booking` models.

- [ ] **Step 4: Add the controller method**

In `app/Http/Controllers/BookingController.php`, add the imports
`App\Actions\GetBookings\GetBookings`, `Illuminate\Http\Request` and
`Inertia\Response`, and add this method before `store()`:

```php
    /**
     * Show the bookings of the logged-in user.
     */
    public function index(Request $request, GetBookings $getBookings): Response
    {
        return Inertia::render('bookings/index', $getBookings->handle($request->user()));
    }
```

- [ ] **Step 5: Add the route**

In `routes/web.php`, inside the `auth` group, after the `events.bookings.store` route:

```php
    Route::get('bookings', [BookingController::class, 'index'])->name('bookings.index');
```

- [ ] **Step 6: Add the types**

Create `resources/js/types/booking.ts`:

```ts
export type BookingStatus = 'confirmed' | 'cancelled';

export type BookingRow = {
    reference: string;
    quantity: number;
    status: BookingStatus;
    event: {
        id: number;
        title: string;
        venue: string;
        starts_at: string;
    };
};
```

In `resources/js/types/index.ts`, add this line in alphabetical order (first line):

```ts
export type * from './booking';
```

- [ ] **Step 7: Add the status badge**

Create `resources/js/components/booking-status-badge.tsx`:

```tsx
import { Badge } from '@/components/ui/badge';
import type { BookingStatus } from '@/types';

const labels: Record<BookingStatus, string> = {
    confirmed: 'Confirmed',
    cancelled: 'Cancelled',
};

const variants = {
    confirmed: 'secondary',
    cancelled: 'destructive',
} as const;

export function BookingStatusBadge({ status }: { status: BookingStatus }) {
    return <Badge variant={variants[status]}>{labels[status]}</Badge>;
}
```

- [ ] **Step 8: Add the page**

The feature tests need the page file, because `config/inertia.php` has
`ensure_pages_exist` set to `true`. Generate the Wayfinder files for the new route
first (the TypeScript checks need them):
`docker compose exec app php artisan wayfinder:generate --with-form`

Create `resources/js/pages/bookings/index.tsx`:

```tsx
import { Head, Link, setLayoutProps } from '@inertiajs/react';
import { BookingStatusBadge } from '@/components/booking-status-badge';
import { LocalDateTime } from '@/components/local-date-time';
import { index } from '@/routes/bookings';
import { index as eventsIndex, show } from '@/routes/events';
import type { BookingRow, BreadcrumbItem } from '@/types';

function BookingTable({
    title,
    bookings,
}: {
    title: string;
    bookings: BookingRow[];
}) {
    return (
        <section className="flex flex-col gap-3">
            <h2 className="text-lg font-semibold">{title}</h2>
            {bookings.length === 0 ? (
                <p className="text-sm text-muted-foreground">No bookings.</p>
            ) : (
                <div className="overflow-x-auto rounded-md border">
                    <table className="w-full text-sm">
                        <thead className="bg-muted/50 text-left">
                            <tr>
                                <th className="px-3 py-2 font-medium">Event</th>
                                <th className="px-3 py-2 font-medium">
                                    Starts
                                </th>
                                <th className="px-3 py-2 font-medium">Venue</th>
                                <th className="px-3 py-2 text-right font-medium">
                                    Seats
                                </th>
                                <th className="px-3 py-2 font-medium">
                                    Reference
                                </th>
                                <th className="px-3 py-2 font-medium">
                                    Status
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            {bookings.map((booking) => (
                                <tr
                                    key={booking.reference}
                                    className="border-t"
                                >
                                    <td className="px-3 py-2">
                                        <Link
                                            href={show(booking.event.id)}
                                            className="font-medium underline-offset-4 hover:underline"
                                        >
                                            {booking.event.title}
                                        </Link>
                                    </td>
                                    <td className="px-3 py-2 whitespace-nowrap">
                                        <LocalDateTime
                                            value={booking.event.starts_at}
                                        />
                                    </td>
                                    <td className="px-3 py-2">
                                        {booking.event.venue}
                                    </td>
                                    <td className="px-3 py-2 text-right">
                                        {booking.quantity}
                                    </td>
                                    <td className="px-3 py-2 font-mono whitespace-nowrap">
                                        {booking.reference}
                                    </td>
                                    <td className="px-3 py-2">
                                        <BookingStatusBadge
                                            status={booking.status}
                                        />
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}
        </section>
    );
}

export default function Bookings({
    upcoming,
    past,
}: {
    upcoming: BookingRow[];
    past: BookingRow[];
}) {
    setLayoutProps<{ breadcrumbs: BreadcrumbItem[] }>({
        breadcrumbs: [{ title: 'My bookings', href: index() }],
    });

    const hasBookings = upcoming.length > 0 || past.length > 0;

    return (
        <>
            <Head title="My bookings" />
            <div className="flex min-w-0 flex-1 flex-col gap-6 p-4">
                <h1 className="text-2xl font-semibold">My bookings</h1>

                {hasBookings ? (
                    <>
                        <BookingTable title="Upcoming" bookings={upcoming} />
                        <BookingTable title="Past" bookings={past} />
                    </>
                ) : (
                    <p className="text-sm">
                        You have no bookings yet.{' '}
                        <Link
                            href={eventsIndex()}
                            className="font-medium underline underline-offset-4"
                        >
                            Browse events
                        </Link>
                    </p>
                )}
            </div>
        </>
    );
}
```

- [ ] **Step 9: Run the tests and check that they pass**

Run: `docker compose exec app php artisan test --compact tests/Feature/GetBookingsTest.php`
Expected: PASS (8 tests).

- [ ] **Step 10: Format and run the static checks**

Run: `docker compose exec app vendor/bin/pint --dirty --format agent`
Run: `docker compose exec app vendor/bin/phpstan analyse --memory-limit=1G`
Run: `npm run check:fix`
Run: `npm run types:check`
Expected: no errors.

- [ ] **Step 11: Commit**

```bash
git add app/Actions/GetBookings/GetBookings.php app/Http/Controllers/BookingController.php \
  routes/web.php tests/Feature/GetBookingsTest.php resources/js/types/booking.ts \
  resources/js/types/index.ts resources/js/components/booking-status-badge.tsx \
  resources/js/pages/bookings/index.tsx
git commit -m "feat: add the My bookings page"
```

---

### Task 2: The sidebar link and the browser check

**Files:**

- Modify: `resources/js/components/app-sidebar.tsx`

**Interfaces:**

- Consumes: Wayfinder `index` from `@/routes/bookings` (Task 1).

- [ ] **Step 1: Add the sidebar link**

In `resources/js/components/app-sidebar.tsx`:

- Add `TicketCheck` to the `lucide-react` import (alphabetical order, after `Ticket`).
- Add the import `import { index as bookingsIndex } from '@/routes/bookings';` before
  the `@/routes/events` import.
- In `navItems`, add this item after "My events" and before "Create event":

```tsx
              {
                  title: 'My bookings',
                  href: bookingsIndex(),
                  icon: TicketCheck,
              },
```

- [ ] **Step 2: Run the checks**

Run: `npm run check:fix`
Run: `npm run types:check`
Expected: no errors.

- [ ] **Step 3: The owner checks the page in the browser**

The session has no browser tool. Ask the owner to check, with the dev server running
(`attende@example.com` / `password` has bookings in the dev database):

1. The sidebar shows "My bookings" when logged in, and not when logged out.
2. The page shows the two tables with the right rows, and the event links work.
3. On a phone-wide window, the table scrolls horizontally and the page does not.
4. A user with no bookings (for example a new account) sees "You have no bookings
   yet." and the link to the events page.
5. No hydration warnings in the browser console.

- [ ] **Step 4: Commit**

```bash
git add resources/js/components/app-sidebar.tsx
git commit -m "feat: add the My bookings link to the sidebar"
```

---

### Task 3: README, checks, handoff and plan

**Files:**

- Create: `app/Actions/GetBookings/README.md`
- Modify: `HANDOFF.md`
- Add: `docs/superpowers/plans/2026-10-08-m4-get-bookings.md`

- [ ] **Step 1: Write the `GetBookings` README**

Follow `app/Actions/GetOrganizerEvents/README.md`. Write in Simple English. Explain:

- The page `GET /bookings` shows only the bookings of the logged-in user, confirmed and
  cancelled. Admins and organizers also see only their own bookings on this page. The
  organizer sees the attendees of an event on another page (`GetEventAttendees`).
- The route has only `auth`. It needs no policy, because the query reads only the rows
  with the `user_id` of the user (BR-B13).
- The Action joins `events` to sort by start time, then loads the event of each booking.
  It splits the bookings into "Upcoming" (earliest first) and "Past" (most recent
  first) with `Event::hasStarted()`. A booking for an event that starts now is in
  "Past".
- Each row shows the event title (link), the start time, the venue, the seats, the
  reference and the status.

Two sequence diagrams, no `alt` blocks:

1. "The bookings are shown": Browser → Middleware (check login) → `BookingController`
   → `GetBookings` → PostgreSQL (read the bookings of the user, sorted by the start time
   of the event; read the events of the bookings) → split → 200, Inertia page
   `bookings/index`.
2. "The person is not logged in": Browser → Middleware → redirect to `/login`.

Render the diagrams locally to check them:
`npx -y @mermaid-js/mermaid-cli -i app/Actions/GetBookings/README.md -o <scratch-dir>/get-bookings.md`
Expected: two SVG files and no errors.

- [ ] **Step 2: Format and run all checks**

Run: `docker compose exec app vendor/bin/pint --dirty --format agent`
Run: `npm run check:fix`
Run: `docker compose exec app composer ci:check`
Expected: Pint, PHPStan, `vp check`, `tsc` and all tests pass.

- [ ] **Step 3: Update `HANDOFF.md`**

"Where we are": PR 3 is merged (#32). The current PR is M4 PR 4
(`feat/get-bookings`), with the path of this plan. Add the decisions from the Global
Constraints: the route and its middleware (no policy); Upcoming/Past split by the start
time of the event, with confirmed and cancelled bookings; the columns; no pagination;
admins and organizers see only their own bookings; the sidebar link; the empty state;
the cancel button comes in PR 5.
"Next steps": the owner reviews this PR. Then plan PR 5 (`CancelBooking`).

- [ ] **Step 4: Commit**

```bash
git add app/Actions/GetBookings/README.md HANDOFF.md \
  docs/superpowers/plans/2026-10-08-m4-get-bookings.md
git commit -m "docs: document GetBookings and update handoff"
```
