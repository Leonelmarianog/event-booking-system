# M4 — CancelEvent: the Organizer or an Admin Cancels an Event

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** The organizer of an event, or an admin, can cancel a draft or published event
before it starts. All confirmed bookings of the event are cancelled, and their seats go
back to the event. Attendees see "Event cancelled" on their bookings.

**Architecture:** A migration adds `events.cancelled_at`. `Event::cancel()` checks the
rules (BR-E12). The `CancelEvent` Action locks the event row, calls `cancel()`, then
locks the confirmed bookings and calls `Booking::cancel()` on each (BR-E13), in one
transaction. The route `POST /events/{event}/cancellation` has `auth`,
`throttle:event-writes` and `can:cancel,event` (BR-E11, BR-A1).
`EventCancellationController@store` redirects to the event page with a toast. The event
page shows a `CancelEventDialog`; "My bookings" shows an "Event cancelled" badge.

**Tech Stack:** Laravel 13, Inertia 3, React 19, TypeScript, Wayfinder, Pest.

**Spec:** `docs/superpowers/specs/2026-09-28-event-booking-design.md` (sections 4 to
8), `docs/design/business-rules.md` (BR-E11 to BR-E13, BR-A1, the state diagrams),
`docs/design/erd.md` (`events.cancelled_at`).

## Global Constraints

- The migration adds `cancelled_at` (`timestampTz`, nullable) to `events`. Do not run
  `migrate:fresh` on the development database; `php artisan migrate` is enough.
- `Event::cancel()` checks in this order: the status allows the change
  (`EventStatus::canTransitionTo(Cancelled)`, else
  `InvalidStateTransition::cannotCancel`), then the event has not started (else
  `EventHasStarted::cannotCancel`). Then it sets `status` and `cancelled_at`. It saves
  nothing.
- `Event::canBeCancelled()`: the same checks, with no exception.
- `EventPolicy::cancel` returns a `Response`: 404 when the person cannot see the event;
  allowed for the organizer and admins (BR-E11, BR-A1); 403 for other users.
- The Action locks the event row first, then the confirmed bookings of the event (the
  same order as `ReserveSeats` and `CancelBooking`). It cancels each booking with
  `Booking::cancel()`, so the seats go back to the event (BR-B11). It returns the
  number of cancelled bookings.
- The route is `POST /events/{event}/cancellation`, named `events.cancellation.store`,
  with `auth` (not `verified`), `whereNumber('event')`, `throttle:event-writes` and
  `can:cancel,event`.
- Success: redirect to `events.show` with the toast `Event cancelled.` (no bookings),
  `Event cancelled. 1 booking was cancelled.` or
  `Event cancelled. :count bookings were cancelled.`
- Error messages:
    - `Only a draft or published event can be cancelled. This event is cancelled.`
    - `The event has started, so it cannot be cancelled.`
- `can.cancel` on the event page = the policy and `Event::canBeCancelled()`.
- The dialog title is "Cancel this event?", the text is "All confirmed bookings are
  cancelled. You cannot undo this.", and the buttons are "Keep event" (with
  `type="button"`) and "Cancel event". The trigger says "Cancel event".
- Each "My bookings" row gets `event_cancelled: bool`. When it is true, the status
  badge says "Event cancelled".
- The `EventCancelled` emails come in M5.
- Run commands inside the `app` container: `docker compose exec app <command>`. Pint
  `--dirty` does not work in the container (no git); pass the file paths. `vp check
--fix` also formats the code blocks of this plan; copy JSX from the plan with care.

## Review Focus

1. **A booking request during the cancel.** `ReserveSeats` waits for the event lock,
   then reads a cancelled event and gets "not bookable". Pinned by the test "booking
   after the cancel fails" in Task 2 (sequential; the lock order is the same as in
   `ReserveSeats`).
2. **Bookings that were already cancelled.** They keep their `cancelled_at`, and their
   seats are not given back again. Pinned by a test in Task 2.
3. **The seat counter after the cancel.** `seats_available` equals the capacity, so the
   attendee summary and "My events" show 0 booked seats. Pinned by a test in Task 2.
4. **Another user on a draft.** The policy gives 404, not 403, so the draft stays
   unknown. Pinned by a test in Task 2.
5. **Started and cancelled events.** No cancel button, and a direct request gets an
   error toast. Pinned by tests in Tasks 2 and 3.

---

### Task 1: Migration and cancel rules on the model

**Files:**

