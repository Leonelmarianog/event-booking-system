# M4 — Bookings Table, Booking Model and Booking Policy

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** The database can keep bookings, and one policy tells who can see and cancel a
booking. Users who had a booking can see a cancelled event (BR-E16). This PR has no
routes and no pages.

**Architecture:** A migration creates the `bookings` table with the constraints of the
ERD. The `Booking` model has the `BookingStatus` enum cast and a ULID `reference` that
the model makes on create. `BookingPolicy` has `view` and `cancel`. `EventPolicy::view`
gets the attendee part of BR-E16. The later M4 PRs add the Actions, the routes and the
pages.

**Tech Stack:** Laravel 13, PostgreSQL 18, Pest.

**Spec:** `docs/superpowers/specs/2026-09-28-event-booking-design.md` (sections 4 and 6),
`docs/design/business-rules.md`, `docs/design/erd.md`.

## Global Constraints

- The `bookings` table has only the v1 columns: `id`, `reference`, `event_id`,
  `user_id`, `quantity`, `status`, `cancelled_at`, `created_at`, `updated_at`. The
  future columns (`payment_expires_at`, `tickets_pdf_path`) are not added.
- Time columns of `bookings` are `timestamptz`.
- `bookings.event_id` and `bookings.user_id` use `ON DELETE RESTRICT` (ERD).
- Constraints: `CHECK (quantity BETWEEN 1 AND 4)` (BR-B5),
  `CHECK (status IN ('confirmed', 'cancelled'))`, `UNIQUE (reference)` (BR-B8),
  `UNIQUE (event_id, user_id) WHERE status = 'confirmed'` (BR-B4, BR-B12).
- Indexes: `(user_id)` and `(event_id, status)` (ERD).
- `reference` is a ULID (26 characters). The model makes it on create with
  `HasUlids` and `uniqueIds()`. The primary key stays a `bigint`.
- `Booking` has no `#[Fillable]`, like `Event`. The Actions set the attributes.
- The factory does not change `events.seats_available`. Tests that need the right
  number of seats set it themselves. `ReserveSeats` (PR 2) owns that logic.
- BR-E16: a user who has any booking for a cancelled event (confirmed or cancelled)
  can see it.
- `BookingPolicy::view`: the attendee and the organizer of the event (BR-B13). All
  others get a 404. Admins are not included: BR-B13 does not name them. Admins see the
  attendees through the attendee list (BR-A2, PR 6).
- `BookingPolicy::cancel`: only the attendee (BR-B9). The organizer gets a 403. All
  others get a 404. The model checks the state in PR 5 (BR-B10).
- Model methods that change state (`Event::reserveSeats()`, `Booking::cancel()`) come
  in the PR of the Action that uses them, not in this PR.
- Each test that covers a business rule starts with the ID of the rule. Tests of the
  factory, the reference format and the status constraint have no ID.
- Run commands inside the `app` container: `docker compose exec app <command>`. Start
  the stack with `make up`.

## Review Focus

1. **The partial unique index.** A user can have one confirmed booking for each event
   (BR-B4), and any number of cancelled bookings. After a cancel, the user can book
   again (BR-B12).
2. **The reference.** The model makes it, not the database. It is a lowercase ULID.
   The primary key is still a `bigint`.
3. **BR-E16.** Only a cancelled event uses the booking check. A draft has no bookings
   (BR-B2), and a published event is visible to everyone.
4. **An organizer who is also an attendee.** BR-B3 blocks this in PR 2, so the policy
   does not handle it.

---

### Task 1: `bookings` table, `BookingStatus` and `Booking` model

**Files:**

- Create: `database/migrations/<timestamp>_create_bookings_table.php`
- Create: `app/Enums/BookingStatus.php`
- Create: `app/Models/Booking.php`
- Create: `database/factories/BookingFactory.php`
- Modify: `app/Models/Event.php`
- Modify: `app/Models/User.php`
- Test: `tests/Feature/BookingsTableTest.php`
- Test: `tests/Feature/BookingModelTest.php`

**Interfaces:**

