# M4 — CancelBooking: an Attendee Cancels a Booking

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** An attendee can cancel their confirmed booking before the event starts. The
seats go back to the event. The attendee can cancel from the "My bookings" page and from
the booking box on the event page.

**Architecture:** `Booking::cancel()` checks the rules (BR-B10) and calls
`Event::releaseSeats()` (BR-B11). The `CancelBooking` Action locks the event row, then the
booking row, calls `cancel()` and saves both rows in one transaction. The route
`POST /bookings/{booking:reference}/cancellation` has `auth`, `throttle:bookings` and
`can:cancel,booking` (BR-B9). `BookingCancellationController@store` redirects back with a
toast. Both pages get a `can_cancel` flag and show a `CancelBookingDialog`.

**Tech Stack:** Laravel 13, Inertia 3, React 19, TypeScript, Wayfinder, Pest.

**Spec:** `docs/superpowers/specs/2026-09-28-event-booking-design.md` (sections 4, 5, 6
and 8), `docs/design/business-rules.md` (BR-B9 to BR-B12, booking state diagram).

## Global Constraints

- The route is `POST /bookings/{booking:reference}/cancellation`, named
  `bookings.cancellation.store`, in the `auth` group (not `verified`), with
  `throttle:bookings` and `can:cancel,booking`. The URL uses the booking reference, not
  the numeric ID.
- `BookingPolicy::cancel` already exists: a person who cannot see the booking gets a
  404; the organizer of the event gets a 403 (BR-B9). Do not change it.
- `BookingStatus::canTransitionTo()`: only `confirmed` → `cancelled` is allowed.
- `Booking::cancel()` checks in this order: the status allows the change
  (`InvalidStateTransition::cannotCancelBooking`), then the event has not started
  (`EventHasStarted::cannotCancelBooking`). Then it sets `status` and `cancelled_at`
  and calls `Event::releaseSeats()`. It saves nothing.
- `Event::releaseSeats(int $quantity)` adds the quantity to `seats_available`, never
  above `capacity`.
- The Action locks the event row first, then the booking row, inside one transaction.
- Success: redirect back (fallback: `bookings.index`) with the toast
  `You cancelled your booking. Reference: <reference>.`
- Error messages:
    - `Only a confirmed booking can be cancelled. This booking is cancelled.`
    - `The event has started, so the booking cannot be cancelled.`
- The dialog title is "Cancel this booking?". Its buttons are "Keep booking" and
  "Cancel booking". The trigger button says "Cancel" in the table and "Cancel booking"
  in the booking box.
- `can_cancel` is `Booking::canBeCancelled()`: confirmed and the event has not started.
- The `BookingCancelled` email comes in M5.
- Run commands inside the `app` container: `docker compose exec app <command>`. Pint
  `--dirty` does not work in the container (no git); pass the file paths. Do not run
  `migrate:fresh` against the development database.

## Review Focus

1. **A double submit.** Two cancel requests for the same booking: the second one waits
   for the lock, sees a cancelled booking and gets the error toast. The seats are
   released once. Pinned by the "already cancelled" test in Task 2 (the model check is
   what stops the second release).
2. **Seats above capacity.** `releaseSeats()` never goes above the capacity, also when
   the data is wrong. Pinned by a test in Task 1.
3. **A wrong reference.** An unknown reference gives a 404, the same as a booking of
   another user. Pinned by a test in Task 2.
4. **Book again after cancel (BR-B12).** After the cancel, the event page shows the
   booking form again, and a new booking succeeds. Pinned by a test in Task 2.
5. **Started events.** No cancel button on a booking of a started event, on both pages.
   Pinned by tests in Task 3.

---

### Task 1: Cancel rules on the models

**Files:**

- Modify: `app/Enums/BookingStatus.php`
- Modify: `app/Exceptions/Domain/InvalidStateTransition.php`
- Modify: `app/Exceptions/Domain/EventHasStarted.php`
- Modify: `app/Models/Event.php`
- Modify: `app/Models/Booking.php`
- Test: `tests/Feature/BookingModelTest.php`
- Test: `tests/Feature/EventModelTest.php`

**Interfaces:**

