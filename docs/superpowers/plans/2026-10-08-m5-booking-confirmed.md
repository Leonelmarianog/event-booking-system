# M5 — BookingConfirmed: the Attendee Gets an Email for a New Booking

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** When a booking is confirmed, the attendee gets a "booking confirmed" email
(BR-N1). The email goes out only after the transaction commits (BR-N6). This PR sets the
pattern for the other three notifications of M5.

**Architecture:** `App\Notifications\BookingConfirmed` is a queued mail notification. It
calls `afterCommit()` in its constructor, because the queue connections have
`after_commit => false`. It tries 3 times with a backoff. `ReserveSeats` calls
`$attendee->notify(...)` inside its transaction, after the saves. The queue keeps the job
until the commit and drops it on a rollback. The worker sends the email with the default
Laravel mail layout (`MailMessage`, no custom template).

**Tech Stack:** Laravel 13 notifications and queues, Pest.

**Spec:** `docs/superpowers/specs/2026-09-28-event-booking-design.md` (sections 4, 7
and 9), `docs/design/business-rules.md` (BR-N1, BR-N6),
`docs/superpowers/plans/milestones.md` (M5).

## Global Constraints

- M5 is split into four PRs: 1 `BookingConfirmed` (this PR), 2 `BookingCancelled`,
  3 `EventCancelled`, 4 reminders (`reminder_sent_at`, `SendEventReminders`, daily at
  08:00 UTC, `EventReminder`).
- Each notification of M5 follows the same pattern:
    - `implements ShouldQueue`, `use Queueable`.
    - `$this->afterCommit()` in the constructor (BR-N6).
    - `#[Tries(3)]` and `#[Backoff([10, 60])]` on the class (spec section 7: 3 tries
      with backoff). The class values win over `--tries` of the worker.
    - `via()` returns `['mail']`.
    - `toMail()` uses the default `MailMessage`: a subject, a greeting, lines, one
      action button. No Markdown template, no published mail views.
    - Times in the email use `D j M Y, H:i` and the suffix ` UTC`, for example
      `Mon 12 Oct 2026, 18:00 UTC`.
- The Action sends the notification inside the transaction, after the saves. Because
  of `afterCommit()`, the queue holds the job until the commit and drops it on a
  rollback.
- `BookingConfirmed` content:
    - Subject: `Booking confirmed: {event title}`
    - Greeting: `Hello {attendee name},`
    - Lines: `Your booking for {event title} is confirmed.`, `Reference: {reference}`,
      `Seats: {quantity}`, `Starts: {starts_at}`, `Venue: {venue}`
    - Action: `View event`, to `route('events.show', $event)`
- `Notification::fake()` does not follow `afterCommit()`. The BR-N6 tests use the real
  `sync` queue of the test environment (`QUEUE_CONNECTION=sync`, `MAIL_MAILER=array`)
  and `Event::fake([NotificationSent::class])`. `RefreshDatabase` makes the queue treat
  the test transaction as the base level, so a commit inside a test runs the
  after-commit callbacks.
- Run commands inside the `app` container: `docker compose exec app <command>`. Pint
  `--dirty` does not work in the container (no git); pass the file paths. `vp check
--fix` also formats the code blocks of this plan.

## Review Focus

1. **A rollback after the notify call.** A caller wraps `ReserveSeats` in a larger
   transaction that fails later. No email goes out. Pinned by the BR-N6 rollback test in
   Task 2.
2. **A booking that fails a rule** (`NotEnoughSeats`, `AlreadyBooked`,
   `EventNotBookable`). No email. Pinned by a test in Task 2.
3. **The email before the commit.** Inside an open transaction, nothing is sent yet;
   after the commit, one email is sent. Pinned by the BR-N6 commit test in Task 2.
4. **The wrong person gets the email** (for example the organizer). Pinned by
   `assertNotSentTo` in Task 2.
5. **A notification that is not queued**, so a slow mail server slows the booking
   request. Pinned by the `ShouldQueue` and `afterCommit` assertions in Task 1.

