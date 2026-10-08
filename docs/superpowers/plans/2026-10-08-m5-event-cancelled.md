# M5 — EventCancelled: Attendees Get an Email When an Event Is Cancelled

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** When an event is cancelled, each attendee with a confirmed booking gets an
"event cancelled" email (BR-N3, the email part of BR-E13). The emails go out only after
the transaction commits (BR-N6).

**Architecture:** `App\Notifications\EventCancelled` follows the pattern of
`BookingConfirmed` and `BookingCancelled`. It takes the cancelled `Booking`, so the
email can show the reference and the seats. `CancelEvent` loads the confirmed bookings
with their attendees (no N+1) and calls `notify()` for each booking inside the
transaction, after the saves. Bookings that were cancelled before the event cancel get
no email.

**Tech Stack:** Laravel 13 notifications and queues, Pest.

**Spec:** `docs/superpowers/specs/2026-09-28-event-booking-design.md` (sections 4, 7
and 9), `docs/design/business-rules.md` (BR-E13, BR-N3, BR-N6),
`docs/superpowers/plans/milestones.md` (M5).

## Global Constraints

- The notification pattern of M5 (see `HANDOFF.md` and
  `app/Notifications/BookingCancelled.php`):
    - `implements ShouldQueue`, `use Queueable`, `$this->afterCommit()` in the
      constructor (BR-N6).
    - `#[Tries(3)]` and `#[Backoff([10, 60])]` on the class.
    - `via()` returns `['mail']`. `toMail()` uses the default `MailMessage`, with a
      `@param User $notifiable` docblock.
    - Times use `->utc()->format('D j M Y, H:i')` and the suffix ` UTC`.
- The Action sends the notifications inside the transaction, after the saves.
- One booking for each attendee and event (BR-B4), so one email for each confirmed
  booking is one email for each attendee.
- `EventCancelled` content (the organizer or an admin can cancel, so the text names
  no person):
    - Subject: `Event cancelled: {event title}`
    - Greeting: `Hello {attendee name},`
    - Lines: `{event title} is cancelled.`, `Your booking is cancelled too.`,
      `Reference: {reference}`, `Seats: {quantity}`, `Starts: {starts_at}`
    - Action: `Find other events`, to `route('events.index')`
- The attendees get no `BookingCancelled` email (owner decision, guarded by the test
  "a cancelled event sends no booking cancelled email" in `CancelEventTest`).
- Tests: `Notification::fake()` for "who gets which email". The BR-N6 tests use the
  real `sync` queue and `Event::fake([NotificationSent::class])`. Rollback tests throw
  and catch a `LogicException` and check its message, so a domain exception of the
  Action cannot make them pass.
- Run commands inside the `app` container: `docker compose exec app <command>`. Pint
  `--dirty` does not work in the container (no git); pass the file paths. `vp check
--fix` also formats the code blocks of this plan.

## Review Focus

1. **A booking that was cancelled before the event cancel.** Its attendee gets no
   email. Pinned by a test in Task 2 (`$this->earlierCancel`).
2. **The organizer gets an email.** The organizer has no booking and gets nothing.
   Pinned by `assertNotSentTo` in Task 2.
3. **A rollback after the notify calls.** No email. Pinned by the BR-N6 rollback test in
   Task 2.
4. **A cancel that fails a rule** (a started event, a second cancel). No email. Pinned
   by tests in Task 2.
5. **One query for each attendee** (N+1) inside the locked transaction. Pinned by the
   eager load in Task 2 and checked in the final review; no test counts the queries.

---

### Task 1: The `EventCancelled` notification

**Files:**

- Create: `app/Notifications/EventCancelled.php` (with
  `php artisan make:notification EventCancelled --no-interaction`)
- Test: `tests/Feature/EventCancelledNotificationTest.php` (with
  `php artisan make:test --pest EventCancelledNotificationTest --no-interaction`)

**Interfaces:**

