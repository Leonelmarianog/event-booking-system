# M4 — GetEventAttendees: the Attendee List of an Event

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** The organizer of an event, and any admin, can see the people who hold seats
for the event: name, email, seats, reference and booking time, 50 for each page.

**Architecture:** The new ability `EventPolicy::viewAttendees` allows the organizer and
admins (BR-A2). The route `events.attendees.index` (`GET /events/{event}/attendees`) has
`auth` and `can:viewAttendees,event`. `EventAttendeeController@index` calls the
`GetEventAttendees` Action. The Action reads the confirmed bookings of the event with
their users and paginates them. The React page `organizer/events/attendees` shows a
summary line, the table and the Previous/Next links. The links move from
`events/index.tsx` into a shared `PaginationNav` component. The event page and the
"My events" page link to the new page.

**Tech Stack:** Laravel 13, Inertia 3, React 19, TypeScript, Wayfinder, Pest.

**Spec:** `docs/superpowers/specs/2026-09-28-event-booking-design.md` (sections 5, 6 and
8), `docs/design/business-rules.md` (BR-A2), `docs/design/erd.md` (index
`bookings(event_id, status)`).

## Global Constraints

- `EventPolicy::viewAttendees`: a person who cannot see the event gets 404; the
  organizer and admins are allowed (BR-A2); all other users get 403.
- The route is `GET /events/{event}/attendees`, named `events.attendees.index`, with
  `auth` (not `verified`), `whereNumber('event')` and `can:viewAttendees,event`. The
  page component is `organizer/events/attendees`.
- The list has only confirmed bookings. Cancelled bookings do not show.
- Order: first booked first (`bookings.created_at`), then the booking ID.
- 50 rows for each page, with `withQueryString()`.
- Columns: Name, Email, Seats, Reference, Booked at.
- The summary line is `N attendees · M of C seats booked`. N is the total number of
  confirmed bookings (`attendees.total`, all pages). M is `Event::seatsBooked()`. Use
  "attendee" for 1.
- An event with no confirmed bookings shows "No attendees yet."
- The event page shows an "Attendees" link when `can.viewAttendees` is true. Each row of
  "My events" shows an "Attendees" link.
- The Previous/Next links are one shared component, `PaginationNav`, used by the events
  page and the attendee page.
- The controller calls one Action and contains no queries.
- The Action directory is `app/Actions/GetEventAttendees/` with `GetEventAttendees.php`
  and `README.md`. The README has one sequence diagram for each outcome and no `alt`
  blocks. It is in Simple English.
- Run commands inside the `app` container: `docker compose exec app <command>`. Pint
  `--dirty` does not work in the container (no git); pass the file paths. Do not run
  `migrate:fresh` against the development database.

## Review Focus

1. **People who must not see the list.** Another user gets 403 on a published event and
   404 on a draft. An attendee of a cancelled event can see the event but gets 403 on
   the list. Pinned by tests in Task 1.
2. **Cancelled bookings.** They do not show and do not count in the summary. Pinned by
   a test in Task 1.
3. **Pagination.** With 51 bookings, page 1 has 50 rows, page 2 has 1, and the total is
   51 on both pages. Pinned by a test in Task 1.
4. **The events page after the refactor.** The Previous/Next links of `/events` still
   work. `GetUpcomingEventsTest` covers the data; the owner checks the links in Task 2.
5. **The table on a phone.** Five columns and long emails: the table scrolls
   horizontally and the page does not. Checked by the owner in Task 2.

---

### Task 1: Policy, Action, route and page

**Files:**

- Modify: `app/Policies/EventPolicy.php`
- Create: `app/Actions/GetEventAttendees/GetEventAttendees.php`
- Create: `app/Http/Controllers/EventAttendeeController.php`
- Modify: `routes/web.php`
- Modify: `resources/js/types/booking.ts`
- Create: `resources/js/components/pagination-nav.tsx`
- Modify: `resources/js/pages/events/index.tsx`
- Create: `resources/js/pages/organizer/events/attendees.tsx`
- Test: `tests/Feature/EventPolicyTest.php`
- Test: `tests/Feature/GetEventAttendeesTest.php`

**Interfaces:**

