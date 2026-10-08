# M5 — BookingCancelled: the Attendee Gets an Email for a Cancelled Booking

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** When an attendee cancels a booking, the attendee gets a "booking cancelled"
email (BR-N2). The email goes out only after the transaction commits (BR-N6).

**Architecture:** `App\Notifications\BookingCancelled` follows the pattern of
`BookingConfirmed` (M5 PR 1): queued, `afterCommit()` in the constructor, 3 tries with a
backoff, the default `MailMessage`. `CancelBooking` calls `notify()` inside its
transaction, after the saves. `CancelEvent` does not use `CancelBooking`, so a cancelled
event sends no `BookingCancelled` (the `EventCancelled` email comes in PR 3).

**Tech Stack:** Laravel 13 notifications and queues, Pest.

**Spec:** `docs/superpowers/specs/2026-09-28-event-booking-design.md` (sections 4, 7
and 9), `docs/design/business-rules.md` (BR-N2, BR-N6),
`docs/superpowers/plans/milestones.md` (M5).

## Global Constraints

- The notification pattern of M5 (see `HANDOFF.md` and
  `app/Notifications/BookingConfirmed.php`):
    - `implements ShouldQueue`, `use Queueable`, `$this->afterCommit()` in the
      constructor (BR-N6).
    - `#[Tries(3)]` and `#[Backoff([10, 60])]` on the class.
    - `via()` returns `['mail']`. `toMail()` uses the default `MailMessage`, with a
      `@param User $notifiable` docblock.
    - Times use `->utc()->format('D j M Y, H:i')` and the suffix ` UTC`.
- The Action sends the notification inside the transaction, after the saves.
- `BookingCancelled` content:
    - Subject: `Booking cancelled: {event title}`
    - Greeting: `Hello {attendee name},`
    - Lines: `Your booking for {event title} is cancelled.`, `Reference: {reference}`,
      `Seats: {quantity}`, `Starts: {starts_at}`
    - Action: `View event`, to `route('events.show', $event)`. The attendee can book
      the event again from there (BR-B12).
- Only the attendee can cancel a booking (`BookingPolicy::cancel`), so the attendee is
  the only person who gets the email.
- When an event is cancelled, its attendees get no `BookingCancelled` email (owner
  decision); they get `EventCancelled` in PR 3.
- Tests: `Notification::fake()` for "who gets which email". The BR-N6 tests use the
  real `sync` queue and `Event::fake([NotificationSent::class])`, because
  `Notification::fake()` ignores `afterCommit()`.
- Run commands inside the `app` container: `docker compose exec app <command>`. Pint
  `--dirty` does not work in the container (no git); pass the file paths. `vp check
--fix` also formats the code blocks of this plan.

## Review Focus

1. **A rollback after the notify call.** No email. Pinned by the BR-N6 rollback test in
   Task 2.
2. **A cancel that fails a rule** (a second cancel, a started event). No email. Pinned
   by a test in Task 2 that also checks the error toast.
3. **Two emails for one cancel.** Exactly one email after the commit. Pinned by
   `assertDispatchedTimes` in Task 2.
4. **A cancelled event sends `BookingCancelled` too.** Pinned by a test in Task 2
   (`CancelEventTest`).
5. **The wrong person gets the email** (the organizer). Pinned by `assertNotSentTo` in
   Task 2.

---

### Task 1: The `BookingCancelled` notification

**Files:**

- Create: `app/Notifications/BookingCancelled.php` (with
  `php artisan make:notification BookingCancelled --no-interaction`)
- Test: `tests/Feature/BookingCancelledNotificationTest.php` (with
  `php artisan make:test --pest BookingCancelledNotificationTest --no-interaction`)

**Interfaces:**

- Produces: `new BookingCancelled(Booking $booking)`, public readonly `Booking $booking`,
  `via(object): array`, `toMail(object): MailMessage`.

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Models\Booking;
use App\Models\Event;
use App\Models\User;
use App\Notifications\BookingCancelled;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->attendee = User::factory()->create(['name' => 'Ada Lovelace']);

    $event = Event::factory()->published()->create([
        'title' => 'Laravel Meetup',
        'starts_at' => CarbonImmutable::parse('2026-10-12 18:00:00', 'UTC'),
    ]);

    $this->booking = Booking::factory()->cancelled()->for($event)
        ->for($this->attendee, 'attendee')->create(['quantity' => 2]);
});