- Produces: `BookingStatus::Confirmed`, `BookingStatus::Cancelled`,
  `Booking::factory()`, `Booking::factory()->cancelled()`, `Booking::$reference`,
  `Booking->event`, `Booking->attendee`, `Booking::isConfirmed()`,
  `Booking::isCancelled()`, `Booking::isMadeBy(User)`, `Event->bookings`,
  `Event::hasBookingBy(User)`, `User->bookings`.

- [ ] **Step 1: Write the failing tests**

Create the files with `php artisan make:test --pest BookingsTableTest --no-interaction`
and `php artisan make:test --pest BookingModelTest --no-interaction`, then replace
their content.

`tests/Feature/BookingsTableTest.php`:

```php
<?php

use App\Models\Booking;
use App\Models\Event;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

test('BR-B5: the database rejects a quantity of 0', function () {
    Booking::factory()->create(['quantity' => 0]);
})->throws(QueryException::class, 'bookings_quantity_check');

test('BR-B5: the database rejects a quantity of 5', function () {
    Booking::factory()->create(['quantity' => 5]);
})->throws(QueryException::class, 'bookings_quantity_check');

test('BR-B5: the database accepts a quantity of 1 and of 4', function () {
    $one = Booking::factory()->create(['quantity' => 1]);
    $four = Booking::factory()->create(['quantity' => 4]);

    expect($one->fresh()->quantity)->toBe(1)
        ->and($four->fresh()->quantity)->toBe(4);
});

test('the database rejects an unknown booking status', function () {
    $booking = Booking::factory()->create();

    DB::table('bookings')->where('id', $booking->id)->update(['status' => 'pending_payment']);
})->throws(QueryException::class, 'bookings_status_check');

test('BR-B4: the database rejects a second confirmed booking of the same user for the same event', function () {
    $booking = Booking::factory()->create();

    Booking::factory()->for($booking->event)->for($booking->attendee, 'attendee')->create();
})->throws(QueryException::class, 'bookings_event_id_user_id_confirmed_unique');

test('BR-B12: the database accepts a new confirmed booking after a cancelled one', function () {
    $cancelled = Booking::factory()->cancelled()->create();

    $confirmed = Booking::factory()->for($cancelled->event)->for($cancelled->attendee, 'attendee')->create();

    expect($confirmed->fresh()->isConfirmed())->toBeTrue();
});

test('BR-B4: two users can each have a confirmed booking for the same event', function () {
    $event = Event::factory()->published()->create();

    Booking::factory()->for($event)->create();
    Booking::factory()->for($event)->create();

    expect($event->bookings()->count())->toBe(2);
});

test('BR-B8: the database rejects a duplicate reference', function () {
    $booking = Booking::factory()->create();

    DB::table('bookings')->insert([
        'reference' => $booking->reference,
        'event_id' => $booking->event_id,
        'user_id' => User::factory()->create()->id,
        'quantity' => 1,
        'status' => 'confirmed',
    ]);
})->throws(QueryException::class, 'bookings_reference_unique');

test('the database does not delete an event that has bookings', function () {
    $booking = Booking::factory()->create();

    DB::table('events')->where('id', $booking->event_id)->delete();
})->throws(QueryException::class, 'bookings_event_id_foreign');

test('BR-U4: the database does not delete a user who has bookings', function () {
    $booking = Booking::factory()->create();

    DB::table('users')->where('id', $booking->user_id)->delete();
})->throws(QueryException::class, 'bookings_user_id_foreign');
```

`tests/Feature/BookingModelTest.php`:

```php
<?php

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Event;
use App\Models\User;

test('the factory makes a confirmed booking of one seat for a published event', function () {
    $booking = Booking::factory()->create()->fresh();

    expect($booking->status)->toBe(BookingStatus::Confirmed)
        ->and($booking->quantity)->toBe(1)
        ->and($booking->cancelled_at)->toBeNull()
        ->and($booking->event->isPublished())->toBeTrue()
        ->and($booking->isConfirmed())->toBeTrue()
        ->and($booking->isCancelled())->toBeFalse();
});

test('the cancelled factory state makes a cancelled booking', function () {
    $booking = Booking::factory()->cancelled()->create()->fresh();

    expect($booking->status)->toBe(BookingStatus::Cancelled)
        ->and($booking->cancelled_at)->not->toBeNull()
        ->and($booking->isCancelled())->toBeTrue()
        ->and($booking->isConfirmed())->toBeFalse();
});

test('BR-B8: a new booking gets a ULID reference', function () {
    $booking = Booking::factory()->create();

    expect($booking->reference)->toMatch('/^[0-9a-hjkmnp-tv-z]{26}$/')
        ->and($booking->id)->toBeInt();
});

test('BR-B8: each booking gets a different reference', function () {
    $first = Booking::factory()->create();
    $second = Booking::factory()->create();

    expect($first->reference)->not->toBe($second->reference);
});

test('a booking belongs to an event and to an attendee', function () {
    $event = Event::factory()->published()->create();
    $user = User::factory()->create();

    $booking = Booking::factory()->for($event)->for($user, 'attendee')->create();

    expect($booking->event->is($event))->toBeTrue()
        ->and($booking->attendee->is($user))->toBeTrue()
        ->and($booking->isMadeBy($user))->toBeTrue()
        ->and($booking->isMadeBy(User::factory()->create()))->toBeFalse()
        ->and($event->bookings->pluck('id')->all())->toBe([$booking->id])
        ->and($user->bookings->pluck('id')->all())->toBe([$booking->id]);
});

test('BR-E16: an event knows which users have a booking for it, also a cancelled one', function () {
    $event = Event::factory()->cancelled()->create();
    $confirmed = Booking::factory()->for($event)->create();
    $cancelled = Booking::factory()->cancelled()->for($event)->create();

    expect($event->hasBookingBy($confirmed->attendee))->toBeTrue()
        ->and($event->hasBookingBy($cancelled->attendee))->toBeTrue()
        ->and($event->hasBookingBy(User::factory()->create()))->toBeFalse();
});
```

- [ ] **Step 2: Run the tests to make sure they fail**

Run: `docker compose exec app php artisan test --compact tests/Feature/BookingsTableTest.php tests/Feature/BookingModelTest.php`
Expected: FAIL. The class `App\Models\Booking` does not exist.

- [ ] **Step 3: Create the enum**

Create `app/Enums/BookingStatus.php`:

```php
<?php

namespace App\Enums;

enum BookingStatus: string
{
    case Confirmed = 'confirmed';
    case Cancelled = 'cancelled';
}
```

- [ ] **Step 4: Create the model, the factory and the migration**

Run: `docker compose exec app php artisan make:model Booking --factory --migration --no-interaction`

Migration `up()` and `down()`:

```php
public function up(): void
{
    Schema::create('bookings', function (Blueprint $table) {
        $table->id();
        $table->ulid('reference')->unique();
        $table->foreignId('event_id')->constrained()->restrictOnDelete();
        $table->foreignId('user_id')->constrained()->restrictOnDelete();
        $table->smallInteger('quantity');
        $table->string('status')->default('confirmed');
        $table->timestampTz('cancelled_at')->nullable();
        $table->timestampsTz();

        $table->index('user_id');
        $table->index(['event_id', 'status']);
    });

    DB::statement('ALTER TABLE bookings ADD CONSTRAINT bookings_quantity_check CHECK (quantity BETWEEN 1 AND 4)');
    DB::statement("ALTER TABLE bookings ADD CONSTRAINT bookings_status_check CHECK (status IN ('confirmed', 'cancelled'))");
    DB::statement("CREATE UNIQUE INDEX bookings_event_id_user_id_confirmed_unique ON bookings (event_id, user_id) WHERE status = 'confirmed'");
}

public function down(): void
{
    Schema::dropIfExists('bookings');
}
```

Add `use Illuminate\Support\Facades\DB;` to the migration.

Replace `app/Models/Booking.php`:

```php
<?php

namespace App\Models;

use App\Enums\BookingStatus;
use Carbon\CarbonImmutable;
use Database\Factories\BookingFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property string $reference
 * @property int $event_id
 * @property int $user_id
 * @property int $quantity
 * @property BookingStatus $status
 * @property CarbonImmutable|null $cancelled_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Event $event
 * @property-read User $attendee
 */
class Booking extends Model
{
    /** @use HasFactory<BookingFactory> */
    use HasFactory, HasUlids;

    /**
     * The columns that get a new ULID on create. The primary key stays an integer.
     *
     * @return list<string>
     */
    public function uniqueIds(): array
    {
        return ['reference'];
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'event_id' => 'integer',
            'user_id' => 'integer',
            'quantity' => 'integer',
            'status' => BookingStatus::class,
            'cancelled_at' => 'datetime',
        ];
    }

    /**
     * The event of the booking.
     *
     * @return BelongsTo<Event, $this>
     */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /**
     * The user who made the booking.
     *
     * @return BelongsTo<User, $this>
     */
    public function attendee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Whether the given user made the booking.
     */
    public function isMadeBy(User $user): bool
    {
        return $this->user_id === $user->id;
    }

    /**
     * Whether the booking is confirmed.
     */
    public function isConfirmed(): bool
    {
        return $this->status === BookingStatus::Confirmed;
    }

    /**
     * Whether the booking is cancelled.
     */
    public function isCancelled(): bool
    {
        return $this->status === BookingStatus::Cancelled;
    }
}
```

Replace `database/factories/BookingFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Event;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Booking>
 */
class BookingFactory extends Factory
{
    /**
     * Define the model's default state: a confirmed booking of one seat for a published
     * event. It does not change the available seats of the event.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'event_id' => Event::factory()->published(),
            'user_id' => User::factory(),
            'quantity' => 1,
            'status' => BookingStatus::Confirmed,
            'cancelled_at' => null,
        ];
    }

    /**
     * Indicate that the booking is cancelled.
     */
    public function cancelled(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => BookingStatus::Cancelled,
            'cancelled_at' => now(),
        ]);
    }
}
```

In `app/Models/Event.php`, add `@property-read Collection<int, Booking> $bookings`
to the class PHPDoc (import `Illuminate\Database\Eloquent\Collection`), and add after
`organizer()`:

```php
/**
 * The bookings of the event, in all statuses.
 *
 * @return HasMany<Booking, $this>
 */
public function bookings(): HasMany
{
    return $this->hasMany(Booking::class);
}
```

and after `isOrganizedBy()`:

```php
/**
 * Whether the given user has a booking for the event, in any status (BR-E16).
 */
public function hasBookingBy(User $user): bool
{
    return $this->bookings()->where('user_id', $user->id)->exists();
}
```

In `app/Models/User.php`, add `@property-read Collection<int, Booking> $bookings` to
the class PHPDoc and this relation:

```php
/**
 * The bookings that the user made, in all statuses.
 *
 * @return HasMany<Booking, $this>
 */
public function bookings(): HasMany
{
    return $this->hasMany(Booking::class);
}
```

- [ ] **Step 5: Run the migration and the tests**

Run: `docker compose exec app php artisan migrate --no-interaction`
(Do not run `migrate:fresh`. It deletes the local data of the owner.)
Run: `docker compose exec app php artisan test --compact tests/Feature/BookingsTableTest.php tests/Feature/BookingModelTest.php`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Enums/BookingStatus.php app/Models database/migrations database/factories/BookingFactory.php \
  tests/Feature/BookingsTableTest.php tests/Feature/BookingModelTest.php
git commit -m "feat: add the bookings table and the Booking model"
```

---

### Task 2: `BookingPolicy` and BR-E16 for attendees

**Files:**

- Create: `app/Policies/BookingPolicy.php`
- Modify: `app/Policies/EventPolicy.php`
- Test: `tests/Feature/BookingPolicyTest.php`
- Modify: `tests/Feature/EventPolicyTest.php`

**Interfaces:**

- Consumes: `Booking::isMadeBy()`, `Event::isOrganizedBy()`, `Event::hasBookingBy()`.
- Produces: `BookingPolicy::view(User, Booking): Response`,
  `BookingPolicy::cancel(User, Booking): Response`, `EventPolicy::view` with the
  attendee part of BR-E16.

- [ ] **Step 1: Write the failing tests**

Create the file with `php artisan make:test --pest BookingPolicyTest --no-interaction`,
then replace its content:

```php
<?php