- Produces:
    - `BookingStatus::canTransitionTo(BookingStatus $status): bool`.
    - `InvalidStateTransition::cannotCancelBooking(BookingStatus $status): self`.
    - `EventHasStarted::cannotCancelBooking(): self`.
    - `Event::releaseSeats(int $quantity): void`.
    - `Booking::canBeCancelled(): bool` and `Booking::cancel(): void` (throws
      `InvalidStateTransition`, `EventHasStarted`). Both read `$this->event`.

- [ ] **Step 1: Write the failing tests**

Add to the end of `tests/Feature/EventModelTest.php`:

```php
test('BR-B11: releasing seats increases the available seats', function () {
    $event = Event::factory()->published()->create(['capacity' => 10, 'seats_available' => 4]);

    $event->releaseSeats(3);

    expect($event->seats_available)->toBe(7);
});

test('BR-B11: releasing seats never goes above the capacity', function () {
    $event = Event::factory()->published()->create(['capacity' => 10, 'seats_available' => 9]);

    $event->releaseSeats(4);

    expect($event->seats_available)->toBe(10);
});
```

Add these imports to `tests/Feature/BookingModelTest.php`:
`App\Exceptions\Domain\EventHasStarted` and `App\Exceptions\Domain\InvalidStateTransition`.
Then add to the end of the file:

```php
test('BR-B10: the booking status follows the state diagram', function (BookingStatus $from, BookingStatus $to, bool $allowed) {
    expect($from->canTransitionTo($to))->toBe($allowed);
})->with([
    'confirmed to cancelled' => [BookingStatus::Confirmed, BookingStatus::Cancelled, true],
    'confirmed to confirmed' => [BookingStatus::Confirmed, BookingStatus::Confirmed, false],
    'cancelled to confirmed' => [BookingStatus::Cancelled, BookingStatus::Confirmed, false],
    'cancelled to cancelled' => [BookingStatus::Cancelled, BookingStatus::Cancelled, false],
]);

test('BR-B10, BR-B11: cancelling a confirmed booking releases its seats', function () {
    $this->freezeSecond();
    $event = Event::factory()->published()->create(['capacity' => 10, 'seats_available' => 6]);
    $booking = Booking::factory()->for($event)->create(['quantity' => 3]);

    $booking->cancel();

    expect($booking->isCancelled())->toBeTrue()
        ->and($booking->cancelled_at->equalTo(now()))->toBeTrue()
        ->and($booking->event->seats_available)->toBe(9);
});

test('BR-B10: a cancelled booking cannot be cancelled again', function () {
    $event = Event::factory()->published()->create(['capacity' => 10, 'seats_available' => 6]);
    $booking = Booking::factory()->cancelled()->for($event)->create(['quantity' => 3]);

    expect(fn () => $booking->cancel())
        ->toThrow(InvalidStateTransition::class, 'Only a confirmed booking can be cancelled. This booking is cancelled.');

    expect($booking->event->seats_available)->toBe(6);
});

test('BR-B10: a booking of a started event cannot be cancelled', function () {
    $event = Event::factory()->published()->started()->create(['capacity' => 10, 'seats_available' => 6]);
    $booking = Booking::factory()->for($event)->create(['quantity' => 3]);

    expect(fn () => $booking->cancel())
        ->toThrow(EventHasStarted::class, 'The event has started, so the booking cannot be cancelled.');

    expect($booking->isConfirmed())->toBeTrue()
        ->and($booking->event->seats_available)->toBe(6);
});

test('BR-B10: only a confirmed booking of an event that has not started can be cancelled', function () {
    $upcoming = Event::factory()->published()->create();
    $started = Event::factory()->published()->started()->create();

    expect(Booking::factory()->for($upcoming)->create()->canBeCancelled())->toBeTrue()
        ->and(Booking::factory()->cancelled()->for($upcoming)->create()->canBeCancelled())->toBeFalse()
        ->and(Booking::factory()->for($started)->create()->canBeCancelled())->toBeFalse();
});
```

- [ ] **Step 2: Run the tests and check that they fail**

Run: `docker compose exec app php artisan test --compact tests/Feature/BookingModelTest.php tests/Feature/EventModelTest.php`
Expected: FAIL with `Call to undefined method` for `canTransitionTo`, `cancel`,
`canBeCancelled` and `releaseSeats`. The old tests still pass.

- [ ] **Step 3: Add the enum method**

Replace the body of `app/Enums/BookingStatus.php` with:

```php
<?php

namespace App\Enums;

enum BookingStatus: string
{
    case Confirmed = 'confirmed';
    case Cancelled = 'cancelled';

    /**
     * Whether a booking with this status can change to the given status (BR-B10).
     */
    public function canTransitionTo(self $status): bool
    {
        return $this === self::Confirmed && $status === self::Cancelled;
    }
}
```

- [ ] **Step 4: Add the exceptions**

In `app/Exceptions/Domain/InvalidStateTransition.php`, add the import
`App\Enums\BookingStatus`, change the class docblock to
`The status does not allow the change (see EventStatus::canTransitionTo() and BookingStatus::canTransitionTo()).`,
and add this method at the end of the class:

```php
    /**
     * BR-B10: only a confirmed booking can be cancelled.
     */
    public static function cannotCancelBooking(BookingStatus $status): self
    {
        return new self(__('Only a confirmed booking can be cancelled. This booking is :status.', [
            'status' => $status->value,
        ]));
    }
```

In `app/Exceptions/Domain/EventHasStarted.php`, add at the end of the class:

```php
    /**
     * BR-B10: a booking can be cancelled only before the event starts.
     */
    public static function cannotCancelBooking(): self
    {
        return new self(__('The event has started, so the booking cannot be cancelled.'));
    }
```

- [ ] **Step 5: Add `Event::releaseSeats()`**

In `app/Models/Event.php`, add this method after `reserve()`:

```php
    /**
     * Give back seats of a cancelled booking (BR-B11). The available seats never go above
     * the capacity. It does not save the event.
     */
    public function releaseSeats(int $quantity): void
    {
        $this->seats_available = min($this->capacity, $this->seats_available + $quantity);
    }
```

- [ ] **Step 6: Add `Booking::canBeCancelled()` and `Booking::cancel()`**

In `app/Models/Booking.php`, add the imports `App\Exceptions\Domain\EventHasStarted` and
`App\Exceptions\Domain\InvalidStateTransition`, and add at the end of the class:

```php
    /**
     * Whether the booking can be cancelled now (BR-B10). The pages use it to show the
     * cancel button.
     */
    public function canBeCancelled(): bool
    {
        return $this->status->canTransitionTo(BookingStatus::Cancelled) && ! $this->event->hasStarted();
    }

    /**
     * Cancel the booking and give its seats back to the event (BR-B10, BR-B11). It saves
     * neither the booking nor the event.
     *
     * @throws InvalidStateTransition
     * @throws EventHasStarted
     */
    public function cancel(): void
    {
        if (! $this->status->canTransitionTo(BookingStatus::Cancelled)) {
            throw InvalidStateTransition::cannotCancelBooking($this->status);
        }

        if ($this->event->hasStarted()) {
            throw EventHasStarted::cannotCancelBooking();
        }

        $this->status = BookingStatus::Cancelled;
        $this->cancelled_at = now();
        $this->event->releaseSeats($this->quantity);
    }
```

- [ ] **Step 7: Run the tests and check that they pass**

Run: `docker compose exec app php artisan test --compact tests/Feature/BookingModelTest.php tests/Feature/EventModelTest.php`
Expected: PASS.

- [ ] **Step 8: Format, analyse and commit**

Run: `docker compose exec app vendor/bin/pint --format agent app/Enums/BookingStatus.php app/Exceptions/Domain app/Models tests/Feature/BookingModelTest.php tests/Feature/EventModelTest.php`
Run: `docker compose exec app vendor/bin/phpstan analyse --memory-limit=1G --no-progress`
Expected: no errors.

```bash
git add app/Enums/BookingStatus.php app/Exceptions/Domain/InvalidStateTransition.php \
  app/Exceptions/Domain/EventHasStarted.php app/Models/Event.php app/Models/Booking.php \
  tests/Feature/BookingModelTest.php tests/Feature/EventModelTest.php
git commit -m "feat: add the booking cancel rules to the models"
```

---

### Task 2: `CancelBooking` Action, route and controller

**Files:**

- Create: `app/Actions/CancelBooking/CancelBooking.php`
- Create: `app/Http/Controllers/BookingCancellationController.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/CancelBookingTest.php`

**Interfaces:**

- Consumes: `Booking::cancel()` (Task 1), `BookingPolicy::cancel`, the `bookings`
  rate limiter.