test('BR-N2: the booking cancelled email has the booking details', function () {
    $mail = (new BookingCancelled($this->booking))->toMail($this->attendee);

    expect($mail->subject)->toBe('Booking cancelled: Laravel Meetup')
        ->and($mail->greeting)->toBe('Hello Ada Lovelace,')
        ->and($mail->introLines)->toBe([
            'Your booking for Laravel Meetup is cancelled.',
            "Reference: {$this->booking->reference}",
            'Seats: 2',
            'Starts: Mon 12 Oct 2026, 18:00 UTC',
        ])
        ->and($mail->actionText)->toBe('View event')
        ->and($mail->actionUrl)->toBe(route('events.show', $this->booking->event));
});

test('BR-N6: the notification is queued after the commit and goes by mail', function () {
    $notification = new BookingCancelled($this->booking);

    expect($notification)->toBeInstanceOf(ShouldQueue::class)
        ->and($notification->afterCommit)->toBeTrue()
        ->and($notification->via($this->attendee))->toBe(['mail']);
});

test('the queued email is tried 3 times with a backoff', function () {
    $job = new SendQueuedNotifications($this->attendee, new BookingCancelled($this->booking), ['mail']);

    expect($job->tries)->toBe(3)
        ->and($job->backoff())->toBe([10, 60]);
});

test('BR-N2: the start time is in UTC when the database session uses another time zone', function () {
    DB::statement("SET LOCAL TIME ZONE 'America/New_York'");

    $mail = (new BookingCancelled($this->booking->fresh()))->toMail($this->attendee);

    expect($mail->introLines)->toContain('Starts: Mon 12 Oct 2026, 18:00 UTC');
});
```

- [ ] **Step 2: Run the test to see it fail**

Run: `docker compose exec app php artisan test --compact tests/Feature/BookingCancelledNotificationTest.php`
Expected: FAIL, class `App\Notifications\BookingCancelled` not found.

- [ ] **Step 3: Write the notification**

```php
<?php

namespace App\Notifications;

use App\Models\Booking;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Queue\Attributes\Backoff;
use Illuminate\Queue\Attributes\Tries;

#[Tries(3)]
#[Backoff([10, 60])]
class BookingCancelled extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * The queue sends the email only after the transaction commits (BR-N6).
     */
    public function __construct(public readonly Booking $booking)
    {
        $this->afterCommit();
    }

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /**
     * Get the mail representation of the notification.
     *
     * @param  User  $notifiable
     */
    public function toMail(object $notifiable): MailMessage
    {
        $event = $this->booking->event;

        return (new MailMessage)
            ->subject("Booking cancelled: {$event->title}")
            ->greeting("Hello {$notifiable->name},")
            ->line("Your booking for {$event->title} is cancelled.")
            ->line("Reference: {$this->booking->reference}")
            ->line("Seats: {$this->booking->quantity}")
            ->line('Starts: '.$event->starts_at->utc()->format('D j M Y, H:i').' UTC')
            ->action('View event', route('events.show', $event));
    }
}
```

- [ ] **Step 4: Run the test to see it pass**

Run: `docker compose exec app php artisan test --compact tests/Feature/BookingCancelledNotificationTest.php`
Expected: PASS (4 tests).

- [ ] **Step 5: Commit**

```bash
docker compose exec app vendor/bin/pint --format agent app/Notifications/BookingCancelled.php tests/Feature/BookingCancelledNotificationTest.php
git add app/Notifications/BookingCancelled.php tests/Feature/BookingCancelledNotificationTest.php
git commit -m "feat: add the BookingCancelled notification"
```

---

### Task 2: `CancelBooking` sends `BookingCancelled` after the commit

**Files:**

- Modify: `app/Actions/CancelBooking/CancelBooking.php`
- Test: `tests/Feature/CancelBookingTest.php`
- Test: `tests/Feature/CancelEventTest.php`

**Interfaces:**

- Consumes: `new BookingCancelled(Booking $booking)` from Task 1.

- [ ] **Step 1: Write the failing tests**

Add to `tests/Feature/CancelBookingTest.php` (and the `use` lines at the top:
`App\Actions\CancelBooking\CancelBooking`, `App\Notifications\BookingCancelled`,
`Illuminate\Notifications\Events\NotificationSent`, `Illuminate\Support\Facades\DB`,
`Illuminate\Support\Facades\Event as EventFacade` and
`Illuminate\Support\Facades\Notification`):

```php
test('BR-N2: the attendee gets a booking cancelled email', function () {
    Notification::fake();

    $this->actingAs($this->attendee)
        ->post(route('bookings.cancellation.store', $this->booking));

    Notification::assertSentTo(
        $this->attendee,
        BookingCancelled::class,
        fn (BookingCancelled $notification) => $notification->booking->is($this->booking),
    );
    Notification::assertNotSentTo($this->organizer, BookingCancelled::class);
    Notification::assertCount(1);
});