---

### Task 1: The `BookingConfirmed` notification

**Files:**

- Create: `app/Notifications/BookingConfirmed.php` (with
  `php artisan make:notification BookingConfirmed --no-interaction`)
- Test: `tests/Feature/BookingConfirmedNotificationTest.php` (with
  `php artisan make:test --pest BookingConfirmedNotificationTest --no-interaction`)

**Interfaces:**

- Produces: `new BookingConfirmed(Booking $booking)`, `via(object): array`,
  `toMail(object): MailMessage`, public readonly `Booking $booking`.

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Models\Booking;
use App\Models\Event;
use App\Models\User;
use App\Notifications\BookingConfirmed;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;

beforeEach(function () {
    $this->attendee = User::factory()->create(['name' => 'Ada Lovelace']);

    $event = Event::factory()->published()->create([
        'title' => 'Laravel Meetup',
        'venue' => 'Main Hall',
        'starts_at' => CarbonImmutable::parse('2026-10-12 18:00:00', 'UTC'),
    ]);

    $this->booking = Booking::factory()->for($event)->for($this->attendee, 'attendee')
        ->create(['quantity' => 2]);
});

test('BR-N1: the booking confirmed email has the booking details', function () {
    $mail = (new BookingConfirmed($this->booking))->toMail($this->attendee);

    expect($mail->subject)->toBe('Booking confirmed: Laravel Meetup')
        ->and($mail->greeting)->toBe('Hello Ada Lovelace,')
        ->and($mail->introLines)->toBe([
            'Your booking for Laravel Meetup is confirmed.',
            "Reference: {$this->booking->reference}",
            'Seats: 2',
            'Starts: Mon 12 Oct 2026, 18:00 UTC',
            'Venue: Main Hall',
        ])
        ->and($mail->actionText)->toBe('View event')
        ->and($mail->actionUrl)->toBe(route('events.show', $this->booking->event));
});

test('BR-N6: the notification is queued after the commit and goes by mail', function () {
    $notification = new BookingConfirmed($this->booking);

    expect($notification)->toBeInstanceOf(ShouldQueue::class)
        ->and($notification->afterCommit)->toBeTrue()
        ->and($notification->via($this->attendee))->toBe(['mail']);
});
```

Check the `Booking` factory first (`database/factories/BookingFactory.php`): if the
relations or states have other names, use them.

- [ ] **Step 2: Run the test to see it fail**

Run: `docker compose exec app php artisan test --compact tests/Feature/BookingConfirmedNotificationTest.php`
Expected: FAIL, class `App\Notifications\BookingConfirmed` not found.

- [ ] **Step 3: Write the notification**

```php
<?php

namespace App\Notifications;

use App\Models\Booking;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Queue\Attributes\Backoff;
use Illuminate\Queue\Attributes\Tries;