- Produces:
    - `CancelBooking::handle(Booking $booking): Booking`.
    - Route `bookings.cancellation.store`: `POST /bookings/{booking:reference}/cancellation`.
      Wayfinder function `store` in `@/routes/bookings/cancellation`, called with the
      reference.

- [ ] **Step 1: Write the failing tests**

Create the file with `docker compose exec app php artisan make:test --pest CancelBookingTest --no-interaction`,
then replace its content:

```php
<?php

use App\Models\Booking;
use App\Models\Event;
use App\Models\User;

beforeEach(function () {
    $this->freezeSecond();

    $this->organizer = User::factory()->create();
    $this->attendee = User::factory()->create();

    $this->event = Event::factory()->published()->for($this->organizer, 'organizer')
        ->create(['capacity' => 10, 'seats_available' => 7]);
    $this->booking = Booking::factory()->for($this->event)->for($this->attendee, 'attendee')
        ->create(['quantity' => 3]);
});

test('BR-B11: the attendee cancels a booking and the seats go back to the event', function () {
    $this->actingAs($this->attendee)
        ->from(route('bookings.index'))
        ->post(route('bookings.cancellation.store', $this->booking))
        ->assertRedirect(route('bookings.index'))
        ->assertInertiaFlash('toast', [
            'type' => 'success',
            'message' => "You cancelled your booking. Reference: {$this->booking->reference}.",
        ]);

    $booking = $this->booking->fresh();

    expect($booking->isCancelled())->toBeTrue()
        ->and($booking->cancelled_at->equalTo(now()))->toBeTrue()
        ->and($this->event->fresh()->seats_available)->toBe(10);
});

test('the attendee goes back to the event page after a cancel from there', function () {
    $this->actingAs($this->attendee)
        ->from(route('events.show', $this->event))
        ->post(route('bookings.cancellation.store', $this->booking))
        ->assertRedirect(route('events.show', $this->event));
});

test('the route uses the booking reference', function () {
    expect(route('bookings.cancellation.store', $this->booking))
        ->toEndWith("/bookings/{$this->booking->reference}/cancellation");
});

test('a visitor is sent to the login page', function () {
    $this->post(route('bookings.cancellation.store', $this->booking))
        ->assertRedirect(route('login'));

    expect($this->booking->fresh()->isConfirmed())->toBeTrue();
});

test('BR-B9: the organizer of the event gets a 403', function () {
    $this->actingAs($this->organizer)
        ->post(route('bookings.cancellation.store', $this->booking))
        ->assertForbidden();

    expect($this->booking->fresh()->isConfirmed())->toBeTrue();
});

test('BR-B9: another user gets a 404', function () {
    $this->actingAs(User::factory()->create())
        ->post(route('bookings.cancellation.store', $this->booking))
        ->assertNotFound();

    expect($this->booking->fresh()->isConfirmed())->toBeTrue();
});

test('an unknown reference gives a 404', function () {
    $this->actingAs($this->attendee)
        ->post('/bookings/01jzzzzzzzzzzzzzzzzzzzzzzz/cancellation')
        ->assertNotFound();
});

test('BR-B10: a cancelled booking cannot be cancelled again, and the seats stay the same', function () {
    $this->actingAs($this->attendee)->post(route('bookings.cancellation.store', $this->booking));
    $cancelledAt = $this->booking->fresh()->cancelled_at;
    $this->travel(1)->minutes();

    $this->actingAs($this->attendee)
        ->from(route('bookings.index'))
        ->post(route('bookings.cancellation.store', $this->booking))
        ->assertRedirect(route('bookings.index'))
        ->assertInertiaFlash('toast', [
            'type' => 'error',
            'message' => 'Only a confirmed booking can be cancelled. This booking is cancelled.',
        ]);

    expect($this->booking->fresh()->cancelled_at->equalTo($cancelledAt))->toBeTrue()
        ->and($this->event->fresh()->seats_available)->toBe(10);
});

test('BR-B10: a booking of a started event cannot be cancelled', function () {
    $this->event->forceFill(['starts_at' => now()->subHour()])->save();

    $this->actingAs($this->attendee)
        ->from(route('bookings.index'))
        ->post(route('bookings.cancellation.store', $this->booking))
        ->assertRedirect(route('bookings.index'))
        ->assertInertiaFlash('toast', [
            'type' => 'error',
            'message' => 'The event has started, so the booking cannot be cancelled.',
        ]);

    expect($this->booking->fresh()->isConfirmed())->toBeTrue()
        ->and($this->event->fresh()->seats_available)->toBe(7);
});

test('BR-B12: after a cancel, the attendee can book the same event again', function () {
    $this->actingAs($this->attendee)->post(route('bookings.cancellation.store', $this->booking));

    $this->actingAs($this->attendee)
        ->post(route('events.bookings.store', $this->event), ['quantity' => 2])
        ->assertRedirect(route('events.show', $this->event))
        ->assertInertiaFlash('toast.type', 'success');

    expect(Booking::where('user_id', $this->attendee->id)->pluck('status')->map->value->all())
        ->toEqualCanonicalizing(['cancelled', 'confirmed'])
        ->and($this->event->fresh()->seats_available)->toBe(8);
});

test('the cancel uses the bookings rate limit', function () {
    foreach (range(1, 10) as $attempt) {
        $this->actingAs($this->attendee)->post(route('bookings.cancellation.store', $this->booking));
    }

    $this->actingAs($this->attendee)
        ->post(route('bookings.cancellation.store', $this->booking))
        ->assertTooManyRequests();
});
```