- Consumes: `EventPolicy::view`, `Event::bookings()`, `Event::seatsBooked()`,
  `Booking::attendee()`, `BookingStatus::Confirmed`, the factories (`Event` states
  `published()`, `cancelled()`; `Booking` state `cancelled()`; `User` state `admin()`),
  `Paginated<T>`, `LocalDateTime`, `Button`.
- Produces:
    - `EventPolicy::viewAttendees(User $user, Event $event): Response`.
    - `GetEventAttendees::handle(Event $event): array` with the keys `event` (`id`,
      `title`, `starts_at`, `capacity`, `seats_booked`) and `attendees` (a paginator of
      rows with `reference`, `name`, `email`, `quantity`, `booked_at`).
    - Route `events.attendees.index`; Wayfinder function `index` in
      `@/routes/events/attendees`.
    - Type `AttendeeRow`; component `PaginationNav` with the prop `paginator`
      (`Paginated<unknown>`).

- [ ] **Step 1: Write the failing policy tests**

Add to the end of `tests/Feature/EventPolicyTest.php`:

```php
// View attendees: BR-A2

test('BR-A2: the organizer and admins can see the attendee list', function () {
    $event = Event::factory()->published()->for($this->organizer, 'organizer')->create();

    expect($this->organizer->can('viewAttendees', $event))->toBeTrue()
        ->and($this->admin->can('viewAttendees', $event))->toBeTrue()
        ->and($this->otherUser->can('viewAttendees', $event))->toBeFalse();
});

test('BR-A2: another user gets a 403 for a published event and a 404 for a draft', function () {
    $published = Event::factory()->published()->for($this->organizer, 'organizer')->create();
    $draft = Event::factory()->for($this->organizer, 'organizer')->create();

    $response = Gate::forUser($this->otherUser)->inspect('viewAttendees', $published);

    expect($response->denied())->toBeTrue()
        ->and($response->status())->toBeNull()
        ->and(Gate::forUser($this->otherUser)->inspect('viewAttendees', $draft)->status())->toBe(404);
});

test('BR-A2: an attendee of a cancelled event can see the event but not the attendee list', function () {
    $event = Event::factory()->cancelled()->for($this->organizer, 'organizer')->create();
    Booking::factory()->cancelled()->for($event)->for($this->otherUser, 'attendee')->create();

    $response = Gate::forUser($this->otherUser)->inspect('viewAttendees', $event);

    expect($this->otherUser->can('view', $event))->toBeTrue()
        ->and($response->denied())->toBeTrue()
        ->and($response->status())->toBeNull();
});
```

- [ ] **Step 2: Write the failing page tests**

Create the file with `docker compose exec app php artisan make:test --pest GetEventAttendeesTest --no-interaction`,
then replace its content:

```php
<?php

use App\Models\Booking;
use App\Models\Event;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->freezeSecond();

    $this->organizer = User::factory()->create();
    $this->event = Event::factory()->published()->for($this->organizer, 'organizer')
        ->create(['title' => 'Laravel Meetup', 'capacity' => 50, 'seats_available' => 50]);
});

/**
 * Create a confirmed booking for the event, made the given number of minutes ago. It
 * also takes the seats from the event.
 */
function attendeeBooking(Event $event, string $name, int $minutesAgo, int $quantity = 1): Booking
{
    $event->decrement('seats_available', $quantity);

    return Booking::factory()
        ->for($event)
        ->for(User::factory()->create(['name' => $name, 'email' => strtolower($name).'@example.com']), 'attendee')
        ->create(['quantity' => $quantity, 'created_at' => now()->subMinutes($minutesAgo)]);
}

test('a visitor is sent to the login page', function () {
    $this->get(route('events.attendees.index', $this->event))->assertRedirect(route('login'));
});

test('BR-A2: another user gets a 403', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('events.attendees.index', $this->event))
        ->assertForbidden();
});

test('BR-A2: an admin can see the attendee list of any event', function () {
    $this->actingAs(User::factory()->admin()->create())
        ->get(route('events.attendees.index', $this->event))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('organizer/events/attendees'));
});

test('the organizer sees the event and the data of each attendee', function () {
    $booking = attendeeBooking($this->event, 'Ana', 30, quantity: 3);

    $this->actingAs($this->organizer)
        ->get(route('events.attendees.index', $this->event))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('organizer/events/attendees')
            ->where('event', [
                'id' => $this->event->id,
                'title' => 'Laravel Meetup',
                'starts_at' => $this->event->starts_at->toIso8601String(),
                'capacity' => 50,
                'seats_booked' => 3,
            ])
            ->where('attendees.total', 1)
            ->where('attendees.data', [[
                'reference' => $booking->reference,
                'name' => 'Ana',
                'email' => 'ana@example.com',
                'quantity' => 3,
                'booked_at' => now()->subMinutes(30)->toIso8601String(),
            ]])
        );
});

test('the list has only confirmed bookings, first booked first', function () {
    attendeeBooking($this->event, 'Second', 20);
    attendeeBooking($this->event, 'First', 40);
    Booking::factory()->cancelled()->for($this->event)->create(['created_at' => now()->subHour()]);
    attendeeBooking($this->event, 'Third', 10);

    $this->actingAs($this->organizer)
        ->get(route('events.attendees.index', $this->event))
        ->assertInertia(fn (Assert $page) => $page
            ->where('attendees.total', 3)
            ->where('attendees.data', fn ($rows) => collect($rows)->pluck('name')->all()
                === ['First', 'Second', 'Third'])
        );
});

test('the list has 50 attendees on each page', function () {
    foreach (range(1, 51) as $number) {
        Booking::factory()->for($this->event)->create(['created_at' => now()->subMinutes(100 - $number)]);
    }

    $this->actingAs($this->organizer)
        ->get(route('events.attendees.index', $this->event))
        ->assertInertia(fn (Assert $page) => $page
            ->has('attendees.data', 50)
            ->where('attendees.total', 51)
            ->where('attendees.last_page', 2)
        );

    $this->actingAs($this->organizer)
        ->get(route('events.attendees.index', ['event' => $this->event, 'page' => 2]))
        ->assertInertia(fn (Assert $page) => $page
            ->has('attendees.data', 1)
            ->where('attendees.total', 51)
            ->where('attendees.current_page', 2)
        );
});

test('an event with no bookings has an empty list', function () {
    $this->actingAs($this->organizer)
        ->get(route('events.attendees.index', $this->event))
        ->assertInertia(fn (Assert $page) => $page
            ->where('attendees.total', 0)
            ->where('attendees.data', [])
            ->where('event.seats_booked', 0)
        );
});

test('an event that the user cannot see gives a 404', function () {
    $draft = Event::factory()->for($this->organizer, 'organizer')->create();

    $this->actingAs(User::factory()->create())
        ->get(route('events.attendees.index', $draft))
        ->assertNotFound();
});
```

- [ ] **Step 3: Run the tests and check that they fail**

Run: `docker compose exec app php artisan test --compact tests/Feature/EventPolicyTest.php tests/Feature/GetEventAttendeesTest.php`
Expected: the 3 new policy tests fail (`viewAttendees` is not defined, so `can()` is
false and `inspect()` gives 403 where 404 is expected or the allow checks fail). The 8
page tests fail with `Route [events.attendees.index] not defined.` The old policy tests
pass.

- [ ] **Step 4: Add the policy ability**

In `app/Policies/EventPolicy.php`, add after `cancel()`:

```php
    /**
     * BR-A2. A person who cannot see the event gets a 404, so that hidden events stay
     * unknown.
     */
    public function viewAttendees(User $user, Event $event): Response
    {
        if ($this->view($user, $event)->denied()) {
            return Response::denyAsNotFound();
        }

        return $user->is_admin || $event->isOrganizedBy($user) ? Response::allow() : Response::deny();
    }
```

- [ ] **Step 5: Write the Action**

Create `app/Actions/GetEventAttendees/GetEventAttendees.php`:

```php
<?php

namespace App\Actions\GetEventAttendees;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Event;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class GetEventAttendees
{
    /**
     * Get the event and one page of its confirmed bookings, first booked first (BR-A2).
     * The page number comes from the `page` query parameter.
     *
     * @return array{
     *     event: array{id: int, title: string, starts_at: string, capacity: int, seats_booked: int},
     *     attendees: LengthAwarePaginator<int, array{reference: string, name: string, email: string, quantity: int, booked_at: string}>,
     * }
     */
    public function handle(Event $event): array
    {
        $attendees = $event->bookings()
            ->where('status', BookingStatus::Confirmed)
            ->with('attendee:id,name,email')
            ->orderBy('created_at')
            ->orderBy('id')
            ->paginate(50)
            ->withQueryString()
            ->through(fn (Booking $booking): array => [
                'reference' => $booking->reference,
                'name' => $booking->attendee->name,
                'email' => $booking->attendee->email,
                'quantity' => $booking->quantity,
                'booked_at' => $booking->created_at->toIso8601String(),
            ]);

        return [
            'event' => [
                'id' => $event->id,
                'title' => $event->title,
                'starts_at' => $event->starts_at->toIso8601String(),
                'capacity' => $event->capacity,
                'seats_booked' => $event->seatsBooked(),
            ],
            'attendees' => $attendees,
        ];
    }
}
```

The summary uses `attendees.total`, so the Action needs no separate count. The index
`bookings(event_id, status)` serves the query.

`created_at` is `CarbonImmutable|null` in the model docblock. PHPStan runs at level 7,
which does not check calls on nullable types, and a saved booking always has
`created_at`, so the call needs no null check.

- [ ] **Step 6: Write the controller and the route**

Create `app/Http/Controllers/EventAttendeeController.php` with
`docker compose exec app php artisan make:controller EventAttendeeController --no-interaction`,
then replace its content:

```php
<?php

namespace App\Http\Controllers;

use App\Actions\GetEventAttendees\GetEventAttendees;
use App\Models\Event;
use Inertia\Inertia;
use Inertia\Response;

class EventAttendeeController extends Controller
{
    /**
     * Show the attendee list of the event.
     */
    public function index(Event $event, GetEventAttendees $getEventAttendees): Response
    {
        return Inertia::render('organizer/events/attendees', $getEventAttendees->handle($event));
    }
}
```

In `routes/web.php`, add the import `App\Http\Controllers\EventAttendeeController`
(alphabetical order, after `BookingController`) and add inside the `auth` group, after
the `events.bookings.store` route:

```php
    Route::get('events/{event}/attendees', [EventAttendeeController::class, 'index'])
        ->whereNumber('event')
        ->name('events.attendees.index')
        ->can('viewAttendees', 'event');
```

- [ ] **Step 7: Add the type and the `PaginationNav` component**

Add to the end of `resources/js/types/booking.ts`:

```ts
export type AttendeeRow = {
    reference: string;
    name: string;
    email: string;
    quantity: number;
    booked_at: string;
};
```

Create `resources/js/components/pagination-nav.tsx`:

```tsx
import { Link } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import type { Paginated } from '@/types';

function PageLink({ href, label }: { href: string | null; label: string }) {
    if (href === null) {
        return (
            <Button variant="outline" size="sm" disabled>
                {label}
            </Button>
        );
    }

    return (
        <Button asChild variant="outline" size="sm">
            <Link href={href}>{label}</Link>
        </Button>
    );
}

/**
 * The Previous and Next links of a paginated list. It shows nothing when the list has
 * only one page.
 */
export function PaginationNav({
    paginator,
}: {
    paginator: Paginated<unknown>;
}) {
    if (paginator.last_page <= 1) {
        return null;
    }

    return (
        <nav
            aria-label="Pages"
            className="flex items-center justify-between gap-4"
        >
            <PageLink href={paginator.prev_page_url} label="Previous" />
            <span className="text-sm text-muted-foreground">
                Page {paginator.current_page} of {paginator.last_page}
            </span>
            <PageLink href={paginator.next_page_url} label="Next" />
        </nav>
    );
}
```

In `resources/js/pages/events/index.tsx`:

- Remove the local `PageLink` function.
- Replace the whole `{events.last_page > 1 && ( <nav ...> ... </nav> )}` block with
  `<PaginationNav paginator={events} />`.
- Add the import `import { PaginationNav } from '@/components/pagination-nav';`.
- Remove the imports that are no longer used (`Button` and `Link`, only if nothing else
  in the file uses them; `npm run check:fix` and `tsc` report unused imports).

- [ ] **Step 8: Add the page**

Generate the Wayfinder files for the new route first:
`docker compose exec app php artisan wayfinder:generate --with-form`

Create `resources/js/pages/organizer/events/attendees.tsx`:

```tsx
import { Head, Link, setLayoutProps } from '@inertiajs/react';
import { LocalDateTime } from '@/components/local-date-time';
import { PaginationNav } from '@/components/pagination-nav';
import { index } from '@/routes/events/attendees';
import { show } from '@/routes/events';
import type { AttendeeRow, BreadcrumbItem, Paginated } from '@/types';

type AttendeeEvent = {
    id: number;
    title: string;
    starts_at: string;
    capacity: number;
    seats_booked: number;
};

export default function EventAttendees({
    event,
    attendees,
}: {
    event: AttendeeEvent;
    attendees: Paginated<AttendeeRow>;
}) {
    setLayoutProps<{ breadcrumbs: BreadcrumbItem[] }>({
        breadcrumbs: [
            { title: event.title, href: show(event.id) },
            { title: 'Attendees', href: index(event.id) },
        ],
    });

    return (
        <>
            <Head title={`Attendees of ${event.title}`} />
            <div className="flex min-w-0 flex-1 flex-col gap-6 p-4">
                <div className="flex flex-col gap-1">
                    <h1 className="text-2xl font-semibold break-words">
                        Attendees
                    </h1>
                    <p className="text-sm">
                        <Link
                            href={show(event.id)}
                            className="font-medium underline-offset-4 hover:underline"
                        >
                            {event.title}
                        </Link>
                        {' · '}
                        <LocalDateTime value={event.starts_at} />
                    </p>
                    <p className="text-sm text-muted-foreground">
                        {attendees.total}{' '}
                        {attendees.total === 1 ? 'attendee' : 'attendees'} ·{' '}
                        {event.seats_booked} of {event.capacity} seats booked
                    </p>
                </div>

                {attendees.data.length === 0 ? (
                    <p className="text-sm text-muted-foreground">
                        No attendees yet.
                    </p>
                ) : (
                    <div className="overflow-x-auto rounded-md border">
                        <table className="w-full text-sm">
                            <thead className="bg-muted/50 text-left">
                                <tr>
                                    <th className="px-3 py-2 font-medium">
                                        Name
                                    </th>
                                    <th className="px-3 py-2 font-medium">
                                        Email
                                    </th>
                                    <th className="px-3 py-2 text-right font-medium">
                                        Seats
                                    </th>
                                    <th className="px-3 py-2 font-medium">
                                        Reference
                                    </th>
                                    <th className="px-3 py-2 font-medium">
                                        Booked at
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                {attendees.data.map((attendee) => (
                                    <tr
                                        key={attendee.reference}
                                        className="border-t"
                                    >
                                        <td className="px-3 py-2">
                                            {attendee.name}
                                        </td>
                                        <td className="px-3 py-2">
                                            {attendee.email}
                                        </td>
                                        <td className="px-3 py-2 text-right">
                                            {attendee.quantity}
                                        </td>
                                        <td className="px-3 py-2 font-mono whitespace-nowrap">
                                            {attendee.reference}
                                        </td>
                                        <td className="px-3 py-2 whitespace-nowrap">
                                            <LocalDateTime
                                                value={attendee.booked_at}
                                            />
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}

                <PaginationNav paginator={attendees} />
            </div>
        </>
    );
}
```

- [ ] **Step 9: Run the tests and check that they pass**

Run: `docker compose exec app php artisan test --compact tests/Feature/EventPolicyTest.php tests/Feature/GetEventAttendeesTest.php tests/Feature/GetUpcomingEventsTest.php`
Expected: PASS.

- [ ] **Step 10: Format, analyse and commit**

Run: `docker compose exec app vendor/bin/pint --format agent app/Policies/EventPolicy.php app/Actions/GetEventAttendees app/Http/Controllers/EventAttendeeController.php routes/web.php tests/Feature/EventPolicyTest.php tests/Feature/GetEventAttendeesTest.php`
Run: `docker compose exec app vendor/bin/phpstan analyse --memory-limit=1G --no-progress`
Run: `npm run check:fix`
Run: `npm run types:check`
Expected: no errors.