- Produces: `new EventCancelled(Booking $booking)`, public readonly `Booking $booking`,
  `via(object): array`, `toMail(object): MailMessage`.

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Models\Booking;
use App\Models\Event;
use App\Models\User;
use App\Notifications\EventCancelled;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->attendee = User::factory()->create(['name' => 'Ada Lovelace']);

    $event = Event::factory()->cancelled()->create([
        'title' => 'Laravel Meetup',
        'starts_at' => CarbonImmutable::parse('2026-10-12 18:00:00', 'UTC'),
    ]);

    $this->booking = Booking::factory()->cancelled()->for($event)
        ->for($this->attendee, 'attendee')->create(['quantity' => 2]);
});

test('BR-N3: the event cancelled email has the event and booking details', function () {
    $mail = (new EventCancelled($this->booking))->toMail($this->attendee);

    expect($mail->subject)->toBe('Event cancelled: Laravel Meetup')
        ->and($mail->greeting)->toBe('Hello Ada Lovelace,')
        ->and($mail->introLines)->toBe([
            'Laravel Meetup is cancelled.',
            'Your booking is cancelled too.',
            "Reference: {$this->booking->reference}",
            'Seats: 2',
            'Starts: Mon 12 Oct 2026, 18:00 UTC',
        ])
        ->and($mail->actionText)->toBe('Find other events')
        ->and($mail->actionUrl)->toBe(route('events.index'));
});

test('BR-N6: the notification is queued after the commit and goes by mail', function () {
    $notification = new EventCancelled($this->booking);

    expect($notification)->toBeInstanceOf(ShouldQueue::class)
        ->and($notification->afterCommit)->toBeTrue()
        ->and($notification->via($this->attendee))->toBe(['mail']);
});

test('the queued email is tried 3 times with a backoff', function () {
    $job = new SendQueuedNotifications($this->attendee, new EventCancelled($this->booking), ['mail']);

    expect($job->tries)->toBe(3)
        ->and($job->backoff())->toBe([10, 60]);
});

test('BR-N3: the start time is in UTC when the database session uses another time zone', function () {
    DB::statement("SET LOCAL TIME ZONE 'America/New_York'");

    $mail = (new EventCancelled($this->booking->fresh()))->toMail($this->attendee);

    expect($mail->introLines)->toContain('Starts: Mon 12 Oct 2026, 18:00 UTC');
});
```

Check that `EventFactory` has a `cancelled()` state (M4 PR 7 added it). If its name
differs, use the existing name.

- [ ] **Step 2: Run the test to see it fail**

Run: `docker compose exec app php artisan test --compact tests/Feature/EventCancelledNotificationTest.php`
Expected: FAIL, class `App\Notifications\EventCancelled` not found.

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
class EventCancelled extends Notification implements ShouldQueue
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
            ->subject("Event cancelled: {$event->title}")
            ->greeting("Hello {$notifiable->name},")
            ->line("{$event->title} is cancelled.")
            ->line('Your booking is cancelled too.')
            ->line("Reference: {$this->booking->reference}")
            ->line("Seats: {$this->booking->quantity}")
            ->line('Starts: '.$event->starts_at->utc()->format('D j M Y, H:i').' UTC')
            ->action('Find other events', route('events.index'));
    }
}
```

- [ ] **Step 4: Run the test to see it pass**

Run: `docker compose exec app php artisan test --compact tests/Feature/EventCancelledNotificationTest.php`
Expected: PASS (4 tests).

- [ ] **Step 5: Commit**

```bash
docker compose exec app vendor/bin/pint --format agent app/Notifications/EventCancelled.php tests/Feature/EventCancelledNotificationTest.php
git add app/Notifications/EventCancelled.php tests/Feature/EventCancelledNotificationTest.php
git commit -m "feat: add the EventCancelled notification"
```

---

### Task 2: `CancelEvent` sends `EventCancelled` after the commit

**Files:**

- Modify: `app/Actions/CancelEvent/CancelEvent.php`
- Test: `tests/Feature/CancelEventTest.php`

**Interfaces:**

- Consumes: `new EventCancelled(Booking $booking)` from Task 1.

The `beforeEach` of `CancelEventTest` has `$this->organizer`, `$this->event` (published,
capacity 10), two confirmed bookings `$this->first` and `$this->second` (each with its
own attendee from the factory), and `$this->earlierCancel` (cancelled one day before).

- [ ] **Step 1: Write the failing tests**