- [ ] **Step 2: Run the tests and check that they fail**

Run: `docker compose exec app php artisan test --compact tests/Feature/CancelBookingTest.php`
Expected: FAIL with `Route [bookings.cancellation.store] not defined.` (the unknown
reference test fails with a 404 for the wrong reason or passes; that is fine at this
step).

- [ ] **Step 3: Write the Action**

Create `app/Actions/CancelBooking/CancelBooking.php`:

```php
<?php

namespace App\Actions\CancelBooking;

use App\Exceptions\Domain\EventHasStarted;
use App\Exceptions\Domain\InvalidStateTransition;
use App\Models\Booking;
use App\Models\Event;
use Illuminate\Support\Facades\DB;

class CancelBooking
{
    /**
     * Cancel the booking and give its seats back to the event (BR-B10, BR-B11). The Action
     * locks the event row first and the booking row second, the same order as
     * ReserveSeats, so a second request for the same booking waits and then sees the
     * cancelled booking.
     *
     * @throws InvalidStateTransition
     * @throws EventHasStarted
     */
    public function handle(Booking $booking): Booking
    {
        return DB::transaction(function () use ($booking): Booking {
            $event = Event::query()->lockForUpdate()->findOrFail($booking->event_id);
            $booking = Booking::query()->lockForUpdate()->findOrFail($booking->id);
            $booking->setRelation('event', $event);

            $booking->cancel();
            $event->save();
            $booking->save();

            return $booking;
        });
    }
}
```

- [ ] **Step 4: Write the controller**

Create `app/Http/Controllers/BookingCancellationController.php` with
`docker compose exec app php artisan make:controller BookingCancellationController --no-interaction`,
then replace its content:

```php
<?php

namespace App\Http\Controllers;

use App\Actions\CancelBooking\CancelBooking;
use App\Models\Booking;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

class BookingCancellationController extends Controller
{
    /**
     * Cancel the booking and go back to the page of the request.
     */
    public function store(Booking $booking, CancelBooking $cancelBooking): RedirectResponse
    {
        $booking = $cancelBooking->handle($booking);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('You cancelled your booking. Reference: :reference.', ['reference' => $booking->reference]),
        ]);

        return back(fallback: route('bookings.index'));
    }
}
```

- [ ] **Step 5: Add the route**

In `routes/web.php`, add the import `App\Http\Controllers\BookingCancellationController`
(alphabetical order, after `BookingController`), and add inside the `auth` group, after
the `bookings.index` route:

```php
    Route::post('bookings/{booking:reference}/cancellation', [BookingCancellationController::class, 'store'])
        ->middleware('throttle:bookings')
        ->name('bookings.cancellation.store')
        ->can('cancel', 'booking');
```

- [ ] **Step 6: Run the tests and check that they pass**

Run: `docker compose exec app php artisan test --compact tests/Feature/CancelBookingTest.php tests/Feature/BookingsRateLimitTest.php tests/Feature/BookingPolicyTest.php`
Expected: PASS.

- [ ] **Step 7: Format, analyse and commit**

Run: `docker compose exec app vendor/bin/pint --format agent app/Actions/CancelBooking app/Http/Controllers/BookingCancellationController.php routes/web.php tests/Feature/CancelBookingTest.php`
Run: `docker compose exec app vendor/bin/phpstan analyse --memory-limit=1G --no-progress`
Expected: no errors.