use App\Models\Booking;
use App\Models\Event;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

beforeEach(function () {
    $this->organizer = User::factory()->create();
    $this->attendee = User::factory()->create();
    $this->otherUser = User::factory()->create();
    $this->admin = User::factory()->admin()->create();

    $event = Event::factory()->published()->for($this->organizer, 'organizer')->create();
    $this->booking = Booking::factory()->for($event)->for($this->attendee, 'attendee')->create();
});

// View: BR-B13

test('BR-B13: the attendee and the organizer can see a booking', function () {
    expect($this->attendee->can('view', $this->booking))->toBeTrue()
        ->and($this->organizer->can('view', $this->booking))->toBeTrue();
});

test('BR-B13: other users, admins and visitors cannot see a booking', function () {
    expect($this->otherUser->can('view', $this->booking))->toBeFalse()
        ->and($this->admin->can('view', $this->booking))->toBeFalse()
        ->and(Gate::forUser(null)->allows('view', $this->booking))->toBeFalse();
});

test('BR-B13: a user who cannot see a booking gets a 404', function () {
    expect(Gate::forUser($this->otherUser)->inspect('view', $this->booking)->status())->toBe(404);
});

// Cancel: BR-B9

test('BR-B9: only the attendee can cancel a booking', function () {
    expect($this->attendee->can('cancel', $this->booking))->toBeTrue()
        ->and($this->organizer->can('cancel', $this->booking))->toBeFalse()
        ->and($this->otherUser->can('cancel', $this->booking))->toBeFalse()
        ->and($this->admin->can('cancel', $this->booking))->toBeFalse()
        ->and(Gate::forUser(null)->allows('cancel', $this->booking))->toBeFalse();
});

test('BR-B9: the organizer gets a 403 for the booking of an attendee', function () {
    expect(Gate::forUser($this->organizer)->inspect('cancel', $this->booking)->status())->toBe(403);
});

test('BR-B9: a user who cannot see the booking gets a 404', function () {
    expect(Gate::forUser($this->otherUser)->inspect('cancel', $this->booking)->status())->toBe(404);
});
```

In `tests/Feature/EventPolicyTest.php`, add `use App\Models\Booking;` and these tests
after `BR-E16: the organizer and admins can see a cancelled event`:

```php
test('BR-E16: an attendee with a confirmed booking can see a cancelled event', function () {
    $event = Event::factory()->cancelled()->create();
    $booking = Booking::factory()->for($event)->create();

    expect($booking->attendee->can('view', $event))->toBeTrue();
});

test('BR-E16: an attendee with a cancelled booking can see a cancelled event', function () {
    $event = Event::factory()->cancelled()->create();
    $booking = Booking::factory()->cancelled()->for($event)->create();

    expect($booking->attendee->can('view', $event))->toBeTrue();
});

test('BR-E16: a booking for another event does not show a cancelled event', function () {
    $event = Event::factory()->cancelled()->create();
    $booking = Booking::factory()->create();

    expect($booking->attendee->can('view', $event))->toBeFalse();
});
```

Remove "Attendees of a cancelled event come in M4." from the docblock in the policy
(Step 3), not from the tests.

- [ ] **Step 2: Run the tests to make sure they fail**

Run: `docker compose exec app php artisan test --compact tests/Feature/BookingPolicyTest.php tests/Feature/EventPolicyTest.php`
Expected: FAIL. No policy for `Booking`, and attendees cannot see a cancelled event.

- [ ] **Step 3: Write the policies**

Run: `docker compose exec app php artisan make:policy BookingPolicy --model=Booking --no-interaction`

Replace `app/Policies/BookingPolicy.php`:

```php
<?php