Add to `tests/Feature/CancelEventTest.php` (and the `use` lines:
`App\Actions\CancelEvent\CancelEvent`, `App\Notifications\EventCancelled`,
`Illuminate\Notifications\Events\NotificationSent`, `Illuminate\Support\Facades\DB` and
`Illuminate\Support\Facades\Event as EventFacade`):

```php
test('BR-N3: each attendee with a confirmed booking gets an event cancelled email', function () {
    Notification::fake();

    $this->actingAs($this->organizer)
        ->post(route('events.cancellation.store', $this->event));

    Notification::assertSentTo(
        $this->first->attendee,
        EventCancelled::class,
        fn (EventCancelled $notification) => $notification->booking->is($this->first),
    );
    Notification::assertSentTo(
        $this->second->attendee,
        EventCancelled::class,
        fn (EventCancelled $notification) => $notification->booking->is($this->second),
    );
    Notification::assertNotSentTo($this->earlierCancel->attendee, EventCancelled::class);
    Notification::assertNotSentTo($this->organizer, EventCancelled::class);
    Notification::assertCount(2);
});

test('BR-N3: an event with no confirmed bookings sends no email', function () {
    Notification::fake();
    $draft = Event::factory()->for($this->organizer, 'organizer')->create();

    $this->actingAs($this->organizer)
        ->post(route('events.cancellation.store', $draft))
        ->assertInertiaFlash('toast.message', 'Event cancelled.');

    Notification::assertNothingSent();
});

test('BR-N3: no email when the cancel fails a rule', function () {
    Notification::fake();
    $this->event->forceFill(['starts_at' => now()->subHour()])->save();

    $this->actingAs($this->organizer)
        ->post(route('events.cancellation.store', $this->event))
        ->assertInertiaFlash('toast.message', 'The event has started, so it cannot be cancelled.');

    Notification::assertNothingSent();
});

test('BR-N6: the event cancelled emails are sent only after the transaction commits', function () {
    EventFacade::fake([NotificationSent::class]);

    DB::transaction(function () {
        app(CancelEvent::class)->handle($this->event);

        EventFacade::assertNotDispatched(NotificationSent::class);
    });

    EventFacade::assertDispatchedTimes(NotificationSent::class, 2);
    EventFacade::assertDispatched(
        NotificationSent::class,
        fn (NotificationSent $sent) => $sent->notification instanceof EventCancelled
            && $sent->notifiable->is($this->first->attendee),
    );
});

test('BR-N6: no event cancelled email when the transaction rolls back', function () {
    EventFacade::fake([NotificationSent::class]);

    try {
        DB::transaction(function () {
            app(CancelEvent::class)->handle($this->event);

            throw new LogicException('A later step fails.');
        });
    } catch (LogicException $exception) {
        expect($exception->getMessage())->toBe('A later step fails.');
    }

    EventFacade::assertNotDispatched(NotificationSent::class);
    expect($this->event->fresh()->isPublished())->toBeTrue();
});
```

Check the draft toast in the existing test "the toast says "booking" for one booking and
nothing for no bookings" and use the same text. If `Event::factory()` without a state
is not a draft, use the draft state the file already uses.

- [ ] **Step 2: Run the tests to see them fail**

Run: `docker compose exec app php artisan test --compact tests/Feature/CancelEventTest.php --filter=BR-N`
Expected: the first BR-N3 test and the BR-N6 commit test FAIL (no notification). The
"no confirmed bookings", "fails a rule" and rollback tests pass already; they guard
Step 3.

- [ ] **Step 3: Send the notifications in the Action**

In `app/Actions/CancelEvent/CancelEvent.php`:

1. Eager load the attendees on the bookings query: add `->with('attendee')` before
   `->lockForUpdate()`.
2. After `$event->save();`, before the `return`:

```php
            $bookings->each(
                fn (Booking $booking) => $booking->attendee->notify(new EventCancelled($booking)),
            );
```

Add `use App\Notifications\EventCancelled;`. Add to the docblock of `handle()`: "Each
attendee of a cancelled booking gets an `EventCancelled` email after the commit (BR-N3,
BR-N6)."

- [ ] **Step 4: Run the tests to see them pass**

