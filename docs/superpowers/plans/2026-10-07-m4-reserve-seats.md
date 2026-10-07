# M4 — Reserve Seats

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A logged-in user can book 1 to 4 seats of a bookable event from the event
page. The booking is confirmed immediately, and the available seats decrease.

**Architecture:** `Event::reserve()` checks the booking rules and returns a new
confirmed `Booking`. The `ReserveSeats` Action locks the event row in a transaction,
calls `reserve()` and saves the event and the booking. `BookingController@store`
handles `POST /events/{event}/bookings`. The event page gets a booking box: the form,
the booking of the user, a login link or "Sold out".

**Tech Stack:** Laravel 13, Inertia 3, React 19, Wayfinder, PostgreSQL 18, Pest.

**Spec:** `docs/superpowers/specs/2026-09-28-event-booking-design.md` (sections 4 to 8),
`docs/design/business-rules.md`, `docs/design/glossary.md`.

## Global Constraints

- Route: `POST /events/{event}/bookings`, name `events.bookings.store`,
  `BookingController@store`. Middleware: `auth` (BR-B1), `throttle:bookings`, and
  `can:view,event`, so that hidden events give a 404 and stay unknown.
- Rate limiter `bookings`: 10 each minute for each user (spec section 6). It has the
  same response as `event-writes`, with the toast "Too many booking requests. Wait one
  minute and try again." `CancelBooking` (PR 5) uses it too.
- `StoreBookingRequest`: `quantity` is required, an integer, from 1 to 4 (BR-B5).
- `Event::reserve(User $attendee, int $quantity): Booking` (spec section 4) checks, in
  this order:
    1. the event is published (BR-B2): `EventNotBookable::notPublished()`;
    2. the event has not started (BR-B2): `EventNotBookable::hasStarted()`;
    3. the user is not the organizer (BR-B3): `EventNotBookable::ownEvent()`;
    4. the user has no confirmed booking for the event (BR-B4): `AlreadyBooked`;
    5. the quantity is not more than the available seats (BR-B2, BR-B6):
       `NotEnoughSeats`, with the field `quantity`.

    Then it decreases `seats_available` (BR-B7) and returns a new, unsaved, confirmed
    booking. It does not save. The model knows nothing about transactions.

- `ReserveSeats` locks the event row (`lockForUpdate()`) in a transaction before it
  calls `reserve()` (BR-B14). The lock also makes two requests of the same user wait
  for each other, so the BR-B4 check sees the first booking. The partial unique index
  is the last line of defense.
- After a booking, the user goes back to the event page with the toast "You booked 2
  seats. Reference: <reference>." ("1 seat" for one).
- The event page gets a `booking` prop:
    - `{ state: 'booked', reference, quantity }`: the user has a confirmed booking;
    - `{ state: 'available', max_quantity }`: the user can book now
      (`Event::canBeBookedBy()`); `max_quantity` is the smaller of 4 and the available
      seats;
    - `{ state: 'login' }`: a visitor, and the event is bookable;
    - `{ state: 'sold_out' }`: the event is published, has not started and has no
      available seats, and the user has no booking. The organizer sees it too;
    - `null`: all other cases (draft, cancelled, started, the organizer of a bookable
      event).
- `GetEvent::handle(Event $event, ?User $viewer = null)` returns
  `['event' => ..., 'booking' => ...]`. The edit page uses only `event`.
- The `BookingConfirmed` email comes in M5. "My bookings" comes in PR 4.
- Each test that covers a business rule starts with the ID of the rule.
- Run commands inside the `app` container: `docker compose exec app <command>`.

## Review Focus

1. **The order of the checks in `reserve()`.** A user who is the organizer of a sold-out
   event gets "own event", not "not enough seats".
2. **The lock.** `reserve()` runs on the locked, fresh row, not on the event from the
   route. The concurrency check (PR 3) proves BR-B14 with parallel requests.
3. **Hidden events.** A draft gives a 404 for other users, before the model runs. A
   cancelled event that the user can see (BR-E16) gives the "not published" toast.
4. **The booking box.** Each state shows the right thing, also on a narrow screen.

---

### Task 1: Booking rules on the `Event` model

**Files:**

- Create: `app/Exceptions/Domain/EventNotBookable.php`
- Create: `app/Exceptions/Domain/AlreadyBooked.php`
- Create: `app/Exceptions/Domain/NotEnoughSeats.php`
- Modify: `app/Models/Event.php`
- Test: `tests/Feature/EventReserveTest.php`

**Interfaces:**

- Produces: `Event::reserve(User, int): Booking`, `Event::isBookable(): bool`,
  `Event::isSoldOut(): bool`, `Event::canBeBookedBy(User): bool`,
  `Event::confirmedBookingBy(User): ?Booking`.

- [ ] **Step 1: Write the failing tests**

Create the file with `php artisan make:test --pest EventReserveTest --no-interaction`,
then replace its content:

```php
<?php

use App\Enums\BookingStatus;
use App\Exceptions\Domain\AlreadyBooked;
use App\Exceptions\Domain\EventNotBookable;
use App\Exceptions\Domain\NotEnoughSeats;
use App\Models\Booking;
use App\Models\Event;
use App\Models\User;

beforeEach(function () {
    $this->organizer = User::factory()->create();
    $this->attendee = User::factory()->create();

    $this->event = Event::factory()->published()->for($this->organizer, 'organizer')
        ->create(['capacity' => 10, 'seats_available' => 10]);
});

test('BR-B7: reserve decreases the available seats and returns a confirmed booking', function () {
    $booking = $this->event->reserve($this->attendee, 3);

    expect($this->event->seats_available)->toBe(7)
        ->and($booking->exists)->toBeFalse()
        ->and($booking->event_id)->toBe($this->event->id)
        ->and($booking->user_id)->toBe($this->attendee->id)
        ->and($booking->quantity)->toBe(3)
        ->and($booking->status)->toBe(BookingStatus::Confirmed);
});

test('BR-B6: a user can book all the available seats', function () {
    $this->event->seats_available = 2;

    $this->event->reserve($this->attendee, 2);

    expect($this->event->seats_available)->toBe(0);
});

test('BR-B6: a user cannot book more than the available seats', function () {
    $this->event->seats_available = 2;

    $this->event->reserve($this->attendee, 3);
})->throws(NotEnoughSeats::class, 'Only 2 seats are left.');

test('BR-B2: a user cannot book a sold-out event', function () {
    $this->event->seats_available = 0;

    $this->event->reserve($this->attendee, 1);
})->throws(NotEnoughSeats::class, 'The event is sold out.');

test('BR-B2: a user cannot book a draft event', function () {
    $draft = Event::factory()->create();

    $draft->reserve($this->attendee, 1);
})->throws(EventNotBookable::class, 'Only a published event can be booked.');

test('BR-B2: a user cannot book a cancelled event', function () {
    $cancelled = Event::factory()->cancelled()->create();

    $cancelled->reserve($this->attendee, 1);
})->throws(EventNotBookable::class, 'Only a published event can be booked.');

test('BR-B2: a user cannot book an event that has started', function () {
    $started = Event::factory()->published()->started()->create();

    $started->reserve($this->attendee, 1);
})->throws(EventNotBookable::class, 'The event has started, so it cannot be booked.');

test('BR-B3: the organizer cannot book their own event', function () {
    $this->event->reserve($this->organizer, 1);
})->throws(EventNotBookable::class, 'You cannot book your own event.');

test('BR-B3: the organizer of a sold-out event gets the own-event error', function () {
    $this->event->seats_available = 0;

    $this->event->reserve($this->organizer, 1);
})->throws(EventNotBookable::class, 'You cannot book your own event.');

test('BR-B4: a user cannot book an event again while the first booking is confirmed', function () {
    Booking::factory()->for($this->event)->for($this->attendee, 'attendee')->create();

    $this->event->reserve($this->attendee, 1);
})->throws(AlreadyBooked::class, 'You already have a booking for this event.');

test('BR-B12: a user can book an event again after a cancelled booking', function () {
    Booking::factory()->cancelled()->for($this->event)->for($this->attendee, 'attendee')->create();

    $booking = $this->event->reserve($this->attendee, 1);

    expect($booking->isConfirmed())->toBeTrue();
});

test('NotEnoughSeats belongs to the quantity field', function () {
    expect((new NotEnoughSeats(2))->field())->toBe('quantity');
});

test('BR-B2: a published event with seats that has not started is bookable', function () {
    expect($this->event->isBookable())->toBeTrue()
        ->and($this->event->isSoldOut())->toBeFalse();
});

test('BR-B2: draft, cancelled, started and sold-out events are not bookable', function () {
    $soldOut = Event::factory()->published()->create(['capacity' => 5, 'seats_available' => 0]);

    expect(Event::factory()->create()->isBookable())->toBeFalse()
        ->and(Event::factory()->cancelled()->create()->isBookable())->toBeFalse()
        ->and(Event::factory()->published()->started()->create()->isBookable())->toBeFalse()
        ->and($soldOut->isBookable())->toBeFalse()
        ->and($soldOut->isSoldOut())->toBeTrue();
});

test('a started event with no seats is not sold out', function () {
    $event = Event::factory()->published()->started()->create(['capacity' => 5, 'seats_available' => 0]);

    expect($event->isSoldOut())->toBeFalse();
});

test('canBeBookedBy gives the same answer as reserve', function () {
    $booked = User::factory()->create();
    Booking::factory()->for($this->event)->for($booked, 'attendee')->create();

    expect($this->event->canBeBookedBy($this->attendee))->toBeTrue()
        ->and($this->event->canBeBookedBy($this->organizer))->toBeFalse()
        ->and($this->event->canBeBookedBy($booked))->toBeFalse();
});

test('confirmedBookingBy gives the confirmed booking of the user, not a cancelled one', function () {
    Booking::factory()->cancelled()->for($this->event)->for($this->attendee, 'attendee')->create();

    expect($this->event->confirmedBookingBy($this->attendee))->toBeNull();

    $confirmed = Booking::factory()->for($this->event)->for($this->attendee, 'attendee')->create();

    expect($this->event->confirmedBookingBy($this->attendee)?->is($confirmed))->toBeTrue();
});
```

- [ ] **Step 2: Run the tests to make sure they fail**

Run: `docker compose exec app php artisan test --compact tests/Feature/EventReserveTest.php`
Expected: FAIL. The exception classes and `Event::reserve()` do not exist.