```bash
git add app/Policies/EventPolicy.php app/Actions/GetEventAttendees/GetEventAttendees.php \
  app/Http/Controllers/EventAttendeeController.php routes/web.php \
  resources/js/types/booking.ts resources/js/components/pagination-nav.tsx \
  resources/js/pages/events/index.tsx resources/js/pages/organizer/events/attendees.tsx \
  tests/Feature/EventPolicyTest.php tests/Feature/GetEventAttendeesTest.php
git commit -m "feat: add the attendee list of an event"
```

---

### Task 2: Links to the attendee list

**Files:**

- Modify: `app/Http/Controllers/EventController.php`
- Modify: `resources/js/pages/events/show.tsx`
- Modify: `resources/js/pages/organizer/events/index.tsx`
- Test: `tests/Feature/GetEventTest.php`

**Interfaces:**

- Consumes: `EventPolicy::viewAttendees` and Wayfinder `index` from
  `@/routes/events/attendees` (Task 1).
- Produces: the prop `can.viewAttendees: boolean` on `events/show`.

- [ ] **Step 1: Write the failing test**

In `tests/Feature/GetEventTest.php`, add after the test
"BR-E4: other users and visitors do not see the edit link":

```php
test('BR-A2: the organizer and admins see the attendees link, other users and visitors do not', function () {
    $event = Event::factory()->published()->for($this->organizer, 'organizer')->create();

    $this->actingAs($this->organizer)
        ->get(route('events.show', $event))
        ->assertInertia(fn (Assert $page) => $page->where('can.viewAttendees', true));

    $this->actingAs($this->admin)
        ->get(route('events.show', $event))
        ->assertInertia(fn (Assert $page) => $page->where('can.viewAttendees', true));

    $this->actingAs($this->otherUser)
        ->get(route('events.show', $event))
        ->assertInertia(fn (Assert $page) => $page->where('can.viewAttendees', false));

    auth()->logout();

    $this->get(route('events.show', $event))
        ->assertInertia(fn (Assert $page) => $page->where('can.viewAttendees', false));
});
```

- [ ] **Step 2: Run the test and check that it fails**

Run: `docker compose exec app php artisan test --compact tests/Feature/GetEventTest.php`
Expected: FAIL in the new test (`can.viewAttendees` does not exist).

- [ ] **Step 3: Add the prop**

In `app/Http/Controllers/EventController.php`, in `show()`, add this line after the
`'delete'` line of the `can` array:

```php
                'viewAttendees' => $request->user()?->can('viewAttendees', $event) ?? false,
```

- [ ] **Step 4: Run the test and check that it passes**

Run: `docker compose exec app php artisan test --compact tests/Feature/GetEventTest.php`
Expected: PASS.

- [ ] **Step 5: Add the links**

In `resources/js/pages/events/show.tsx`:

- Add the import `import { index as attendees } from '@/routes/events/attendees';`.
- Change the `can` prop type to
  `{ update: boolean; publish: boolean; delete: boolean; viewAttendees: boolean }`.
- Change the condition of the button group to
  `(can.publish || can.update || can.delete || can.viewAttendees)`.
- Add this link as the first item in the button group, before the Edit link (`Users`
  is already imported from `lucide-react`):

```tsx
{
    can.viewAttendees && (
        <Button asChild variant="outline" size="sm">
            <Link href={attendees(event.id)}>
                <Users />
                Attendees
            </Link>
        </Button>
    );
}
```

In `resources/js/pages/organizer/events/index.tsx`:

- Add the import `import { index as attendees } from '@/routes/events/attendees';`.
- Replace the content of the actions cell (the `<td className="px-3 py-2 text-right">`
  with the Edit link) with:

```tsx
<td className="px-3 py-2 text-right whitespace-nowrap">
    <Link
        href={attendees(event.id)}
        className="font-medium underline-offset-4 hover:underline"
    >
        Attendees
    </Link>
    {event.can_update && (
        <Link
            href={edit(event.id)}
            className="ml-4 font-medium underline-offset-4 hover:underline"
        >
            Edit
        </Link>
    )}
</td>
```

- [ ] **Step 6: Run the checks**

Run: `docker compose exec app vendor/bin/pint --format agent app/Http/Controllers/EventController.php tests/Feature/GetEventTest.php`
Run: `npm run check:fix`
Run: `npm run types:check`
Expected: no errors.

- [ ] **Step 7: Commit**