```bash
git add app/Actions/CancelBooking/CancelBooking.php \
  app/Http/Controllers/BookingCancellationController.php routes/web.php \
  tests/Feature/CancelBookingTest.php
git commit -m "feat: add the CancelBooking action and route"
```

---

### Task 3: Cancel buttons on both pages

**Files:**

- Modify: `app/Actions/GetBookings/GetBookings.php`
- Modify: `app/Actions/GetEvent/GetEvent.php`
- Modify: `resources/js/types/booking.ts`
- Modify: `resources/js/types/event.ts`
- Create: `resources/js/components/cancel-booking-dialog.tsx`
- Modify: `resources/js/pages/bookings/index.tsx`
- Modify: `resources/js/components/booking-box.tsx`
- Test: `tests/Feature/GetBookingsTest.php`
- Test: `tests/Feature/GetEventTest.php`

**Interfaces:**

- Consumes: `Booking::canBeCancelled()` (Task 1), Wayfinder `store` from
  `@/routes/bookings/cancellation` (Task 2).
- Produces:
    - Each `GetBookings` row gets `can_cancel: bool`.
    - The `booked` state of the `GetEvent` booking box gets `can_cancel: bool`.
    - Component `CancelBookingDialog` with the props `reference: string` and
      `triggerLabel: string`.

- [ ] **Step 1: Write the failing tests**

In `tests/Feature/GetBookingsTest.php`, in the test "the attendee sees the data of each
booking", add `'can_cancel' => true,` after `'status' => 'confirmed',` in the expected
row. Then add at the end of the file:

```php
test('BR-B10: only confirmed bookings of events that have not started can be cancelled', function () {
    bookingOf($this->attendee, 'Upcoming', now()->addDay());
    bookingOf($this->attendee, 'Cancelled', now()->addDays(2), cancelled: true);
    bookingOf($this->attendee, 'Started', now()->subDay());

    $this->actingAs($this->attendee)
        ->get(route('bookings.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('upcoming', fn ($rows) => collect($rows)->pluck('can_cancel', 'event.title')->all()
                === ['Upcoming' => true, 'Cancelled' => false])
            ->where('past', fn ($rows) => collect($rows)->pluck('can_cancel', 'event.title')->all()
                === ['Started' => false])
        );
});
```

In `tests/Feature/GetEventTest.php`, in the test "BR-B4: a user with a confirmed booking
sees the booking, not the form", change the expected value to
`['state' => 'booked', 'reference' => $booking->reference, 'quantity' => 3, 'can_cancel' => true]`.
Then add after that test:

```php
test('BR-B10: a user with a booking of a started event cannot cancel it', function () {
    $event = Event::factory()->published()->started()->create();
    Booking::factory()->for($event)->for($this->otherUser, 'attendee')->create();

    $this->actingAs($this->otherUser)
        ->get(route('events.show', $event))
        ->assertInertia(fn (Assert $page) => $page
            ->where('booking.state', 'booked')
            ->where('booking.can_cancel', false)
        );
});
```

- [ ] **Step 2: Run the tests and check that they fail**

Run: `docker compose exec app php artisan test --compact tests/Feature/GetBookingsTest.php tests/Feature/GetEventTest.php`
Expected: FAIL in the three changed or new tests (`can_cancel` is missing).

- [ ] **Step 3: Add `can_cancel` to the Actions**

In `app/Actions/GetBookings/GetBookings.php`, add `can_cancel: bool` after
`status: string` in all three array shapes of the docblocks, and add this line after
`'status' => $booking->status->value,` in `row()`:

```php
            'can_cancel' => $booking->canBeCancelled(),
```

In `app/Actions/GetEvent/GetEvent.php`, change both docblock shapes of the booked
state to `array{state: 'booked', reference: string, quantity: int, can_cancel: bool}`,
and change the booked branch of `bookingBox()` to:

```php
        if ($booking !== null) {
            $booking->setRelation('event', $event);

            return [
                'state' => 'booked',
                'reference' => $booking->reference,
                'quantity' => $booking->quantity,
                'can_cancel' => $booking->canBeCancelled(),
            ];
        }
```

`setRelation()` avoids a second query for the event that the page already has.