- [ ] **Step 3: Create the exceptions**

`app/Exceptions/Domain/EventNotBookable.php`:

```php
<?php

namespace App\Exceptions\Domain;

/**
 * The event cannot be booked by this user (BR-B2, BR-B3).
 */
class EventNotBookable extends DomainException
{
    /**
     * BR-B2: only a published event can be booked.
     */
    public static function notPublished(): self
    {
        return new self(__('Only a published event can be booked.'));
    }

    /**
     * BR-B2: only an event that has not started can be booked.
     */
    public static function hasStarted(): self
    {
        return new self(__('The event has started, so it cannot be booked.'));
    }

    /**
     * BR-B3: the organizer cannot book their own event.
     */
    public static function ownEvent(): self
    {
        return new self(__('You cannot book your own event.'));
    }
}
```

`app/Exceptions/Domain/AlreadyBooked.php`:

```php
<?php

namespace App\Exceptions\Domain;

/**
 * BR-B4: the user already has a confirmed booking for the event.
 */
class AlreadyBooked extends DomainException
{
    public function __construct()
    {
        parent::__construct(__('You already have a booking for this event.'));
    }
}
```

`app/Exceptions/Domain/NotEnoughSeats.php`:

```php
<?php

namespace App\Exceptions\Domain;

/**
 * BR-B2 and BR-B6: the quantity is more than the available seats.
 */
class NotEnoughSeats extends DomainException
{
    public function __construct(int $seatsAvailable)
    {
        parent::__construct($seatsAvailable === 0
            ? __('The event is sold out.')
            : trans_choice('{1} Only 1 seat is left.|[2,*] Only :count seats are left.', $seatsAvailable));
    }

    /**
     * The error belongs to the quantity field.
     */
    public function field(): ?string
    {
        return 'quantity';
    }
}
```

- [ ] **Step 4: Add the model methods**

In `app/Models/Event.php`, import `App\Enums\BookingStatus`,
`App\Exceptions\Domain\AlreadyBooked`, `App\Exceptions\Domain\EventNotBookable` and
`App\Exceptions\Domain\NotEnoughSeats`. Add after `seatsBooked()`:

```php
/**
 * Whether the event is published, has not started and has available seats (BR-B2).
 */
public function isBookable(): bool
{
    return $this->isPublished() && ! $this->hasStarted() && $this->seats_available > 0;
}

/**
 * Whether the event is published, has not started and has no available seats.
 */
public function isSoldOut(): bool
{
    return $this->isPublished() && ! $this->hasStarted() && $this->seats_available === 0;
}

/**
 * The confirmed booking of the given user for the event, if any.
 */
public function confirmedBookingBy(User $user): ?Booking
{
    return $this->bookings()
        ->where('user_id', $user->id)
        ->where('status', BookingStatus::Confirmed)
        ->first();
}

/**
 * Whether the given user can book seats now (BR-B2, BR-B3, BR-B4). The page uses it to
 * show the booking form.
 */
public function canBeBookedBy(User $user): bool
{
    return $this->isBookable()
        && ! $this->isOrganizedBy($user)
        && $this->confirmedBookingBy($user) === null;
}

/**
 * Book seats for the given user (BR-B2 to BR-B4, BR-B6, BR-B7). It decreases the
 * available seats and returns a new confirmed booking. It saves nothing.
 *
 * @throws EventNotBookable
 * @throws AlreadyBooked
 * @throws NotEnoughSeats
 */
public function reserve(User $attendee, int $quantity): Booking
{
    if (! $this->isPublished()) {
        throw EventNotBookable::notPublished();
    }

    if ($this->hasStarted()) {
        throw EventNotBookable::hasStarted();
    }

    if ($this->isOrganizedBy($attendee)) {
        throw EventNotBookable::ownEvent();
    }

    if ($this->confirmedBookingBy($attendee) !== null) {
        throw new AlreadyBooked;
    }

    if ($quantity > $this->seats_available) {
        throw new NotEnoughSeats($this->seats_available);
    }

    $this->seats_available -= $quantity;

    $booking = new Booking;
    $booking->event_id = $this->id;
    $booking->user_id = $attendee->id;
    $booking->quantity = $quantity;
    $booking->status = BookingStatus::Confirmed;

    return $booking;
}
```

- [ ] **Step 5: Run the tests to make sure they pass**

Run: `docker compose exec app php artisan test --compact tests/Feature/EventReserveTest.php`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Exceptions/Domain app/Models/Event.php tests/Feature/EventReserveTest.php
git commit -m "feat: add the booking rules to the Event model"
```

---

### Task 2: `ReserveSeats` Action, route and rate limiter

**Files:**

- Create: `app/Actions/ReserveSeats/ReserveSeats.php`
- Create: `app/Http/Requests/StoreBookingRequest.php`
- Create: `app/Http/Controllers/BookingController.php`
- Modify: `routes/web.php`
- Modify: `app/Providers/AppServiceProvider.php`
- Test: `tests/Feature/ReserveSeatsTest.php`
- Test: `tests/Feature/BookingsRateLimitTest.php`

**Interfaces:**

- Consumes: `Event::reserve()`.
- Produces: `ReserveSeats::handle(Event, User, int): Booking`, the route
  `events.bookings.store`, the rate limiter `bookings`.

- [ ] **Step 1: Write the failing tests**

Create both files with `php artisan make:test --pest <Name> --no-interaction`, then
replace their content.

`tests/Feature/ReserveSeatsTest.php`:

```php
<?php