- Create: `database/migrations/<timestamp>_add_cancelled_at_to_events_table.php`
- Modify: `app/Models/Event.php`
- Modify: `database/factories/EventFactory.php`
- Modify: `app/Exceptions/Domain/InvalidStateTransition.php`
- Modify: `app/Exceptions/Domain/EventHasStarted.php`
- Test: `tests/Feature/EventModelTest.php`

**Interfaces:**

- Produces:
    - Column `events.cancelled_at`; cast `datetime`; `@property CarbonImmutable|null $cancelled_at`.
    - `Event::canBeCancelled(): bool` and `Event::cancel(): void` (throws
      `InvalidStateTransition`, `EventHasStarted`).
    - `InvalidStateTransition::cannotCancel(EventStatus $status): self`.
    - `EventHasStarted::cannotCancel(): self`.
    - The factory state `cancelled()` also sets `cancelled_at`.

- [ ] **Step 1: Write the failing tests**

Add to the end of `tests/Feature/EventModelTest.php` (add the imports
`App\Exceptions\Domain\EventHasStarted` and `App\Exceptions\Domain\InvalidStateTransition`
if the file does not have them yet):

```php
test('BR-E12: a draft or published event can be cancelled', function (string $state) {
    $this->freezeSecond();
    $event = $state === 'draft' ? Event::factory()->create() : Event::factory()->published()->create();

    $event->cancel();
    $event->save();

    $event = $event->fresh();

    expect($event->isCancelled())->toBeTrue()
        ->and($event->cancelled_at->equalTo(now()))->toBeTrue();
})->with(['draft', 'published']);

test('BR-E12: a cancelled event cannot be cancelled again', function () {
    $event = Event::factory()->cancelled()->create();

    expect(fn () => $event->cancel())
        ->toThrow(InvalidStateTransition::class, 'Only a draft or published event can be cancelled. This event is cancelled.');
});

test('BR-E12: a started event cannot be cancelled', function () {
    $event = Event::factory()->published()->started()->create();

    expect(fn () => $event->cancel())
        ->toThrow(EventHasStarted::class, 'The event has started, so it cannot be cancelled.');

    expect($event->isPublished())->toBeTrue()
        ->and($event->cancelled_at)->toBeNull();
});

test('BR-E12: only a draft or published event that has not started can be cancelled', function () {
    expect(Event::factory()->create()->canBeCancelled())->toBeTrue()
        ->and(Event::factory()->published()->create()->canBeCancelled())->toBeTrue()
        ->and(Event::factory()->cancelled()->create()->canBeCancelled())->toBeFalse()
        ->and(Event::factory()->published()->started()->create()->canBeCancelled())->toBeFalse();
});

test('the cancelled factory state sets the cancel time', function () {
    expect(Event::factory()->cancelled()->create()->fresh()->cancelled_at)->not->toBeNull();
});
```

- [ ] **Step 2: Run the tests and check that they fail**

Run: `docker compose exec app php artisan test --compact tests/Feature/EventModelTest.php`
Expected: FAIL. The new tests fail with `Call to undefined method` (`cancel`,
`canBeCancelled`) or with an SQL error about the missing `cancelled_at` column.

- [ ] **Step 3: Add the migration**

Run: `docker compose exec app php artisan make:migration add_cancelled_at_to_events_table --table=events --no-interaction`

Replace the bodies of `up()` and `down()` with:

```php
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->timestampTz('cancelled_at')->nullable()->after('published_at');
        });
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dropColumn('cancelled_at');
        });
    }
```

Keep the docblocks that `make:migration` writes.

Run: `docker compose exec app php artisan migrate --no-interaction`
Expected: the migration runs on the development database. (The tests use their own
database and run the migrations themselves.)

- [ ] **Step 4: Add the exceptions**

In `app/Exceptions/Domain/InvalidStateTransition.php`, add after `cannotPublish()`:

```php
    /**
     * BR-E12: only a draft or published event can be cancelled.
     */
    public static function cannotCancel(EventStatus $status): self
    {
        return new self(__('Only a draft or published event can be cancelled. This event is :status.', [
            'status' => $status->value,
        ]));
    }
```

In `app/Exceptions/Domain/EventHasStarted.php`, add after `cannotPublish()`:

```php
    /**
     * BR-E12: only an event that has not started can be cancelled.
     */
    public static function cannotCancel(): self
    {
        return new self(__('The event has started, so it cannot be cancelled.'));
    }
```

- [ ] **Step 5: Add the model changes**

In `app/Models/Event.php`:

- Add `@property CarbonImmutable|null $cancelled_at` after the `published_at` line of the
  class docblock.
- Add `'cancelled_at' => 'datetime',` after `'published_at' => 'datetime',` in
  `casts()`.
- Add these methods after `publish()`:

```php
    /**
     * Whether the event can be cancelled now (BR-E12). The page uses it with the policy
     * to show the cancel button.
     */
    public function canBeCancelled(): bool
    {
        return $this->status->canTransitionTo(EventStatus::Cancelled) && ! $this->hasStarted();
    }

    /**
     * Cancel the event (BR-E12). It does not cancel the bookings and does not save the
     * event; the CancelEvent Action does both.
     *
     * @throws InvalidStateTransition
     * @throws EventHasStarted
     */
    public function cancel(): void
    {
        if (! $this->status->canTransitionTo(EventStatus::Cancelled)) {
            throw InvalidStateTransition::cannotCancel($this->status);
        }

        if ($this->hasStarted()) {
            throw EventHasStarted::cannotCancel();
        }

        $this->status = EventStatus::Cancelled;
        $this->cancelled_at = now();
    }
```

In `database/factories/EventFactory.php`, change the `cancelled()` state to:

```php
        return $this->state(fn (array $attributes) => [
            'status' => EventStatus::Cancelled,
            'cancelled_at' => now(),
        ]);
```

- [ ] **Step 6: Run the tests and check that they pass**

Run: `docker compose exec app php artisan test --compact tests/Feature/EventModelTest.php tests/Feature/EventsTableTest.php tests/Feature/EventPolicyTest.php`
Expected: PASS.

- [ ] **Step 7: Format, analyse and commit**

Run: `docker compose exec app vendor/bin/pint --format agent database/migrations app/Models/Event.php database/factories/EventFactory.php app/Exceptions/Domain tests/Feature/EventModelTest.php`
Run: `docker compose exec app vendor/bin/phpstan analyse --memory-limit=1G --no-progress`
Expected: no errors.

```bash
git add database/migrations/*_add_cancelled_at_to_events_table.php app/Models/Event.php \
  database/factories/EventFactory.php app/Exceptions/Domain/InvalidStateTransition.php \
  app/Exceptions/Domain/EventHasStarted.php tests/Feature/EventModelTest.php
git commit -m "feat: add the event cancel rules to the model"
```

---

### Task 2: Policy, `CancelEvent` Action, route and controller

**Files:**

- Modify: `app/Policies/EventPolicy.php`
- Create: `app/Actions/CancelEvent/CancelEvent.php`
- Create: `app/Http/Controllers/EventCancellationController.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/EventPolicyTest.php`
- Test: `tests/Feature/CancelEventTest.php`

**Interfaces:**

- Consumes: `Event::cancel()` (Task 1), `Booking::cancel()`, `BookingStatus::Confirmed`,
  the `event-writes` rate limiter.
- Produces:
    - `EventPolicy::cancel(User $user, Event $event): Response`.
    - `CancelEvent::handle(Event $event): int`.
    - Route `events.cancellation.store`; Wayfinder function `store` in
      `@/routes/events/cancellation`.

- [ ] **Step 1: Write the failing policy test**

In `tests/Feature/EventPolicyTest.php`, add after the test
"BR-E11: an admin can cancel any event":

```php
test('BR-E11: another user gets a 403 for a published event and a 404 for a draft', function () {
    $published = Event::factory()->published()->for($this->organizer, 'organizer')->create();
    $draft = Event::factory()->for($this->organizer, 'organizer')->create();

    $response = Gate::forUser($this->otherUser)->inspect('cancel', $published);

    expect($response->denied())->toBeTrue()
        ->and($response->status())->toBeNull()
        ->and(Gate::forUser($this->otherUser)->inspect('cancel', $draft)->status())->toBe(404);
});
```

- [ ] **Step 2: Write the failing feature tests**

Create the file with `docker compose exec app php artisan make:test --pest CancelEventTest --no-interaction`,
then replace its content:

```php
<?php

use App\Models\Booking;
use App\Models\Event;
use App\Models\User;

beforeEach(function () {
    $this->freezeSecond();

    $this->organizer = User::factory()->create();
    $this->event = Event::factory()->published()->for($this->organizer, 'organizer')
        ->create(['capacity' => 10, 'seats_available' => 5]);

    $this->first = Booking::factory()->for($this->event)->create(['quantity' => 2]);
    $this->second = Booking::factory()->for($this->event)->create(['quantity' => 3]);
    $this->earlierCancel = Booking::factory()->cancelled()->for($this->event)
        ->create(['quantity' => 4, 'cancelled_at' => now()->subDay()]);
});

test('BR-E13: the organizer cancels the event and all confirmed bookings are cancelled', function () {
    $this->actingAs($this->organizer)
        ->post(route('events.cancellation.store', $this->event))
        ->assertRedirect(route('events.show', $this->event))
        ->assertInertiaFlash('toast', [
            'type' => 'success',
            'message' => 'Event cancelled. 2 bookings were cancelled.',
        ]);

    $event = $this->event->fresh();

    expect($event->isCancelled())->toBeTrue()
        ->and($event->cancelled_at->equalTo(now()))->toBeTrue()
        ->and($this->first->fresh()->isCancelled())->toBeTrue()
        ->and($this->first->fresh()->cancelled_at->equalTo(now()))->toBeTrue()
        ->and($this->second->fresh()->isCancelled())->toBeTrue();
});

test('BR-B11: the seats of the cancelled bookings go back to the event', function () {
    $this->actingAs($this->organizer)->post(route('events.cancellation.store', $this->event));

    expect($this->event->fresh()->seats_available)->toBe(10);
});

test('a booking that was already cancelled keeps its cancel time', function () {
    $this->actingAs($this->organizer)->post(route('events.cancellation.store', $this->event));

    expect($this->earlierCancel->fresh()->cancelled_at->equalTo(now()->subDay()))->toBeTrue();
});

test('the toast says "booking" for one booking and nothing for no bookings', function () {
    $one = Event::factory()->published()->for($this->organizer, 'organizer')->create();
    Booking::factory()->for($one)->create();
    $none = Event::factory()->for($this->organizer, 'organizer')->create();

    $this->actingAs($this->organizer)
        ->post(route('events.cancellation.store', $one))
        ->assertInertiaFlash('toast.message', 'Event cancelled. 1 booking was cancelled.');

    $this->actingAs($this->organizer)
        ->post(route('events.cancellation.store', $none))
        ->assertInertiaFlash('toast.message', 'Event cancelled.');

    expect($none->fresh()->isCancelled())->toBeTrue();
});

test('BR-A1: an admin can cancel the event of another user', function () {
    $this->actingAs(User::factory()->admin()->create())
        ->post(route('events.cancellation.store', $this->event))
        ->assertRedirect(route('events.show', $this->event));

    expect($this->event->fresh()->isCancelled())->toBeTrue();
});

test('a visitor is sent to the login page', function () {
    $this->post(route('events.cancellation.store', $this->event))
        ->assertRedirect(route('login'));

    expect($this->event->fresh()->isPublished())->toBeTrue();
});

test('BR-E11: another user gets a 403', function () {
    $this->actingAs(User::factory()->create())
        ->post(route('events.cancellation.store', $this->event))
        ->assertForbidden();

    expect($this->event->fresh()->isPublished())->toBeTrue();
});

test('BR-E12: a started event cannot be cancelled', function () {
    $this->event->forceFill(['starts_at' => now()->subHour()])->save();

    $this->actingAs($this->organizer)
        ->from(route('events.show', $this->event))
        ->post(route('events.cancellation.store', $this->event))
        ->assertRedirect(route('events.show', $this->event))
        ->assertInertiaFlash('toast', [
            'type' => 'error',
            'message' => 'The event has started, so it cannot be cancelled.',
        ]);

    expect($this->event->fresh()->isPublished())->toBeTrue()
        ->and($this->first->fresh()->isConfirmed())->toBeTrue()
        ->and($this->event->fresh()->seats_available)->toBe(5);
});

test('BR-E12: a cancelled event cannot be cancelled again', function () {
    $this->actingAs($this->organizer)->post(route('events.cancellation.store', $this->event));

    $this->actingAs($this->organizer)
        ->from(route('events.show', $this->event))
        ->post(route('events.cancellation.store', $this->event))
        ->assertInertiaFlash('toast', [
            'type' => 'error',
            'message' => 'Only a draft or published event can be cancelled. This event is cancelled.',
        ]);
});

test('BR-B2: booking after the cancel fails', function () {
    $this->actingAs($this->organizer)->post(route('events.cancellation.store', $this->event));
    $attendee = $this->first->attendee;

    $this->actingAs($attendee)
        ->from(route('events.show', $this->event))
        ->post(route('events.bookings.store', $this->event), ['quantity' => 1])
        ->assertRedirect(route('events.show', $this->event))
        ->assertInertiaFlash('toast.type', 'error');

    expect(Booking::where('event_id', $this->event->id)->where('status', 'confirmed')->count())->toBe(0);
});

test('the cancel uses the event-writes rate limit', function () {
    foreach (range(1, 20) as $attempt) {
        $this->actingAs($this->organizer)->post(route('events.cancellation.store', $this->event));
    }

    $this->actingAs($this->organizer)
        ->post(route('events.cancellation.store', $this->event))
        ->assertTooManyRequests();
});
```