```bash
git add app/Http/Controllers/EventController.php resources/js/pages/events/show.tsx \
  resources/js/pages/organizer/events/index.tsx tests/Feature/GetEventTest.php
git commit -m "feat: link to the attendee list from the event page and My events"
```

- [ ] **Step 8: The owner checks the pages in the browser**

The session has no browser tool. Ask the owner to check after Task 3, with the dev
server running (`organizer@example.com` / `password` organizes events with bookings):

1. The event page of an own event shows "Attendees"; another user does not see it.
2. "My events" shows "Attendees" on each row, and "Edit" where it was before.
3. The attendee page shows the summary line, the table and the empty state for an
   event with no bookings.
4. `/events` still shows the Previous/Next links when there are more than 12 events
   (or check that nothing changed when there is only one page).
5. At phone width, the table scrolls horizontally and the page does not.
6. No hydration warnings in the browser console.

---

### Task 3: README, checks, handoff and plan

**Files:**

- Create: `app/Actions/GetEventAttendees/README.md`
- Modify: `app/Actions/GetEvent/README.md`
- Modify: `app/Actions/GetBookings/README.md`
- Modify: `HANDOFF.md`
- Add: `docs/superpowers/plans/2026-10-08-m4-event-attendees.md`

- [ ] **Step 1: Write the `GetEventAttendees` README**

Follow `app/Actions/GetOrganizerEvents/README.md`. Write in Simple English. Explain:

- The page `GET /events/{event}/attendees` shows the people who hold seats for the
  event. The organizer and admins can open it (BR-A2).
- The route has `auth` and `EventPolicy::viewAttendees`. A person who cannot see the
  event gets 404; other users get 403.
- The Action reads only confirmed bookings, with the name and email of each user, first
  booked first, 50 for each page. The summary counts all confirmed bookings, not only
  the page.
- The event page and "My events" link to this page.

Four sequence diagrams, no `alt` blocks:

1. "The attendees are shown": Browser → Middleware (check login, read the event,
   `viewAttendees` allows) → `EventAttendeeController` → `GetEventAttendees` →
   PostgreSQL (count and read one page of the confirmed bookings; read their users) →
   200, Inertia page `organizer/events/attendees`.
2. "The person is not logged in": redirect to `/login`.
3. "The person cannot see the event": the policy denies as not found → 404.
4. "The person is not the organizer or an admin": the policy denies → 403.

In `app/Actions/GetEvent/README.md`, add that the page gets `can.viewAttendees` for the
"Attendees" link. In `app/Actions/GetBookings/README.md`, change "A later PR adds a page
where the organizer of an event sees its attendees (`GetEventAttendees`)" to say that
the organizer sees them on the attendee page (`GetEventAttendees`).

Render the diagrams locally to check them:
`npx -y @mermaid-js/mermaid-cli -i app/Actions/GetEventAttendees/README.md -o <scratch-dir>/get-event-attendees.md`
Expected: four SVG files and no errors.

- [ ] **Step 2: Format and run all checks**

Run: `npm run check:fix`
Run: `docker compose exec app composer ci:check`
Expected: Pint, PHPStan, `vp check`, `tsc` and all tests pass.

- [ ] **Step 3: Update `HANDOFF.md`**

"Where we are": PR 5 is merged (#34). The current PR is M4 PR 6
(`feat/event-attendees`), with the path of this plan. Write that it is open as a PR and
waits for the merge (no PR number, so that the branch needs only one push). Replace the
PR 5 notes at the top with the decisions from the Global Constraints: the policy and
its 404/403; the route and the page name; confirmed bookings only; the order; 50 for
each page; the columns and the summary; the links; `PaginationNav`. Keep the PR 5 notes
under a "PR 5 notes:" heading, shortened, and remove the "PR 4 notes" if the list
grows long (the READMEs keep the details).
"Next steps": the owner reviews this PR. Then plan PR 7 (`CancelEvent`). Add the
review advice from PR 5: `CancelEvent` must lock the event row first, then its
bookings.

- [ ] **Step 4: Commit**

```bash
git add app/Actions/GetEventAttendees/README.md app/Actions/GetEvent/README.md \
  app/Actions/GetBookings/README.md HANDOFF.md \
  docs/superpowers/plans/2026-10-08-m4-event-attendees.md
git commit -m "docs: document GetEventAttendees and update handoff"
```