#[Tries(3)]
#[Backoff([10, 60])]
class BookingConfirmed extends Notification implements ShouldQueue
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
     */
    public function toMail(object $notifiable): MailMessage
    {
        $event = $this->booking->event;

        return (new MailMessage)
            ->subject("Booking confirmed: {$event->title}")
            ->greeting("Hello {$notifiable->name},")
            ->line("Your booking for {$event->title} is confirmed.")
            ->line("Reference: {$this->booking->reference}")
            ->line("Seats: {$this->booking->quantity}")
            ->line('Starts: '.$event->starts_at->format('D j M Y, H:i').' UTC')
            ->line("Venue: {$event->venue}")
            ->action('View event', route('events.show', $event));
    }
}
```

Remove the `toArray()` method that `make:notification` writes; the notification has no
database channel. Keep the `object $notifiable` signature that `make:notification`
writes. If PHPStan (level 7) complains about `$notifiable->name`, add
`@param User $notifiable` to the docblock of `toMail()` and import `App\Models\User`.

- [ ] **Step 4: Run the test to see it pass**

Run: `docker compose exec app php artisan test --compact tests/Feature/BookingConfirmedNotificationTest.php`
Expected: PASS (2 tests).

- [ ] **Step 5: Commit**

```bash
docker compose exec app vendor/bin/pint --format agent app/Notifications/BookingConfirmed.php tests/Feature/BookingConfirmedNotificationTest.php
git add app/Notifications/BookingConfirmed.php tests/Feature/BookingConfirmedNotificationTest.php
git commit -m "feat: add the BookingConfirmed notification"
```

---

### Task 2: `ReserveSeats` sends `BookingConfirmed` after the commit

**Files:**

- Modify: `app/Actions/ReserveSeats/ReserveSeats.php`
- Test: `tests/Feature/ReserveSeatsTest.php`

**Interfaces:**

- Consumes: `new BookingConfirmed(Booking $booking)` from Task 1.

- [ ] **Step 1: Write the failing tests**

Add to `tests/Feature/ReserveSeatsTest.php` (and the `use` lines at the top:
`App\Actions\ReserveSeats\ReserveSeats`, `App\Notifications\BookingConfirmed`,
`Illuminate\Notifications\Events\NotificationSent`, `Illuminate\Support\Facades\DB`,
`Illuminate\Support\Facades\Event as EventFacade` — the alias, because `App\Models\Event`
is already imported — and `Illuminate\Support\Facades\Notification`):

```php
test('BR-N1: the attendee gets a booking confirmed email', function () {
    Notification::fake();

    $this->actingAs($this->attendee)
        ->post(route('events.bookings.store', $this->event), ['quantity' => 2]);

    $booking = Booking::sole();

    Notification::assertSentTo(
        $this->attendee,
        BookingConfirmed::class,
        fn (BookingConfirmed $notification) => $notification->booking->is($booking),
    );
    Notification::assertNotSentTo($this->organizer, BookingConfirmed::class);
    Notification::assertCount(1);
});

test('BR-N1: no email when the booking fails a rule', function () {
    Notification::fake();

    $this->actingAs($this->attendee)
        ->post(route('events.bookings.store', $this->event), ['quantity' => 2]);
    $this->actingAs($this->attendee)
        ->post(route('events.bookings.store', $this->event), ['quantity' => 1]);

    Notification::assertSentTimes(BookingConfirmed::class, 1);
});

test('BR-N6: the email is sent only after the transaction commits', function () {
    EventFacade::fake([NotificationSent::class]);

    DB::transaction(function () {
        app(ReserveSeats::class)->handle($this->event, $this->attendee, 1);

        EventFacade::assertNotDispatched(NotificationSent::class);
    });

    EventFacade::assertDispatched(
        NotificationSent::class,
        fn (NotificationSent $sent) => $sent->notification instanceof BookingConfirmed
            && $sent->notifiable->is($this->attendee),
    );
});

test('BR-N6: no email when the transaction rolls back', function () {
    EventFacade::fake([NotificationSent::class]);

    try {
        DB::transaction(function () {
            app(ReserveSeats::class)->handle($this->event, $this->attendee, 1);

            throw new RuntimeException('A later step fails.');
        });
    } catch (RuntimeException) {
    }

    EventFacade::assertNotDispatched(NotificationSent::class);
    expect(Booking::count())->toBe(0);
});
```

The second BR-N1 test uses `AlreadyBooked` (the same user books two times). If the file
already uses another style for a second request (for example a helper), follow it.

- [ ] **Step 2: Run the tests to see them fail**

Run: `docker compose exec app php artisan test --compact tests/Feature/ReserveSeatsTest.php --filter=BR-N`
Expected: the BR-N1 tests and the BR-N6 commit test FAIL (no notification). The BR-N6
rollback test passes already; that is expected, it guards the change in Step 3.

- [ ] **Step 3: Send the notification in the Action**

In `app/Actions/ReserveSeats/ReserveSeats.php`, after `$booking->save();`:

```php
            $attendee->notify(new BookingConfirmed($booking));