- [ ] **Step 3: Run the tests and check that they fail**

Run: `docker compose exec app php artisan test --compact tests/Feature/EventPolicyTest.php tests/Feature/CancelEventTest.php`
Expected: the new policy test fails (the draft gives 403, not 404). The feature tests
fail with `Route [events.cancellation.store] not defined.`

- [ ] **Step 4: Change the policy ability**

In `app/Policies/EventPolicy.php`, replace `cancel()` with:

```php
    /**
     * BR-E11 and BR-A1. The model checks the state of the event (BR-E12). A person who
     * cannot see the event gets a 404, so that hidden events stay unknown.
     */
    public function cancel(User $user, Event $event): Response
    {
        if ($this->view($user, $event)->denied()) {
            return Response::denyAsNotFound();
        }

        return $user->is_admin || $event->isOrganizedBy($user) ? Response::allow() : Response::deny();
    }
```

- [ ] **Step 5: Write the Action**

Create `app/Actions/CancelEvent/CancelEvent.php`:

```php
<?php

namespace App\Actions\CancelEvent;

use App\Enums\BookingStatus;
use App\Exceptions\Domain\EventHasStarted;
use App\Exceptions\Domain\InvalidStateTransition;
use App\Models\Booking;
use App\Models\Event;
use Illuminate\Support\Facades\DB;

class CancelEvent
{
    /**
     * Cancel the event and all its confirmed bookings (BR-E12, BR-E13). The seats of the
     * bookings go back to the event (BR-B11). The Action locks the event row first and
     * the bookings second, the same order as ReserveSeats and CancelBooking. It returns
     * the number of cancelled bookings.
     *
     * @throws InvalidStateTransition
     * @throws EventHasStarted
     */
    public function handle(Event $event): int
    {
        return DB::transaction(function () use ($event): int {
            $event = Event::query()->lockForUpdate()->findOrFail($event->id);
            $event->cancel();

            $bookings = $event->bookings()
                ->where('status', BookingStatus::Confirmed)
                ->lockForUpdate()
                ->get();

            $bookings->each(function (Booking $booking) use ($event): void {
                $booking->setRelation('event', $event);
                $booking->cancel();
                $booking->save();
            });

            $event->save();

            return $bookings->count();
        });
    }
}
```

`Booking::cancel()` checks that the event has not started, which `Event::cancel()` has
already checked, and gives the seats back to the same `$event` object.

- [ ] **Step 6: Write the controller and the route**

Create the controller with
`docker compose exec app php artisan make:controller EventCancellationController --no-interaction`,
then replace its content:

```php
<?php

namespace App\Http\Controllers;

use App\Actions\CancelEvent\CancelEvent;
use App\Models\Event;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

class EventCancellationController extends Controller
{
    /**
     * Cancel the event and its bookings, and show the event page.
     */
    public function store(Event $event, CancelEvent $cancelEvent): RedirectResponse
    {
        $cancelledBookings = $cancelEvent->handle($event);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => trans_choice(
                '{0} Event cancelled.|{1} Event cancelled. 1 booking was cancelled.|[2,*] Event cancelled. :count bookings were cancelled.',
                $cancelledBookings,
            ),
        ]);

        return to_route('events.show', $event);
    }
}
```

In `routes/web.php`, add the import `App\Http\Controllers\EventCancellationController`
(alphabetical order, after `EventAttendeeController`) and add inside the `auth` group,
after the `events.publication.store` route:

```php
    Route::post('events/{event}/cancellation', [EventCancellationController::class, 'store'])
        ->whereNumber('event')
        ->middleware('throttle:event-writes')
        ->name('events.cancellation.store')
        ->can('cancel', 'event');
```

- [ ] **Step 7: Run the tests and check that they pass**

Run: `docker compose exec app php artisan test --compact tests/Feature/EventPolicyTest.php tests/Feature/CancelEventTest.php tests/Feature/EventWritesRateLimitTest.php`
Expected: PASS.

- [ ] **Step 8: Format, analyse and commit**