use App\Models\Booking;
use App\Models\Event;
use App\Models\User;

beforeEach(function () {
    $this->organizer = User::factory()->create();
    $this->attendee = User::factory()->create();

    $this->event = Event::factory()->published()->for($this->organizer, 'organizer')
        ->create(['capacity' => 10, 'seats_available' => 10]);
});

test('BR-B7: a user books seats and the booking is confirmed', function () {
    $response = $this->actingAs($this->attendee)
        ->post(route('events.bookings.store', $this->event), ['quantity' => 2]);

    $booking = Booking::sole();

    $response->assertRedirect(route('events.show', $this->event))
        ->assertInertiaFlash('toast', [
            'type' => 'success',
            'message' => "You booked 2 seats. Reference: {$booking->reference}.",
        ]);

    expect($booking->isConfirmed())->toBeTrue()
        ->and($booking->quantity)->toBe(2)
        ->and($booking->isMadeBy($this->attendee))->toBeTrue()
        ->and($booking->event_id)->toBe($this->event->id)
        ->and($this->event->fresh()->seats_available)->toBe(8);
});

test('BR-B7: the toast says "seat" for one seat', function () {
    $this->actingAs($this->attendee)
        ->post(route('events.bookings.store', $this->event), ['quantity' => 1])
        ->assertInertiaFlash('toast.message', 'You booked 1 seat. Reference: '.Booking::sole()->reference.'.');
});

test('BR-B8: each booking gets its own reference', function () {
    $otherAttendee = User::factory()->create();

    $this->actingAs($this->attendee)->post(route('events.bookings.store', $this->event), ['quantity' => 1]);
    $this->actingAs($otherAttendee)->post(route('events.bookings.store', $this->event), ['quantity' => 1]);

    expect(Booking::pluck('reference')->unique())->toHaveCount(2);
});

test('BR-B1: a visitor is sent to the login page', function () {
    $this->post(route('events.bookings.store', $this->event), ['quantity' => 1])
        ->assertRedirect(route('login'));

    expect(Booking::count())->toBe(0);
});

test('BR-B2: another user gets a 404 for a draft event', function () {
    $draft = Event::factory()->for($this->organizer, 'organizer')->create();

    $this->actingAs($this->attendee)
        ->post(route('events.bookings.store', $draft), ['quantity' => 1])
        ->assertNotFound();
});

test('BR-B2: a user who can see a cancelled event cannot book it', function () {
    $cancelled = Event::factory()->cancelled()->create();
    Booking::factory()->cancelled()->for($cancelled)->for($this->attendee, 'attendee')->create();

    $this->actingAs($this->attendee)
        ->from(route('events.show', $cancelled))
        ->post(route('events.bookings.store', $cancelled), ['quantity' => 1])
        ->assertRedirect(route('events.show', $cancelled))
        ->assertInertiaFlash('toast', ['type' => 'error', 'message' => 'Only a published event can be booked.']);

    expect(Booking::count())->toBe(1);
});

test('BR-B2: a user cannot book an event that has started', function () {
    $started = Event::factory()->published()->started()->create();

    $this->actingAs($this->attendee)
        ->post(route('events.bookings.store', $started), ['quantity' => 1])
        ->assertInertiaFlash('toast.message', 'The event has started, so it cannot be booked.');

    expect(Booking::count())->toBe(0);
});

test('BR-B3: the organizer cannot book their own event', function () {
    $this->actingAs($this->organizer)
        ->post(route('events.bookings.store', $this->event), ['quantity' => 1])
        ->assertInertiaFlash('toast.message', 'You cannot book your own event.');

    expect(Booking::count())->toBe(0)
        ->and($this->event->fresh()->seats_available)->toBe(10);
});

test('BR-B4: a user cannot book the same event twice', function () {
    $this->actingAs($this->attendee)->post(route('events.bookings.store', $this->event), ['quantity' => 1]);

    $this->actingAs($this->attendee)
        ->post(route('events.bookings.store', $this->event), ['quantity' => 1])
        ->assertInertiaFlash('toast.message', 'You already have a booking for this event.');

    expect(Booking::count())->toBe(1)
        ->and($this->event->fresh()->seats_available)->toBe(9);
});

test('BR-B12: a user can book again after a cancelled booking', function () {
    Booking::factory()->cancelled()->for($this->event)->for($this->attendee, 'attendee')->create();

    $this->actingAs($this->attendee)
        ->post(route('events.bookings.store', $this->event), ['quantity' => 1])
        ->assertRedirect(route('events.show', $this->event));

    expect($this->event->confirmedBookingBy($this->attendee))->not->toBeNull();
});

test('BR-B6: a user cannot book more than the available seats', function () {
    $this->event->forceFill(['seats_available' => 2])->save();

    $this->actingAs($this->attendee)
        ->from(route('events.show', $this->event))
        ->post(route('events.bookings.store', $this->event), ['quantity' => 3])
        ->assertRedirect(route('events.show', $this->event))
        ->assertSessionHasErrors(['quantity' => 'Only 2 seats are left.']);

    expect(Booking::count())->toBe(0)
        ->and($this->event->fresh()->seats_available)->toBe(2);
});