```

Add `use App\Notifications\BookingConfirmed;`. Add to the docblock of `handle()`:
"The attendee gets a `BookingConfirmed` email after the commit (BR-N1, BR-N6)."

- [ ] **Step 4: Run the tests to see them pass**

Run: `docker compose exec app php artisan test --compact tests/Feature/ReserveSeatsTest.php`
Expected: PASS (all tests of the file).

Also run: `docker compose exec app php artisan test --compact tests/Feature/BookingsRateLimitTest.php tests/Feature/EventReserveTest.php`
Expected: PASS. The sync queue now sends a real email to the `array` mailer during these
tests; no test needs a change.

- [ ] **Step 5: Commit**

```bash
docker compose exec app vendor/bin/pint --format agent app/Actions/ReserveSeats/ReserveSeats.php tests/Feature/ReserveSeatsTest.php
git add app/Actions/ReserveSeats/ReserveSeats.php tests/Feature/ReserveSeatsTest.php
git commit -m "feat: send BookingConfirmed when a booking is made"
```

---

### Task 3: README, Mailpit check, handoff and plan

**Files:**

- Modify: `app/Actions/ReserveSeats/README.md`
- Modify: `HANDOFF.md`
- Add: `docs/superpowers/plans/2026-10-08-m5-booking-confirmed.md`

- [ ] **Step 1: Update the `ReserveSeats` README**

Replace "The `BookingConfirmed` email comes in M5." with a short paragraph in Simple
English: after the saves, the Action sends `BookingConfirmed` to the attendee (BR-N1).
The notification is queued and marked "after commit", so the queue keeps the job until
the transaction commits. On a rollback, the queue drops the job and no email goes out
(BR-N6). The worker sends the email; it tries 3 times.

In the diagram "The seats are booked", add `participant Queue` and, after
`Action->>DB: Update the event, insert the booking, commit`, change the order so that
the notify call is in the transaction:

```text
    Action->>DB: Update the event, insert the booking
    Action->>Queue: BookingConfirmed (held until the commit)
    Action->>DB: Commit
    Queue-->>Queue: Release the job after the commit
```

Do not add the email to the error diagrams; nothing changes there.

Render the diagrams locally:
`npx -y @mermaid-js/mermaid-cli -i app/Actions/ReserveSeats/README.md -o <scratch-dir>/reserve-seats.md`
Expected: four SVG files and no errors.

- [ ] **Step 2: Check the email in Mailpit (owner)**

With the local stack up (`docker compose up -d`, the `worker` service runs
`queue:listen`), log in as `attendee@example.com` (password `password`) and book a
seat on a published event. Open Mailpit at `http://localhost:8025`. Expected: one
email "Booking confirmed: {title}" with the reference, the seats, the start time, the
venue and a "View event" button. The owner does this check; the implementer asks for
it and waits.

- [ ] **Step 3: Format and run all checks**

Run: `npm run check:fix`
Run: `docker compose exec app composer ci:check`
Expected: Pint, PHPStan, `vp check`, `tsc` and all tests pass.

- [ ] **Step 4: Update `HANDOFF.md`**

"Where we are": M4 is complete (PR 7 merged as #36). M5 (Notifications) is split into
the four PRs of the Global Constraints. The current PR is M5 PR 1
(`feat/booking-confirmed-email`), with the path of this plan. Write that it is open as
a PR and waits for the merge (no PR number, so that the branch needs only one push).
Replace the M4 PR 7 notes at the top with the notification pattern from the Global
Constraints (the next three PRs follow it) and the test note about
`Notification::fake()` and `afterCommit()`. Keep the M4 PR 7 notes shortened under
"M4 PR 7 notes:" and keep the general notes. "Next steps": the owner reviews this PR.
Then M5 PR 2 (`BookingCancelled` from `CancelBooking`).

- [ ] **Step 5: Commit**

```bash
git add app/Actions/ReserveSeats/README.md HANDOFF.md \
  docs/superpowers/plans/2026-10-08-m5-booking-confirmed.md
git commit -m "docs: document the BookingConfirmed email and update handoff"
```