test('BR-N2: no email when the cancel fails a rule', function () {
    Notification::fake();

    $this->actingAs($this->attendee)
        ->post(route('bookings.cancellation.store', $this->booking));
    $this->actingAs($this->attendee)
        ->post(route('bookings.cancellation.store', $this->booking))
        ->assertInertiaFlash('toast.message', 'Only a confirmed booking can be cancelled. This booking is cancelled.');

    Notification::assertSentTimes(BookingCancelled::class, 1);
});

test('BR-N6: the cancel email is sent only after the transaction commits', function () {
    EventFacade::fake([NotificationSent::class]);

    DB::transaction(function () {
        app(CancelBooking::class)->handle($this->booking);

        EventFacade::assertNotDispatched(NotificationSent::class);
    });

    EventFacade::assertDispatchedTimes(NotificationSent::class, 1);
    EventFacade::assertDispatched(
        NotificationSent::class,
        fn (NotificationSent $sent) => $sent->notification instanceof BookingCancelled
            && $sent->notifiable->is($this->attendee),
    );
});

test('BR-N6: no cancel email when the transaction rolls back', function () {
    EventFacade::fake([NotificationSent::class]);

    try {
        DB::transaction(function () {
            app(CancelBooking::class)->handle($this->booking);

            throw new RuntimeException('A later step fails.');
        });
    } catch (RuntimeException) {
    }

    EventFacade::assertNotDispatched(NotificationSent::class);
    expect($this->booking->fresh()->isConfirmed())->toBeTrue();
});
```

`beforeEach` of this file calls `$this->freezeSecond()`; the tests above do not depend
on it.

Add to `tests/Feature/CancelEventTest.php` (add `App\Notifications\BookingCancelled`
and `Illuminate\Support\Facades\Notification` to the `use` lines if they are missing).
Read the file's `beforeEach` first and use its names for the organizer, the event and an
attendee with a confirmed booking; create the booking with
`Booking::factory()->for($event)->for($attendee, 'attendee')->create()` if the
`beforeEach` has none:

```php
test('a cancelled event sends no booking cancelled email', function () {
    Notification::fake();

    $this->actingAs($this->organizer)
        ->post(route('events.cancellation.store', $this->event));

    Notification::assertNotSentTo($this->attendee, BookingCancelled::class);
});
```

This test guards the owner decision and passes before Step 3 (`CancelEvent` does not
use `CancelBooking`). Check that the booking really ends cancelled in this test (the
toast or `isCancelled()`), so the test is not empty.

- [ ] **Step 2: Run the tests to see them fail**

Run: `docker compose exec app php artisan test --compact tests/Feature/CancelBookingTest.php --filter=BR-N`
Expected: the BR-N2 tests and the BR-N6 commit test FAIL (no notification). The BR-N6
rollback test passes already; it guards Step 3.

- [ ] **Step 3: Send the notification in the Action**

In `app/Actions/CancelBooking/CancelBooking.php`, after `$booking->save();`:

```php
            $booking->attendee->notify(new BookingCancelled($booking));