- [ ] **Step 4: Run the tests and check that they pass**

Run: `docker compose exec app php artisan test --compact tests/Feature/GetBookingsTest.php tests/Feature/GetEventTest.php`
Expected: PASS.

- [ ] **Step 5: Update the types**

In `resources/js/types/booking.ts`, add `can_cancel: boolean;` after
`status: BookingStatus;` in `BookingRow`.

In `resources/js/types/event.ts`, change the booked state of `EventBookingBox` to:

```ts
    | {
          state: 'booked';
          reference: string;
          quantity: number;
          can_cancel: boolean;
      }
```

- [ ] **Step 6: Add the dialog**

Generate the Wayfinder files for the new route first:
`docker compose exec app php artisan wayfinder:generate --with-form`

Create `resources/js/components/cancel-booking-dialog.tsx`:

```tsx
import { Form } from '@inertiajs/react';
import { X } from 'lucide-react';
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
import { store } from '@/routes/bookings/cancellation';

/**
 * A button that asks for a confirmation and then cancels the booking. The dialog
 * closes when the request ends, also when the server refuses with an error toast.
 */
export function CancelBookingDialog({
    reference,
    triggerLabel,
}: {
    reference: string;
    triggerLabel: string;
}) {
    const [open, setOpen] = useState(false);

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button size="sm" variant="outline">
                    <X />
                    {triggerLabel}
                </Button>
            </DialogTrigger>
            <DialogContent>
                <DialogTitle>Cancel this booking?</DialogTitle>
                <DialogDescription>
                    Your seats go back to the event. You can book again while
                    seats are available.
                </DialogDescription>
                <Form
                    {...store.form(reference)}
                    options={{ preserveScroll: true }}
                    onFinish={() => setOpen(false)}
                >
                    {({ processing }) => (
                        <DialogFooter className="gap-2">
                            <DialogClose asChild>
                                <Button variant="secondary">
                                    Keep booking
                                </Button>
                            </DialogClose>
                            <Button
                                type="submit"
                                variant="destructive"
                                disabled={processing}
                            >
                                Cancel booking
                            </Button>
                        </DialogFooter>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}
```

- [ ] **Step 7: Use the dialog on both pages**

In `resources/js/pages/bookings/index.tsx`:

- Add the import `import { CancelBookingDialog } from '@/components/cancel-booking-dialog';`.
- Add a last header cell after "Status":

```tsx
<th className="px-3 py-2">
    <span className="sr-only">Actions</span>
</th>
```

- Add a last cell in each row, after the status cell:

```tsx
<td className="px-3 py-2 text-right">
    {booking.can_cancel && (
        <CancelBookingDialog
            reference={booking.reference}
            triggerLabel="Cancel"
        />
    )}
</td>
```

In `resources/js/components/booking-box.tsx`, add the import
`import { CancelBookingDialog } from '@/components/cancel-booking-dialog';` and replace
the `booked` block with:

```tsx
{
    booking.state === 'booked' && (
        <div className="flex flex-col items-start gap-3">
            <p>
                You booked {booking.quantity}{' '}
                {booking.quantity === 1 ? 'seat' : 'seats'}. Reference:{' '}
                <span className="font-mono break-all">{booking.reference}</span>
            </p>
            {booking.can_cancel && (
                <CancelBookingDialog
                    reference={booking.reference}
                    triggerLabel="Cancel booking"
                />
            )}
        </div>
    );
}
```

- [ ] **Step 8: Run the checks**

Run: `docker compose exec app php artisan test --compact tests/Feature/GetBookingsTest.php tests/Feature/GetEventTest.php`
Run: `docker compose exec app vendor/bin/pint --format agent app/Actions/GetBookings app/Actions/GetEvent tests/Feature/GetBookingsTest.php tests/Feature/GetEventTest.php`
Run: `docker compose exec app vendor/bin/phpstan analyse --memory-limit=1G --no-progress`
Run: `npm run check:fix`
Run: `npm run types:check`
Expected: all pass, no errors.

- [ ] **Step 9: Commit**

```bash
git add app/Actions/GetBookings/GetBookings.php app/Actions/GetEvent/GetEvent.php \
  resources/js/types/booking.ts resources/js/types/event.ts \
  resources/js/components/cancel-booking-dialog.tsx resources/js/pages/bookings/index.tsx \
  resources/js/components/booking-box.tsx tests/Feature/GetBookingsTest.php \
  tests/Feature/GetEventTest.php
git commit -m "feat: add cancel buttons to My bookings and the event page"
```