Run: `docker compose exec app vendor/bin/pint --format agent app/Policies/EventPolicy.php app/Actions/CancelEvent app/Http/Controllers/EventCancellationController.php routes/web.php tests/Feature/EventPolicyTest.php tests/Feature/CancelEventTest.php`
Run: `docker compose exec app vendor/bin/phpstan analyse --memory-limit=1G --no-progress`
Expected: no errors.

```bash
git add app/Policies/EventPolicy.php app/Actions/CancelEvent/CancelEvent.php \
  app/Http/Controllers/EventCancellationController.php routes/web.php \
  tests/Feature/EventPolicyTest.php tests/Feature/CancelEventTest.php
git commit -m "feat: add the CancelEvent action and route"
```

---

### Task 3: Cancel button and "Event cancelled" badge

**Files:**

- Modify: `app/Http/Controllers/EventController.php`
- Modify: `app/Actions/GetBookings/GetBookings.php`
- Modify: `resources/js/types/booking.ts`
- Create: `resources/js/components/cancel-event-dialog.tsx`
- Modify: `resources/js/components/booking-status-badge.tsx`
- Modify: `resources/js/pages/events/show.tsx`
- Modify: `resources/js/pages/bookings/index.tsx`
- Test: `tests/Feature/GetEventTest.php`
- Test: `tests/Feature/GetBookingsTest.php`

**Interfaces:**

- Consumes: `EventPolicy::cancel`, `Event::canBeCancelled()` (Tasks 1 and 2), Wayfinder
  `store` from `@/routes/events/cancellation`.
- Produces: the prop `can.cancel: boolean` on `events/show`; `event_cancelled: bool` on
  each `GetBookings` row; `BookingStatusBadge` gets the optional prop `eventCancelled`;
  component `CancelEventDialog` with the prop `eventId`.

- [ ] **Step 1: Write the failing tests**

In `tests/Feature/GetEventTest.php`, add after the test
"BR-A2: the organizer and admins see the attendees link, other users and visitors do not":

```php
test('BR-E11, BR-A1: the organizer and admins see the cancel button, other users do not', function () {
    $event = Event::factory()->published()->for($this->organizer, 'organizer')->create();

    $this->actingAs($this->organizer)
        ->get(route('events.show', $event))
        ->assertInertia(fn (Assert $page) => $page->where('can.cancel', true));

    $this->actingAs($this->admin)
        ->get(route('events.show', $event))
        ->assertInertia(fn (Assert $page) => $page->where('can.cancel', true));

    $this->actingAs($this->otherUser)
        ->get(route('events.show', $event))
        ->assertInertia(fn (Assert $page) => $page->where('can.cancel', false));
});

test('BR-E12: there is no cancel button on started or cancelled events', function () {
    $started = Event::factory()->published()->started()->for($this->organizer, 'organizer')->create();
    $cancelled = Event::factory()->cancelled()->for($this->organizer, 'organizer')->create();

    $this->actingAs($this->organizer)
        ->get(route('events.show', $started))
        ->assertInertia(fn (Assert $page) => $page->where('can.cancel', false));

    $this->actingAs($this->organizer)
        ->get(route('events.show', $cancelled))
        ->assertInertia(fn (Assert $page) => $page->where('can.cancel', false));
});
```

In `tests/Feature/GetBookingsTest.php`, in the test "the attendee sees the data of each
booking", add `'event_cancelled' => false,` after `'can_cancel' => true,`. Then add at
the end of the file:

```php
test('BR-E13: the bookings of a cancelled event say that the event is cancelled', function () {
    bookingOf($this->attendee, 'Still on', now()->addDay());
    $booking = bookingOf($this->attendee, 'Called off', now()->addDays(2), cancelled: true);
    $booking->event->forceFill(['status' => 'cancelled', 'cancelled_at' => now()])->save();

    $this->actingAs($this->attendee)
        ->get(route('bookings.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('upcoming', fn ($rows) => collect($rows)->pluck('event_cancelled', 'event.title')->all()
                === ['Still on' => false, 'Called off' => true])
        );
});
```

- [ ] **Step 2: Run the tests and check that they fail**

Run: `docker compose exec app php artisan test --compact tests/Feature/GetEventTest.php tests/Feature/GetBookingsTest.php`
Expected: FAIL in the new and changed tests (`can.cancel` and `event_cancelled` do not
exist).

- [ ] **Step 3: Add the props**

In `app/Http/Controllers/EventController.php`, in `show()`, add this line after the
`'publish'` line of the `can` array:

```php
                'cancel' => ($request->user()?->can('cancel', $event) ?? false) && $event->canBeCancelled(),
```

In `app/Actions/GetBookings/GetBookings.php`:

- Change the eager load to `->with('event:id,title,venue,starts_at,status')`.
- Add `event_cancelled: bool` after `can_cancel: bool` in all three array shapes of the
  docblocks.
- Add this line after `'can_cancel' => $booking->canBeCancelled(),` in `row()`:

```php
            'event_cancelled' => $booking->event->isCancelled(),
```

- [ ] **Step 4: Run the tests and check that they pass**

Run: `docker compose exec app php artisan test --compact tests/Feature/GetEventTest.php tests/Feature/GetBookingsTest.php`
Expected: PASS.

- [ ] **Step 5: Update the type and the badge**

In `resources/js/types/booking.ts`, add `event_cancelled: boolean;` after
`can_cancel: boolean;` in `BookingRow`.

Replace the `BookingStatusBadge` function in
`resources/js/components/booking-status-badge.tsx` with:

```tsx
export function BookingStatusBadge({
    status,
    eventCancelled = false,
}: {
    status: BookingStatus;
    eventCancelled?: boolean;
}) {
    if (eventCancelled) {
        return <Badge variant="destructive">Event cancelled</Badge>;
    }

    return <Badge variant={variants[status]}>{labels[status]}</Badge>;
}
```

In `resources/js/pages/bookings/index.tsx`, pass the new prop to the badge:

```tsx
<BookingStatusBadge
    status={booking.status}
    eventCancelled={booking.event_cancelled}
/>
```

- [ ] **Step 6: Add the dialog**

Generate the Wayfinder files for the new route first:
`docker compose exec app php artisan wayfinder:generate --with-form`

Create `resources/js/components/cancel-event-dialog.tsx`:

```tsx
import { Form } from '@inertiajs/react';
import { Ban } from 'lucide-react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { store } from '@/routes/events/cancellation';

/**
 * A button that asks for a confirmation and then cancels the event and its bookings.
 * The dialog closes when the request ends, also when the server refuses with an error
 * toast.
 */
export function CancelEventDialog({ eventId }: { eventId: number }) {
    const [open, setOpen] = useState(false);

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button size="sm" variant="destructive">
                    <Ban />
                    Cancel event
                </Button>
            </DialogTrigger>
            <DialogContent>
                <DialogTitle>Cancel this event?</DialogTitle>
                <DialogDescription>
                    All confirmed bookings are cancelled. You cannot undo this.
                </DialogDescription>
                <Form {...store.form(eventId)} onFinish={() => setOpen(false)}>
                    {({ processing }) => (
                        <DialogFooter className="gap-2">
                            <DialogClose asChild>
                                <Button type="button" variant="secondary">
                                    Keep event
                                </Button>
                            </DialogClose>
                            <Button
                                type="submit"
                                variant="destructive"
                                disabled={processing}
                            >
                                Cancel event
                            </Button>
                        </DialogFooter>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}
```

- [ ] **Step 7: Show the dialog on the event page**

In `resources/js/pages/events/show.tsx`:

- Add the import `import { CancelEventDialog } from '@/components/cancel-event-dialog';`.
- Add `cancel: boolean;` to the `can` prop type.
- Add `can.cancel ||` to the condition of the button group.
- Add this block in the button group, after the `can.publish` block and before the
  `can.delete` block:

```tsx
{
    can.cancel && <CancelEventDialog eventId={event.id} />;
}
```

The button group has `flex gap-2`. If four buttons do not fit on a phone, add
`flex-wrap` to its class list.

- [ ] **Step 8: Run the checks**

Run: `docker compose exec app vendor/bin/pint --format agent app/Http/Controllers/EventController.php app/Actions/GetBookings tests/Feature/GetEventTest.php tests/Feature/GetBookingsTest.php`
Run: `docker compose exec app vendor/bin/phpstan analyse --memory-limit=1G --no-progress`
Run: `npm run check:fix`
Run: `npm run types:check`
Expected: no errors.

- [ ] **Step 9: Commit**

```bash
git add app/Http/Controllers/EventController.php app/Actions/GetBookings/GetBookings.php \
  resources/js/types/booking.ts resources/js/components/cancel-event-dialog.tsx \
  resources/js/components/booking-status-badge.tsx resources/js/pages/events/show.tsx \
  resources/js/pages/bookings/index.tsx tests/Feature/GetEventTest.php \
  tests/Feature/GetBookingsTest.php
git commit -m "feat: add the cancel event button and the event cancelled badge"
```