Run: `docker compose exec app php artisan test --compact tests/Feature/CancelEventTest.php`
Expected: PASS (all tests of the file, also "a cancelled event sends no booking
cancelled email").

- [ ] **Step 5: Commit**

```bash
docker compose exec app vendor/bin/pint --format agent app/Actions/CancelEvent/CancelEvent.php tests/Feature/CancelEventTest.php
git add app/Actions/CancelEvent/CancelEvent.php tests/Feature/CancelEventTest.php
git commit -m "feat: send EventCancelled to the attendees of a cancelled event"
```

---

### Task 3: READMEs, BR-E13 and BR-N3 check, handoff and plan

**Files:**

- Modify: `app/Actions/CancelEvent/README.md`
- Modify: `app/Actions/CancelBooking/README.md` (the sentence "They get the
  `EventCancelled` email (M5)." loses "(M5)")
- Modify: `HANDOFF.md`
- Add: `docs/superpowers/plans/2026-10-08-m5-event-cancelled.md`

- [ ] **Step 1: Update the `CancelEvent` README**

Replace "The `EventCancelled` emails to the attendees come in M5." with a short paragraph
in Simple English, like the one in `app/Actions/CancelBooking/README.md`: the Action
loads the confirmed bookings with their attendees. After the saves, it sends
`EventCancelled` to the attendee of each booking that it cancelled (BR-N3). Bookings
that were cancelled before get no email. The attendees get no `BookingCancelled` email.
The queue keeps the jobs until the commit and drops them on a rollback (BR-N6). The
worker tries each email 3 times. Add the Redis note: if Redis fails right after the
commit, the event is cancelled, but the request ends with an error and some or all
emails do not go out.

In the diagram "The event is cancelled", add `participant Queue` (last) and split the
commit from the update line, then add the queue lines before the commit:

```text
    Action->>Queue: EventCancelled for each cancelled booking (held until the commit)
    Action->>DB: Commit
    Queue-->>Queue: Release the jobs after the commit
```

Keep the existing wording of the update line; only split off the commit.
Render the diagrams locally:
`npx -y @mermaid-js/mermaid-cli -i app/Actions/CancelEvent/README.md -o <scratch-dir>/cancel-event.md`
Expected: all diagrams render, no errors.

- [ ] **Step 2: Check BR-E13 and BR-N3**

Run: `grep -rhoE "BR-(E13|N[1-3]|N6)\b" tests | sort -u`
Expected: BR-E13, BR-N1, BR-N2, BR-N3 and BR-N6.

- [ ] **Step 3: Check the email in Mailpit (owner)**

Log in as `organizer@example.com` (password `password`). Cancel a published event that
has a confirmed booking of `attendee@example.com` (book one first as the attendee if
there is none). Open Mailpit at `http://localhost:8025`. Expected: one email "Event
cancelled: {title}" to `attendee@example.com` with the reference, the seats, the start
time in UTC and a "Find other events" button, and no "Booking cancelled" email for it.
The owner does this check; the implementer asks for it and waits.

- [ ] **Step 4: Format and run all checks**

Run: `npm run check:fix`
Run: `docker compose exec app composer ci:check`
Expected: Pint, PHPStan, `vp check`, `tsc` and all tests pass.

- [ ] **Step 5: Update `HANDOFF.md`**

"Where we are": PR 2 is merged (#38). The current PR is M5 PR 3
(`feat/event-cancelled-email`), with the path of this plan. Write that it is open as a
PR and waits for the merge (no PR number). Keep the notification pattern; add the
`LogicException` rule for rollback tests to it. Replace the PR 2 line with one line:
`CancelEvent` sends `EventCancelled` to the attendee of each booking that it cancels,
and no `BookingCancelled`. With this PR, BR-E13 is complete (also the email part).
"Next steps": the owner reviews this PR. Then M5 PR 4 (reminders).

- [ ] **Step 6: Commit**

```bash
git add app/Actions/CancelEvent/README.md app/Actions/CancelBooking/README.md HANDOFF.md \
  docs/superpowers/plans/2026-10-08-m5-event-cancelled.md
git commit -m "docs: document the EventCancelled email and update handoff"
```