```

Add `use App\Notifications\BookingCancelled;`. Add to the docblock of `handle()`:
"The attendee gets a `BookingCancelled` email after the commit (BR-N2, BR-N6)."

- [ ] **Step 4: Run the tests to see them pass**

Run: `docker compose exec app php artisan test --compact tests/Feature/CancelBookingTest.php tests/Feature/CancelEventTest.php`
Expected: PASS (all tests of both files).

- [ ] **Step 5: Commit**

```bash
docker compose exec app vendor/bin/pint --format agent app/Actions/CancelBooking/CancelBooking.php tests/Feature/CancelBookingTest.php tests/Feature/CancelEventTest.php
git add app/Actions/CancelBooking/CancelBooking.php tests/Feature/CancelBookingTest.php tests/Feature/CancelEventTest.php
git commit -m "feat: send BookingCancelled when an attendee cancels a booking"
```

---

### Task 3: README, Mailpit check, handoff and plan

**Files:**

- Modify: `app/Actions/CancelBooking/README.md`
- Modify: `app/Actions/CancelEvent/README.md` (only if it says that attendees get a
  `BookingCancelled` email or leaves it open)
- Modify: `HANDOFF.md`
- Add: `docs/superpowers/plans/2026-10-08-m5-booking-cancelled.md`

- [ ] **Step 1: Update the `CancelBooking` README**

Replace "The `BookingCancelled` email comes in M5." with a short paragraph in Simple
English, like the one in `app/Actions/ReserveSeats/README.md`: after the saves, the
Action sends `BookingCancelled` to the attendee (BR-N2). The queue keeps the job until
the commit and drops it on a rollback (BR-N6). The worker tries 3 times. Add the same
Redis note as in the `ReserveSeats` README (the booking is cancelled, but the request
ends with an error and no email goes out). Say that a cancelled event does not send this
email; it sends `EventCancelled` (M5).

In the diagram "The booking is cancelled", add `participant Queue` (last) and replace
the commit line with:

```text
    Action->>DB: Update the event and the booking
    Action->>Queue: BookingCancelled (held until the commit)
    Action->>DB: Commit
    Queue-->>Queue: Release the job after the commit
```

Keep the wording of the existing update line if it differs; only split off the commit.
Render the diagrams locally:
`npx -y @mermaid-js/mermaid-cli -i app/Actions/CancelBooking/README.md -o <scratch-dir>/cancel-booking.md`
Expected: all diagrams render, no errors.

- [ ] **Step 2: Check the email in Mailpit (owner)**

Log in as `attendee@example.com` (password `password`), open "My bookings" and cancel a
confirmed booking of an event that has not started. Open Mailpit at
`http://localhost:8025`. Expected: one email "Booking cancelled: {title}" with the
reference, the seats, the start time in UTC and a "View event" button. The owner does
this check; the implementer asks for it and waits.

- [ ] **Step 3: Format and run all checks**

Run: `npm run check:fix`
Run: `docker compose exec app composer ci:check`
Expected: Pint, PHPStan, `vp check`, `tsc` and all tests pass.

- [ ] **Step 4: Update `HANDOFF.md`**

"Where we are": PR 1 is merged (#37). The current PR is M5 PR 2
(`feat/booking-cancelled-email`), with the path of this plan. Write that it is open as
a PR and waits for the merge (no PR number). Keep the notification pattern. Add one
line: `CancelBooking` sends `BookingCancelled`; `CancelEvent` does not use
`CancelBooking`, so a cancelled event sends no `BookingCancelled`. "Next steps": the
owner reviews this PR. Then M5 PR 3 (`EventCancelled` from `CancelEvent`).

- [ ] **Step 5: Commit**

```bash
git add app/Actions/CancelBooking/README.md HANDOFF.md \
  docs/superpowers/plans/2026-10-08-m5-booking-cancelled.md
git commit -m "docs: document the BookingCancelled email and update handoff"
```

Add `app/Actions/CancelEvent/README.md` to `git add` if Step 1 changed it.