- [ ] **Step 10: The owner checks the pages in the browser**

The session has no browser tool. Ask the owner to check after Task 4, with the dev
server running. Create a test event as `organizer@example.com` and book it as
`attende@example.com` first, so that the dev data stays easy to read.

1. The event page of an own published event shows "Cancel event". The dialog opens;
   "Keep event" closes it; "Cancel event" cancels it, shows the toast with the number
   of bookings, and the page shows the "Cancelled" badge with no cancel button.
2. As the attendee, "My bookings" shows "Event cancelled" for that booking and no
   Cancel button.
3. The attendee list of the cancelled event shows 0 attendees and 0 seats booked.
4. At phone width, the button group fits (it wraps if needed).
5. No hydration warnings in the browser console.

---

### Task 4: READMEs, M4 rule check, handoff and plan

**Files:**

- Create: `app/Actions/CancelEvent/README.md`
- Modify: `app/Actions/GetBookings/README.md`
- Modify: `app/Actions/GetEvent/README.md`
- Modify: `HANDOFF.md`
- Add: `docs/superpowers/plans/2026-10-08-m4-cancel-event.md`

- [ ] **Step 1: Write the `CancelEvent` README**

Follow `app/Actions/CancelBooking/README.md`. Write in Simple English. Start with the
route and the checks before the controller (`auth`, the `event-writes` limiter,
`EventPolicy::cancel` with 404 when the person cannot see the event and 403 for other
users; BR-E11, BR-A1). Then explain the transaction and the lock order (the event row,
then the confirmed bookings), the checks in `Event::cancel()` (BR-E12), the cancel of
each confirmed booking with `Booking::cancel()` (BR-E13) and the seats that go back to
the event (BR-B11), why a booking request at the same time fails, and the redirect with
the toast. Say that the `EventCancelled` emails come in M5.

One sequence diagram for each outcome, no `alt` blocks:

1. The event is cancelled (lock the event, `cancel()`, lock the confirmed bookings,
   cancel each, update the rows, commit, redirect with the toast).
2. The event cannot be cancelled because of its status (`InvalidStateTransition`,
   rollback, error toast).
3. The event has started (`EventHasStarted`, rollback, error toast).
4. The person cannot see the event (policy denies as not found, 404).
5. The person is not the organizer or an admin (policy denies, 403).

In `app/Actions/GetBookings/README.md`, add that each row has `event_cancelled`, so that
the badge says "Event cancelled". In `app/Actions/GetEvent/README.md`, add `can.cancel`
to the description of the `can` flags.

Render the diagrams locally:
`npx -y @mermaid-js/mermaid-cli -i app/Actions/CancelEvent/README.md -o <scratch-dir>/cancel-event.md`
Expected: five SVG files and no errors.

- [ ] **Step 2: Check that every M4 rule has a test**

Run: `grep -rhoE "BR-(B[0-9]+|E1[23]|A[12])\b" tests | sort -u`
Expected: BR-B1 to BR-B14, BR-E12, BR-E13, BR-A1 and BR-A2 are all in the list. If one
is missing, find the test that covers it and add the rule ID to its name, or write the
missing test.

- [ ] **Step 3: Format and run all checks**

Run: `npm run check:fix`
Run: `docker compose exec app composer ci:check`
Expected: Pint, PHPStan, `vp check`, `tsc` and all tests pass.

- [ ] **Step 4: Update `HANDOFF.md`**

"Where we are": PR 6 is merged (#35). The current PR is M4 PR 7 (`feat/cancel-event`),
with the path of this plan. Write that it is open as a PR and waits for the merge (no
PR number, so that the branch needs only one push). With this PR, M4 is complete: every
M4 rule has a test (from Step 2). Replace the PR 6 notes at the top with the decisions
from the Global Constraints. Keep the PR 6 notes shortened under "PR 6 notes:" and keep
the general notes (`ensure_pages_exist`, `vp check` and Markdown code blocks).
"Next steps": the owner reviews this PR. Then split M5 (notifications) into PRs with
the owner.

- [ ] **Step 5: Commit**

```bash
git add app/Actions/CancelEvent/README.md app/Actions/GetBookings/README.md \
  app/Actions/GetEvent/README.md HANDOFF.md \
  docs/superpowers/plans/2026-10-08-m4-cancel-event.md
git commit -m "docs: document CancelEvent and update handoff"
```