test('BR-B5: the quantity must be from 1 to 4', function (mixed $quantity) {
    $this->actingAs($this->attendee)
        ->post(route('events.bookings.store', $this->event), ['quantity' => $quantity])
        ->assertSessionHasErrors('quantity');

    expect(Booking::count())->toBe(0);
})->with([0, 5, 'two', null]);

test('BR-B5: a user can book 4 seats', function () {
    $this->actingAs($this->attendee)
        ->post(route('events.bookings.store', $this->event), ['quantity' => 4])
        ->assertSessionHasNoErrors();

    expect(Booking::sole()->quantity)->toBe(4);
});
```

`tests/Feature/BookingsRateLimitTest.php`:

```php
<?php

use App\Models\Booking;
use App\Models\Event;
use App\Models\User;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->event = Event::factory()->published()->create(['capacity' => 10, 'seats_available' => 10]);

    foreach (range(1, 10) as $attempt) {
        $this->actingAs($this->user)->post(route('events.bookings.store', $this->event), ['quantity' => 1]);
    }
});

test('an Inertia request over the limit goes back with an error toast', function () {
    $this->actingAs($this->user)
        ->from(route('events.show', $this->event))
        ->withHeaders(['X-Inertia' => 'true'])
        ->post(route('events.bookings.store', $this->event), ['quantity' => 1])
        ->assertRedirect(route('events.show', $this->event))
        ->assertInertiaFlash('toast', [
            'type' => 'error',
            'message' => 'Too many booking requests. Wait one minute and try again.',
        ]);
});

test('a plain request over the limit gets a 429', function () {
    $this->actingAs($this->user)
        ->post(route('events.bookings.store', $this->event), ['quantity' => 1])
        ->assertTooManyRequests();
});

test('the limit is for each user', function () {
    $this->actingAs(User::factory()->create())
        ->post(route('events.bookings.store', $this->event), ['quantity' => 1])
        ->assertRedirect(route('events.show', $this->event));

    expect(Booking::count())->toBe(2);
});
```

The first 10 requests of the same user make one booking and nine `AlreadyBooked`
errors. They still count for the limit, because the limit counts requests.

- [ ] **Step 2: Run the tests to make sure they fail**

Run: `docker compose exec app php artisan test --compact tests/Feature/ReserveSeatsTest.php tests/Feature/BookingsRateLimitTest.php`
Expected: FAIL. The route `events.bookings.store` is not defined.

- [ ] **Step 3: Create the Action, the request and the controller**

`app/Actions/ReserveSeats/ReserveSeats.php`:

```php
<?php

namespace App\Actions\ReserveSeats;

use App\Exceptions\Domain\AlreadyBooked;
use App\Exceptions\Domain\EventNotBookable;
use App\Exceptions\Domain\NotEnoughSeats;
use App\Models\Booking;
use App\Models\Event;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class ReserveSeats
{
    /**
     * Book seats of the event for the attendee. The row lock makes parallel requests
     * wait, so two users never get the same last seat (BR-B14).
     *
     * @throws EventNotBookable
     * @throws AlreadyBooked
     * @throws NotEnoughSeats
     */
    public function handle(Event $event, User $attendee, int $quantity): Booking
    {
        return DB::transaction(function () use ($event, $attendee, $quantity): Booking {
            $event = Event::query()->lockForUpdate()->findOrFail($event->id);

            $booking = $event->reserve($attendee, $quantity);
            $event->save();
            $booking->save();

            return $booking;
        });
    }
}
```

Run: `docker compose exec app php artisan make:request StoreBookingRequest --no-interaction`

```php
<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreBookingRequest extends FormRequest
{
    /**
     * The route checks that the user can see the event. The model checks the booking
     * rules (BR-B2 to BR-B4, BR-B6).
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request (BR-B5).
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'quantity' => ['required', 'integer', 'between:1,4'],
        ];
    }
}
```

Run: `docker compose exec app php artisan make:controller BookingController --no-interaction`

```php
<?php

namespace App\Http\Controllers;

use App\Actions\ReserveSeats\ReserveSeats;
use App\Http\Requests\StoreBookingRequest;
use App\Models\Event;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

class BookingController extends Controller
{
    /**
     * Book seats of the event and show the event page.
     */
    public function store(StoreBookingRequest $request, Event $event, ReserveSeats $reserveSeats): RedirectResponse
    {
        $booking = $reserveSeats->handle($event, $request->user(), $request->integer('quantity'));

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => trans_choice(
                '{1} You booked 1 seat. Reference: :reference.|[2,*] You booked :count seats. Reference: :reference.',
                $booking->quantity,
                ['reference' => $booking->reference],
            ),
        ]);

        return to_route('events.show', $event);
    }
}
```

- [ ] **Step 4: Add the route and the rate limiter**

In `routes/web.php`, import `App\Http\Controllers\BookingController` and add in the
`auth` group, after the publication route:

```php
Route::post('events/{event}/bookings', [BookingController::class, 'store'])
    ->whereNumber('event')
    ->middleware('throttle:bookings')
    ->name('events.bookings.store')
    ->can('view', 'event');