- [ ] **Step 10: The owner checks the pages in the browser**

The session has no browser tool. Ask the owner to check after Task 4, with the dev
server running (`attende@example.com` / `password`):

1. "My bookings": each upcoming confirmed booking has "Cancel"; cancelled and past rows
   have none. The dialog opens; "Keep booking" closes it; "Cancel booking" cancels,
   the page stays on "My bookings", the toast shows the reference, and the row shows
   "Cancelled".
2. Event page with a booking: "Cancel booking" under the reference. After the cancel,
   the page shows the booking form again and the available seats go up.
3. At phone width, the table still scrolls horizontally, and the dialog fits.
4. No hydration warnings in the browser console.

---

### Task 4: READMEs, checks, handoff and plan

**Files:**

- Create: `app/Actions/CancelBooking/README.md`
- Modify: `app/Actions/GetBookings/README.md`
- Modify: `app/Actions/GetEvent/README.md`
- Modify: `HANDOFF.md`
- Add: `docs/superpowers/plans/2026-10-08-m4-cancel-booking.md`

- [ ] **Step 1: Write the `CancelBooking` README**

Follow `app/Actions/ReserveSeats/README.md`. Write in Simple English. Start with the
route and the checks before the controller: `auth`, the `bookings` limiter (shared with
`ReserveSeats`), the reference in the URL, and `BookingPolicy::cancel` (404 when the
person cannot see the booking, 403 for the organizer of the event, BR-B9). Then explain
the transaction and the lock order (event row, then booking row, the same order as
`ReserveSeats`), the checks in `Booking::cancel()` (BR-B10), the release of the seats
(BR-B11, never above the capacity), why a double submit releases the seats only once,
and the redirect back with the toast. Say that the `BookingCancelled` email comes in
M5.

One sequence diagram for each outcome, no `alt` blocks:

1. The booking is cancelled (lock the event, lock the booking, `cancel()`, update both
   rows, commit, redirect back with the toast).
2. The booking is not confirmed (`InvalidStateTransition`, rollback, error toast).
3. The event has started (`EventHasStarted`, rollback, error toast).
4. The person may not cancel the booking (policy: 404 or 403, the Action does not run).

In `app/Actions/GetBookings/README.md`, add that each row tells if the booking can be
cancelled (`Booking::canBeCancelled()`), so that the page shows a "Cancel" button, and
change "The organizer of an event sees the attendees of the event on another page
(`GetEventAttendees`)" to say that this page comes in a later PR.

In `app/Actions/GetEvent/README.md`, add `can_cancel` to the description of the
`booked` state.

Render each diagram locally to check it:
`npx -y @mermaid-js/mermaid-cli -i app/Actions/CancelBooking/README.md -o <scratch-dir>/cancel-booking.md`
Expected: four SVG files and no errors. Do the same for the other two READMEs if their
diagrams changed.

- [ ] **Step 2: Format and run all checks**

Run: `npm run check:fix`
Run: `docker compose exec app composer ci:check`
Expected: Pint, PHPStan, `vp check`, `tsc` and all tests pass.

- [ ] **Step 3: Update `HANDOFF.md`**

"Where we are": PR 4 is merged (#33). The current PR is M4 PR 5
(`feat/cancel-booking`), with the path of this plan. Write that it is open as a PR and
waits for the merge (no PR number, so that the branch needs only one push). Replace the
PR 4 notes at the top with the decisions from the Global Constraints: the route, its
middleware and the reference in the URL; the lock order; the order of the checks in
`cancel()`; `releaseSeats()` never above the capacity; redirect back with the toast;
the dialog texts; `can_cancel` on both pages; the email in M5. Keep the PR 4 notes under
a "PR 4 notes:" heading, shortened.
"Next steps": the owner reviews this PR. Then plan PR 6 (`GetEventAttendees`).

- [ ] **Step 4: Commit**

```bash
git add app/Actions/CancelBooking/README.md app/Actions/GetBookings/README.md \
  app/Actions/GetEvent/README.md HANDOFF.md \
  docs/superpowers/plans/2026-10-08-m4-cancel-booking.md
git commit -m "docs: document CancelBooking and update handoff"
```