namespace App\Policies;

use App\Models\Booking;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class BookingPolicy
{
    /**
     * BR-B13. A person who cannot see the booking gets a 404, so that other bookings
     * stay unknown.
     */
    public function view(?User $user, Booking $booking): Response
    {
        if ($user !== null && ($booking->isMadeBy($user) || $booking->event->isOrganizedBy($user))) {
            return Response::allow();
        }

        return Response::denyAsNotFound();
    }

    /**
     * BR-B9. The model checks the state of the booking and the event (BR-B10). A person
     * who cannot see the booking gets a 404, so that other bookings stay unknown.
     */
    public function cancel(User $user, Booking $booking): Response
    {
        if ($this->view($user, $booking)->denied()) {
            return Response::denyAsNotFound();
        }

        return $booking->isMadeBy($user) ? Response::allow() : Response::deny();
    }
}
```

In `app/Policies/EventPolicy.php`, replace `view()`:

```php
/**
 * BR-E14, BR-E15, BR-E16 and BR-A4. A person who cannot see the event gets a 404, so
 * that hidden events stay unknown.
 */
public function view(?User $user, Event $event): Response
{
    if ($event->isPublished()) {
        return Response::allow();
    }

    if ($user === null) {
        return Response::denyAsNotFound();
    }

    if ($user->is_admin || $event->isOrganizedBy($user)) {
        return Response::allow();
    }

    if ($event->isCancelled() && $event->hasBookingBy($user)) {
        return Response::allow();
    }

    return Response::denyAsNotFound();
}
```

- [ ] **Step 4: Run the tests to make sure they pass**

Run: `docker compose exec app php artisan test --compact tests/Feature/BookingPolicyTest.php tests/Feature/EventPolicyTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Policies tests/Feature/BookingPolicyTest.php tests/Feature/EventPolicyTest.php
git commit -m "feat: add the booking policy and let attendees see a cancelled event"
```

---

### Task 3: Checks, handoff and plan

**Files:**

- Modify: `HANDOFF.md`
- Add: `docs/superpowers/plans/2026-10-07-m4-bookings-foundation.md`

- [ ] **Step 1: Format and run all checks**

Run: `docker compose exec app vendor/bin/pint --dirty --format agent`
Run: `npm run check:fix`
Run: `docker compose exec app composer ci:check`
Expected: Pint, PHPStan, `vp check`, `tsc` and all tests pass.

- [ ] **Step 2: Update `HANDOFF.md`**

"Where we are": M3 is complete. M4 (Bookings) has started. List the seven M4 PRs in
order:

1. `bookings` table, `Booking` model, `BookingStatus` enum, `BookingPolicy`, and the
   attendee part of BR-E16. No routes and no pages.
2. `ReserveSeats`: the booking form on `events/show`.
3. The concurrency check: the script, `make concurrency-test` and the CI job.
4. `GetBookings`: the "My bookings" page.
5. `CancelBooking`: `POST /bookings/{booking}/cancellation`.
6. `GetEventAttendees`: the attendee list page.
7. `CancelEvent`: `events.cancelled_at`, `Event::cancel()`, and the cancel of all
   confirmed bookings. The email of BR-E13 comes in M5.

The current PR (`feat/bookings-foundation`) is PR 1, with the path of this plan. Add
the decisions from the Global Constraints: the ULID reference made by the model with a
`bigint` primary key; the partial unique index; the factory does not change the
available seats; BR-E16 counts any booking; `BookingPolicy::view` (attendee and
organizer, 404 for others, no admins) and `cancel` (attendee only, 403 for the
organizer). Remove the old notes that say BR-E16 waits for M4 and that `HANDOFF.md`
waits for the review of PR 8.
"Next steps": the owner reviews this PR. Then plan PR 2 (`ReserveSeats`).

- [ ] **Step 3: Commit**

```bash
git add HANDOFF.md docs/superpowers/plans/2026-10-07-m4-bookings-foundation.md
git commit -m "docs: plan the bookings foundation and update handoff"
```