```

In `app/Providers/AppServiceProvider.php`, change `configureRateLimiting()` so that both
limiters share one response:

```php
/**
 * Configure the named rate limiters of the application.
 */
protected function configureRateLimiting(): void
{
    RateLimiter::for('event-writes', fn (Request $request): Limit => Limit::perMinute(20)
        ->by((string) $request->user()?->id)
        ->response($this->tooManyRequests(__('Too many changes. Wait one minute and try again.'))));

    RateLimiter::for('bookings', fn (Request $request): Limit => Limit::perMinute(10)
        ->by((string) $request->user()?->id)
        ->response($this->tooManyRequests(__('Too many booking requests. Wait one minute and try again.'))));
}

/**
 * The response over a limit: a redirect back with an error toast for Inertia requests,
 * and a plain 429 for all other requests.
 *
 * @return Closure(Request, array<string, string>): Response
 */
protected function tooManyRequests(string $message): Closure
{
    return function (Request $request, array $headers) use ($message): Response {
        if (! $request->header('X-Inertia')) {
            return response('Too Many Attempts.', 429, $headers);
        }

        Inertia::flash('toast', ['type' => 'error', 'message' => $message]);

        return back()->withHeaders($headers);
    };
}
```

Import `Closure`.

- [ ] **Step 5: Run the tests to make sure they pass**

Run: `docker compose exec app php artisan test --compact tests/Feature/ReserveSeatsTest.php tests/Feature/BookingsRateLimitTest.php tests/Feature/EventWritesRateLimitTest.php`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Actions/ReserveSeats/ReserveSeats.php app/Http routes/web.php app/Providers/AppServiceProvider.php \
  tests/Feature/ReserveSeatsTest.php tests/Feature/BookingsRateLimitTest.php
git commit -m "feat: let users book seats of an event"
```

---

### Task 3: The booking box on the event page

**Files:**

- Modify: `app/Actions/GetEvent/GetEvent.php`
- Modify: `app/Http/Controllers/EventController.php`
- Modify: `resources/js/types/event.ts`
- Create: `resources/js/components/booking-box.tsx`
- Modify: `resources/js/pages/events/show.tsx`
- Test: `tests/Feature/GetEventTest.php`

**Interfaces:**

- Consumes: `Event::canBeBookedBy()`, `Event::confirmedBookingBy()`,
  `Event::isBookable()`, `Event::isSoldOut()`, the route `events.bookings.store`.
- Produces: `GetEvent::handle(Event, ?User): array{event: ..., booking: ...}`, the
  `booking` prop, `BookingBox`.

- [ ] **Step 1: Write the failing tests**

Add `use App\Models\Booking;` to `tests/Feature/GetEventTest.php` and these tests at
the end:

```php
// Booking box: BR-B1 to BR-B4

test('BR-B2: a user sees the booking form on a bookable event', function () {
    $event = Event::factory()->published()->create(['capacity' => 10, 'seats_available' => 10]);

    $this->actingAs($this->otherUser)
        ->get(route('events.show', $event))
        ->assertInertia(fn (Assert $page) => $page
            ->where('booking', ['state' => 'available', 'max_quantity' => 4])
        );
});

test('BR-B6: the form allows at most the available seats', function () {
    $event = Event::factory()->published()->create(['capacity' => 10, 'seats_available' => 2]);

    $this->actingAs($this->otherUser)
        ->get(route('events.show', $event))
        ->assertInertia(fn (Assert $page) => $page
            ->where('booking', ['state' => 'available', 'max_quantity' => 2])
        );
});

test('BR-B4: a user with a confirmed booking sees the booking, not the form', function () {
    $event = Event::factory()->published()->create();
    $booking = Booking::factory()->for($event)->for($this->otherUser, 'attendee')->create(['quantity' => 3]);

    $this->actingAs($this->otherUser)
        ->get(route('events.show', $event))
        ->assertInertia(fn (Assert $page) => $page
            ->where('booking', ['state' => 'booked', 'reference' => $booking->reference, 'quantity' => 3])
        );
});

test('BR-B12: a user with only a cancelled booking sees the form', function () {
    $event = Event::factory()->published()->create();
    Booking::factory()->cancelled()->for($event)->for($this->otherUser, 'attendee')->create();

    $this->actingAs($this->otherUser)
        ->get(route('events.show', $event))
        ->assertInertia(fn (Assert $page) => $page->where('booking.state', 'available'));
});

test('BR-B1: a visitor sees a login link on a bookable event', function () {
    $event = Event::factory()->published()->create();

    $this->get(route('events.show', $event))
        ->assertInertia(fn (Assert $page) => $page->where('booking', ['state' => 'login']));
});

test('BR-B2: users and visitors see "Sold out" on a sold-out event', function () {
    $event = Event::factory()->published()->create(['capacity' => 5, 'seats_available' => 0]);

    $this->actingAs($this->otherUser)
        ->get(route('events.show', $event))
        ->assertInertia(fn (Assert $page) => $page->where('booking', ['state' => 'sold_out']));

    auth()->logout();

    $this->get(route('events.show', $event))
        ->assertInertia(fn (Assert $page) => $page->where('booking', ['state' => 'sold_out']));
});

test('BR-B3: the organizer sees no booking box on their bookable event', function () {
    $event = Event::factory()->published()->for($this->organizer, 'organizer')->create();

    $this->actingAs($this->organizer)
        ->get(route('events.show', $event))
        ->assertInertia(fn (Assert $page) => $page->where('booking', null));
});

test('BR-B2: there is no booking box on draft, cancelled and started events', function () {
    $draft = Event::factory()->for($this->organizer, 'organizer')->create();
    $cancelled = Event::factory()->cancelled()->for($this->organizer, 'organizer')->create();
    $started = Event::factory()->published()->started()->create();

    foreach ([$draft, $cancelled] as $event) {
        $this->actingAs($this->organizer)
            ->get(route('events.show', $event))
            ->assertInertia(fn (Assert $page) => $page->where('booking', null));
    }

    $this->actingAs($this->otherUser)
        ->get(route('events.show', $started))
        ->assertInertia(fn (Assert $page) => $page->where('booking', null));
});
```

- [ ] **Step 2: Run the tests to make sure they fail**

Run: `docker compose exec app php artisan test --compact tests/Feature/GetEventTest.php`
Expected: FAIL. The page has no `booking` prop.

- [ ] **Step 3: Change `GetEvent` and the controller**

Replace `app/Actions/GetEvent/GetEvent.php`:

```php
<?php

namespace App\Actions\GetEvent;

use App\Models\Event;
use App\Models\User;

class GetEvent
{
    /**
     * Get the data for the page of one event. The booking box depends on the viewer
     * (BR-B1 to BR-B4).
     *
     * @return array{
     *     event: array{id: int, title: string, description: string, venue: string, starts_at: string, capacity: int, seats_available: int, status: string, organizer_name: string},
     *     booking: array{state: 'booked', reference: string, quantity: int}|array{state: 'available', max_quantity: int}|array{state: 'login'}|array{state: 'sold_out'}|null,
     * }
     */
    public function handle(Event $event, ?User $viewer = null): array
    {
        $event->loadMissing('organizer:id,name');

        return [
            'event' => [
                'id' => $event->id,
                'title' => $event->title,
                'description' => $event->description,
                'venue' => $event->venue,
                'starts_at' => $event->starts_at->toIso8601String(),
                'capacity' => $event->capacity,
                'seats_available' => $event->seats_available,
                'status' => $event->status->value,
                'organizer_name' => $event->organizer->name,
            ],
            'booking' => $this->bookingBox($event, $viewer),
        ];
    }

    /**
     * What the booking box shows to the viewer.
     *
     * @return array{state: 'booked', reference: string, quantity: int}|array{state: 'available', max_quantity: int}|array{state: 'login'}|array{state: 'sold_out'}|null
     */
    private function bookingBox(Event $event, ?User $viewer): ?array
    {
        $booking = $viewer === null ? null : $event->confirmedBookingBy($viewer);

        if ($booking !== null) {
            return ['state' => 'booked', 'reference' => $booking->reference, 'quantity' => $booking->quantity];
        }

        if ($viewer !== null && $event->canBeBookedBy($viewer)) {
            return ['state' => 'available', 'max_quantity' => min(4, $event->seats_available)];
        }

        if ($viewer === null && $event->isBookable()) {
            return ['state' => 'login'];
        }

        if ($event->isSoldOut()) {
            return ['state' => 'sold_out'];
        }

        return null;
    }
}
```

In `app/Http/Controllers/EventController.php`, change `show()` and `edit()`:

```php
public function show(Request $request, Event $event, GetEvent $getEvent): Response
{
    return Inertia::render('events/show', [
        ...$getEvent->handle($event, $request->user()),
        'can' => [
            // unchanged
        ],
    ]);
}
```

```php
public function edit(Event $event, GetEvent $getEvent): Response
{
    return Inertia::render('events/edit', [
        'event' => $getEvent->handle($event)['event'],
    ]);
}
```

- [ ] **Step 4: Run the tests to make sure they pass**

Run: `docker compose exec app php artisan test --compact tests/Feature/GetEventTest.php tests/Feature/UpdateEventTest.php`
Expected: PASS.

- [ ] **Step 5: Add the type and the component**

In `resources/js/types/event.ts`, add:

```ts
export type EventBookingBox =
    | { state: 'booked'; reference: string; quantity: number }
    | { state: 'available'; max_quantity: number }
    | { state: 'login' }
    | { state: 'sold_out' };
```

Run: `docker compose exec app php artisan wayfinder:generate --no-interaction` (the
Vite plugin also does this in development).

Create `resources/js/components/booking-box.tsx`:

```tsx
import { Form, Link } from '@inertiajs/react';
import { Ticket } from 'lucide-react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { login } from '@/routes';
import { store } from '@/routes/events/bookings';
import type { EventBookingBox } from '@/types';

/**
 * The booking part of the event page: the form, the booking of the user, a login link
 * or "Sold out".
 */
export function BookingBox({
    eventId,
    booking,
}: {
    eventId: number;
    booking: EventBookingBox;
}) {
    return (
        <section aria-label="Booking" className="rounded-lg border p-4 text-sm">
            {booking.state === 'booked' && (
                <p>
                    You booked {booking.quantity}{' '}
                    {booking.quantity === 1 ? 'seat' : 'seats'}. Reference:{' '}
                    <span className="font-mono break-all">
                        {booking.reference}
                    </span>
                </p>
            )}

            {booking.state === 'available' && (
                <Form
                    {...store.form(eventId)}
                    options={{ preserveScroll: true }}
                    className="flex flex-wrap items-end gap-3"
                >
                    {({ processing, errors }) => (
                        <>
                            <div className="grid gap-2">
                                <Label htmlFor="quantity">Seats</Label>
                                <Input
                                    id="quantity"
                                    name="quantity"
                                    type="number"
                                    required
                                    min={1}
                                    max={booking.max_quantity}
                                    defaultValue={1}
                                    className="w-24"
                                />
                            </div>
                            <Button type="submit" disabled={processing}>
                                <Ticket />
                                Book seats
                            </Button>
                            <InputError
                                message={errors.quantity}
                                className="basis-full"
                            />
                        </>
                    )}
                </Form>
            )}

            {booking.state === 'login' && (
                <p>
                    <Link href={login()} className="underline">
                        Log in
                    </Link>{' '}
                    to book seats.
                </p>
            )}

            {booking.state === 'sold_out' && (
                <p className="font-medium">Sold out</p>
            )}
        </section>
    );
}
```

If `InputError` does not accept `className`, drop that prop and record the ruling.

In `resources/js/pages/events/show.tsx`, import `BookingBox` and `EventBookingBox`, add
the prop `booking: EventBookingBox | null`, and render the box after the `<dl>` list:

```tsx
{
    booking && <BookingBox eventId={event.id} booking={booking} />;
}
```

- [ ] **Step 6: Check the types and the page**

Run: `npx tsc --noEmit`
Expected: no errors.

Ask the owner to check the page in the browser (no browser tool in this session):
the form books seats and shows the toast; after the booking, the box shows the
reference; a visitor sees the login link; a sold-out event shows "Sold out"; the
organizer sees no box; an error under the field when the quantity is too high (change
the seats in the database to test it); the box works on a narrow window; no hydration
error in the console.

- [ ] **Step 7: Commit**

```bash
git add app/Actions/GetEvent/GetEvent.php app/Http/Controllers/EventController.php \
  resources/js tests/Feature/GetEventTest.php
git commit -m "feat: show the booking box on the event page"
```

---

### Task 4: READMEs, checks, handoff and plan

**Files:**

- Create: `app/Actions/ReserveSeats/README.md`
- Modify: `app/Actions/GetEvent/README.md`
- Modify: `HANDOFF.md`
- Add: `docs/superpowers/plans/2026-10-07-m4-reserve-seats.md`

- [ ] **Step 1: Write the `ReserveSeats` README**

Follow `app/Actions/PublishEvent/README.md` and `app/Actions/UpdateEvent/README.md`.
Start with the route and the checks before the controller (`auth` for BR-B1, the
`bookings` limiter, `view` of `EventPolicy` with the 404 for hidden events,
`StoreBookingRequest` for BR-B5). Then explain the transaction and the row lock
(BR-B14), the order of the checks in `Event::reserve()`, and the handler in
`bootstrap/app.php`. Say that the `BookingConfirmed` email comes in M5.

One sequence diagram for each outcome, no `alt` blocks:

1. The seats are booked (lock, `reserve()`, update the event, insert the booking,
   commit, redirect with the toast).
2. The event cannot be booked (`EventNotBookable`, rollback, error toast).
3. The user already has a booking (`AlreadyBooked`, rollback, error toast).
4. Not enough seats (`NotEnoughSeats`, rollback, error toast and field error).

In `app/Actions/GetEvent/README.md`, update the table row for "Cancelled" (attendees
can see it now, BR-E16), and add a short section about the booking box with its five
states. Update the diagrams if they show the Action's return value.

Render each diagram locally to check it:
`npx -y @mermaid-js/mermaid-cli -i app/Actions/ReserveSeats/README.md -o <scratch-dir>/reserve-seats.md`
Expected: four SVG files and no errors. Do the same for the GetEvent README.

- [ ] **Step 2: Format and run all checks**

Run: `docker compose exec app vendor/bin/pint --dirty --format agent`
Run: `npm run check:fix`
Run: `docker compose exec app composer ci:check`
Expected: Pint, PHPStan, `vp check`, `tsc` and all tests pass.

- [ ] **Step 3: Update `HANDOFF.md`**

"Where we are": PR 1 is merged. The current PR is M4 PR 2 (`feat/reserve-seats`),
with the path of this plan. Add the decisions from the Global Constraints: the route
and its middleware; the `bookings` limiter and the shared `tooManyRequests()` response;
the order of the checks in `reserve()`; the row lock; the redirect to the event page
with the reference in the toast; the `booking` prop and its states; the new signature
of `GetEvent`.
"Next steps": the owner reviews this PR. Then plan PR 3 (the concurrency check).

- [ ] **Step 4: Commit**

```bash
git add app/Actions/ReserveSeats/README.md app/Actions/GetEvent/README.md HANDOFF.md \
  docs/superpowers/plans/2026-10-07-m4-reserve-seats.md
git commit -m "docs: document ReserveSeats and update handoff"
```
