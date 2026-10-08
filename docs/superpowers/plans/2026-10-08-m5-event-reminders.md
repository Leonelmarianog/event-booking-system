# M5 — Event Reminders: Attendees Get an Email Before the Event Starts

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Each day at 08:00 UTC, attendees with a confirmed booking for an event that
starts in the next 24 hours get a reminder email (BR-N4). The reminder for an event goes
out only one time; the event stores the send time in `reminder_sent_at` (BR-N5). This is
the last PR of M5: with it, every rule BR-N1 to BR-N7 has a test.

**Architecture:** A migration adds `events.reminder_sent_at`. The `Event` model gets the
scope `dueForReminder()`, `needsReminder()` and `markReminderSent()`. The
`SendEventReminders` Action reads the IDs of the due events, then handles each event in
its own transaction: it locks the event row, checks `needsReminder()` again, marks the
reminder as sent, and sends `EventReminder` to the attendee of each confirmed booking.
The `events:send-reminders` command calls the Action. `routes/console.php` schedules it
daily at 08:00 UTC with `withoutOverlapping()`. The `scheduler` service of Docker
Compose already runs `schedule:work`.

**Tech Stack:** Laravel 13 migrations, notifications, queues, console commands and the
scheduler, Pest.

**Spec:** `docs/superpowers/specs/2026-09-28-event-booking-design.md` (sections 4, 5, 7
and 9), `docs/design/business-rules.md` (BR-N4 to BR-N7),
`docs/design/c4-containers.md` (Scheduler), `docs/design/erd.md`
(`events.reminder_sent_at`), `docs/superpowers/plans/milestones.md` (M5).

## Global Constraints

- The migration adds `reminder_sent_at` (`timestampTz`, nullable) to `events`. Do not
  run `migrate:fresh` on the development database; `php artisan migrate` is enough.
- An event is due for a reminder when all of these are true: the status is `published`,
  the event has not started (`starts_at > now`), `starts_at <= now + 24 hours`, and
  `reminder_sent_at` is null. The scope and `needsReminder()` use the same checks.
- Owner decisions:
    - A due event with no confirmed bookings is also marked as sent. Each due event is
      handled one time.
    - When the organizer changes the start time after the reminder, there is no second
      reminder (BR-N5, BR-N7). `UpdateEvent` does not change `reminder_sent_at`.
    - This PR adds the BR-N7 test: a start time change sends no email.
- Lock order: the Action locks the event row only. `ReserveSeats`, `CancelBooking` and
  `CancelEvent` lock the event row first, so the confirmed bookings cannot change while
  the Action holds the lock. Bookings are read without a lock, with their attendees
  (`->with('attendee')`, no N+1).
- One transaction for each event (spec section 5), so an error on one event does not
  undo the others. `handle()` wraps each event in `rescue()`: an error on one event is
  reported (logged) and the run goes on with the next event. Without it, one event that
  always fails would stop the reminders of all later events on every run.
- The notification pattern of M5 (see `HANDOFF.md` and
  `app/Notifications/EventCancelled.php`): `ShouldQueue`, `afterCommit()` in the
  constructor, `#[Tries(3)]`, `#[Backoff([10, 60])]`, `['mail']`, the default
  `MailMessage` with a `@param User $notifiable` docblock, times with
  `->utc()->format('D j M Y, H:i')` and ` UTC`.
- `EventReminder` content:
    - Subject: `Reminder: {event title}`
    - Greeting: `Hello {attendee name},`
    - Lines: `{event title} starts soon.`, `Starts: {starts_at}`, `Venue: {venue}`,
      `Reference: {reference}`, `Seats: {quantity}`
    - Action: `View event`, to `route('events.show', $event)`
- The command signature is `events:send-reminders`, with the description `Send the
reminder emails for the events that start in the next 24 hours`. It prints
  `Reminders sent for 1 event.` or `Reminders sent for N events.` (also for 0 events).
- The schedule: `Schedule::command(SendEventReminders::class)->dailyAt('08:00')
->timezone('UTC')->withoutOverlapping()`.
- Tests: rollback tests throw and catch a `LogicException`; the BR-N6 test uses the
  real `sync` queue and `Event::fake([NotificationSent::class])`.
- Run commands inside the `app` container: `docker compose exec app <command>`. Pint
  `--dirty` does not work in the container (no git); pass the file paths. `vp check
--fix` also formats the code blocks of this plan.

- Change after the final review (owner decision): the command runs each hour with
  `->hourly()->withoutOverlapping(60)`, not daily at 08:00 UTC. BR-N4 says "each hour".

## Review Focus

1. **A run at the same time as another run, or a second run on the same day.** The
   event gets one reminder only. Pinned by the "second run" test in Task 3 and by the
   check under the lock; `withoutOverlapping()` is pinned in Task 4.
2. **An event that is cancelled between the ID query and the lock.** No reminder. Pinned
   by the "event changed after the query" test in Task 3 (it calls the Action with a
   stale ID list through the model check).
3. **The 24-hour edge.** An event exactly 24 hours ahead is due; one second later is not.
   Pinned by tests in Task 1.
4. **Cancelled bookings, drafts, cancelled and started events, the organizer.** No
   email. Pinned by tests in Task 3.
5. **An error on one event stops the others.** Each event has its own transaction and
   `rescue()`. Pinned by the "an error on one event does not stop the others" test in
   Task 3.

---

### Task 1: Migration and reminder rules on the model

**Files:**

- Create: `database/migrations/<timestamp>_add_reminder_sent_at_to_events_table.php`
  (with `php artisan make:migration add_reminder_sent_at_to_events_table --no-interaction`)
- Modify: `app/Models/Event.php`
- Modify: `database/factories/EventFactory.php`
- Test: `tests/Feature/EventModelTest.php`

**Interfaces:**

- Produces: the scope `Event::query()->dueForReminder()`, `Event::needsReminder(): bool`,
  `Event::markReminderSent(): void` (sets `reminder_sent_at` to now, saves nothing),
  the factory state `EventFactory::reminderSent()`, the property
  `CarbonImmutable|null $reminder_sent_at`.

- [ ] **Step 1: Write the failing tests**

Add to `tests/Feature/EventModelTest.php` (read the top of the file first and follow its
`use` lines and style):

```php
test('BR-N4: a published event that starts in the next 24 hours is due for a reminder', function () {
    $this->freezeSecond();

    $inOneHour = Event::factory()->published()->create(['starts_at' => now()->addHour()]);
    $inExactly24Hours = Event::factory()->published()->create(['starts_at' => now()->addDay()]);
    $inMoreThan24Hours = Event::factory()->published()->create(['starts_at' => now()->addDay()->addSecond()]);
    $started = Event::factory()->published()->create(['starts_at' => now()->subMinute()]);
    $draft = Event::factory()->create(['starts_at' => now()->addHour()]);
    $cancelled = Event::factory()->cancelled()->create(['starts_at' => now()->addHour()]);
    $alreadySent = Event::factory()->published()->reminderSent()->create(['starts_at' => now()->addHour()]);

    expect(Event::query()->dueForReminder()->pluck('id')->sort()->values()->all())
        ->toBe(collect([$inOneHour->id, $inExactly24Hours->id])->sort()->values()->all())
        ->and($inOneHour->needsReminder())->toBeTrue()
        ->and($inExactly24Hours->needsReminder())->toBeTrue()
        ->and($inMoreThan24Hours->needsReminder())->toBeFalse()
        ->and($started->needsReminder())->toBeFalse()
        ->and($draft->needsReminder())->toBeFalse()
        ->and($cancelled->needsReminder())->toBeFalse()
        ->and($alreadySent->needsReminder())->toBeFalse();
});

test('BR-N5: marking the reminder as sent stores the time and ends the need', function () {
    $this->freezeSecond();
    $event = Event::factory()->published()->create(['starts_at' => now()->addHour()]);

    $event->markReminderSent();

    expect($event->reminder_sent_at->equalTo(now()))->toBeTrue()
        ->and($event->needsReminder())->toBeFalse()
        ->and($event->isDirty('reminder_sent_at'))->toBeTrue();
});

test('BR-N5: the reminder_sent_at column stores the time', function () {
    $event = Event::factory()->reminderSent()->create();

    expect($event->fresh()->reminder_sent_at)->not->toBeNull()
        ->and(Event::factory()->create()->fresh()->reminder_sent_at)->toBeNull();
});
```

The `cancelled()` factory state may set a `starts_at`; the `create([...])` value wins.

- [ ] **Step 2: Run the tests to see them fail**

Run: `docker compose exec app php artisan test --compact tests/Feature/EventModelTest.php --filter=BR-N`
Expected: FAIL (`reminderSent` state or `dueForReminder` scope not found).

- [ ] **Step 3: Write the migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->timestampTz('reminder_sent_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dropColumn('reminder_sent_at');
        });
    }
};
```

Run: `docker compose exec app php artisan migrate --no-interaction`
Expected: the migration runs on the development database.

- [ ] **Step 4: Change the model and the factory**

In `app/Models/Event.php`:

1. Add `@property CarbonImmutable|null $reminder_sent_at` after `$cancelled_at` in the
   class docblock.
2. Add `'reminder_sent_at' => 'datetime',` to `casts()`.
3. Add the scope after `upcoming()`:

```php
    /**
     * Published events that start in the next 24 hours and have no reminder yet
     * (BR-N4, BR-N5).
     *
     * @param  Builder<Event>  $query
     */
    #[Scope]
    protected function dueForReminder(Builder $query): void
    {
        $query->published()
            ->upcoming()
            ->where('starts_at', '<=', now()->addDay())
            ->whereNull('reminder_sent_at');
    }
```

4. Add the methods after `cancel()`:

```php
    /**
     * Whether the event needs its reminder now: published, not started, starts in the
     * next 24 hours, and no reminder was sent (BR-N4, BR-N5).
     */
    public function needsReminder(): bool
    {
        return $this->isPublished()
            && ! $this->hasStarted()
            && $this->starts_at->lessThanOrEqualTo(now()->addDay())
            && $this->reminder_sent_at === null;
    }

    /**
     * Store the send time of the reminder (BR-N5).
     */
    public function markReminderSent(): void
    {
        $this->reminder_sent_at = now();
    }
```

`hasStarted()` is `! starts_at->isFuture()`, the same edge as the scope `upcoming()`
(`starts_at > now()`).

In `database/factories/EventFactory.php`, add after `cancelled()` (follow its style):

```php
    /**
     * An event whose reminder was sent.
     */
    public function reminderSent(): static
    {
        return $this->state(fn (array $attributes) => [
            'reminder_sent_at' => now(),
        ]);
    }
```

- [ ] **Step 5: Run the tests to see them pass**

Run: `docker compose exec app php artisan test --compact tests/Feature/EventModelTest.php`
Expected: PASS (all tests of the file).

- [ ] **Step 6: Commit**

```bash
docker compose exec app vendor/bin/pint --format agent app/Models/Event.php database/factories/EventFactory.php tests/Feature/EventModelTest.php database/migrations/*_add_reminder_sent_at_to_events_table.php
git add app/Models/Event.php database/factories/EventFactory.php tests/Feature/EventModelTest.php database/migrations/*_add_reminder_sent_at_to_events_table.php
git commit -m "feat: add reminder_sent_at and the reminder rules to events"
```

---

### Task 2: The `EventReminder` notification

**Files:**

- Create: `app/Notifications/EventReminder.php` (with
  `php artisan make:notification EventReminder --no-interaction`)
- Test: `tests/Feature/EventReminderNotificationTest.php` (with
  `php artisan make:test --pest EventReminderNotificationTest --no-interaction`)

**Interfaces:**

- Produces: `new EventReminder(Booking $booking)`, public readonly `Booking $booking`,
  `via(object): array`, `toMail(object): MailMessage`.

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Models\Booking;
use App\Models\Event;
use App\Models\User;
use App\Notifications\EventReminder;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Facades\DB;

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

test('BR-N4: the reminder email has the event and booking details', function () {
    $mail = (new EventReminder($this->booking))->toMail($this->attendee);

    expect($mail->subject)->toBe('Reminder: Laravel Meetup')
        ->and($mail->greeting)->toBe('Hello Ada Lovelace,')
        ->and($mail->introLines)->toBe([
            'Laravel Meetup starts soon.',
            'Starts: Mon 12 Oct 2026, 18:00 UTC',
            'Venue: Main Hall',
            "Reference: {$this->booking->reference}",
            'Seats: 2',
        ])
        ->and($mail->actionText)->toBe('View event')
        ->and($mail->actionUrl)->toBe(route('events.show', $this->booking->event));
});

test('BR-N6: the notification is queued after the commit and goes by mail', function () {
    $notification = new EventReminder($this->booking);

    expect($notification)->toBeInstanceOf(ShouldQueue::class)
        ->and($notification->afterCommit)->toBeTrue()
        ->and($notification->via($this->attendee))->toBe(['mail']);
});

test('the queued email is tried 3 times with a backoff', function () {
    $job = new SendQueuedNotifications($this->attendee, new EventReminder($this->booking), ['mail']);

    expect($job->tries)->toBe(3)
        ->and($job->backoff())->toBe([10, 60]);
});

test('BR-N4: the start time is in UTC when the database session uses another time zone', function () {
    DB::statement("SET LOCAL TIME ZONE 'America/New_York'");

    $mail = (new EventReminder($this->booking->fresh()))->toMail($this->attendee);

    expect($mail->introLines)->toContain('Starts: Mon 12 Oct 2026, 18:00 UTC');
});
```

- [ ] **Step 2: Run the test to see it fail**

Run: `docker compose exec app php artisan test --compact tests/Feature/EventReminderNotificationTest.php`
Expected: FAIL, class `App\Notifications\EventReminder` not found.

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
class EventReminder extends Notification implements ShouldQueue
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
            ->subject("Reminder: {$event->title}")
            ->greeting("Hello {$notifiable->name},")
            ->line("{$event->title} starts soon.")
            ->line('Starts: '.$event->starts_at->utc()->format('D j M Y, H:i').' UTC')
            ->line("Venue: {$event->venue}")
            ->line("Reference: {$this->booking->reference}")
            ->line("Seats: {$this->booking->quantity}")
            ->action('View event', route('events.show', $event));
    }
}
```

- [ ] **Step 4: Run the test to see it pass**

Run: `docker compose exec app php artisan test --compact tests/Feature/EventReminderNotificationTest.php`
Expected: PASS (4 tests).

- [ ] **Step 5: Commit**

```bash
docker compose exec app vendor/bin/pint --format agent app/Notifications/EventReminder.php tests/Feature/EventReminderNotificationTest.php
git add app/Notifications/EventReminder.php tests/Feature/EventReminderNotificationTest.php
git commit -m "feat: add the EventReminder notification"
```

---

### Task 3: The `SendEventReminders` Action

**Files:**

- Create: `app/Actions/SendEventReminders/SendEventReminders.php` (with
  `php artisan make:class Actions/SendEventReminders/SendEventReminders --no-interaction`;
  follow the shape of `app/Actions/CancelEvent/CancelEvent.php`)
- Test: `tests/Feature/SendEventRemindersTest.php` (with
  `php artisan make:test --pest SendEventRemindersTest --no-interaction`)

**Interfaces:**

- Consumes: `Event::query()->dueForReminder()`, `Event::needsReminder()`,
  `Event::markReminderSent()`, `EventFactory::reminderSent()` (Task 1);
  `new EventReminder(Booking $booking)` (Task 2).
- Produces: `SendEventReminders::handle(): int` (the number of events whose reminder
  was handled, also events with no bookings);
  `SendEventReminders::remindEvent(int $eventId): bool` (public, one event in one
  transaction; true when it marked the event).

- [ ] **Step 1: Write the failing tests**

```php
<?php

use App\Actions\SendEventReminders\SendEventReminders;
use App\Models\Booking;
use App\Models\Event;
use App\Models\User;
use App\Notifications\EventReminder;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event as EventFacade;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    $this->freezeSecond();

    $this->organizer = User::factory()->create();
    $this->event = Event::factory()->published()->for($this->organizer, 'organizer')
        ->create(['starts_at' => now()->addHours(5), 'capacity' => 10, 'seats_available' => 5]);

    $this->first = Booking::factory()->for($this->event)->create(['quantity' => 2]);
    $this->second = Booking::factory()->for($this->event)->create(['quantity' => 3]);
    $this->cancelledBooking = Booking::factory()->cancelled()->for($this->event)->create();
});

test('BR-N4: attendees with a confirmed booking get a reminder', function () {
    Notification::fake();

    $count = app(SendEventReminders::class)->handle();

    expect($count)->toBe(1);
    Notification::assertSentTo(
        $this->first->attendee,
        EventReminder::class,
        fn (EventReminder $notification) => $notification->booking->is($this->first),
    );
    Notification::assertSentTo(
        $this->second->attendee,
        EventReminder::class,
        fn (EventReminder $notification) => $notification->booking->is($this->second),
    );
    Notification::assertNotSentTo($this->cancelledBooking->attendee, EventReminder::class);
    Notification::assertNotSentTo($this->organizer, EventReminder::class);
    Notification::assertCount(2);
});

test('BR-N5: the event stores the send time of the reminder', function () {
    Notification::fake();

    app(SendEventReminders::class)->handle();

    expect($this->event->fresh()->reminder_sent_at->equalTo(now()))->toBeTrue();
});

test('BR-N5: a second run sends no second reminder', function () {
    Notification::fake();

    app(SendEventReminders::class)->handle();
    $count = app(SendEventReminders::class)->handle();

    expect($count)->toBe(0);
    Notification::assertSentTimes(EventReminder::class, 2);
});

test('BR-N4: events that are not due get no reminder', function () {
    Notification::fake();
    $this->event->forceFill(['starts_at' => now()->addDay()->addSecond()])->save();

    foreach ([
        Event::factory()->create(['starts_at' => now()->addHour()]),
        Event::factory()->cancelled()->create(['starts_at' => now()->addHour()]),
        Event::factory()->published()->create(['starts_at' => now()->subMinute()]),
        Event::factory()->published()->reminderSent()->create(['starts_at' => now()->addHour()]),
    ] as $notDue) {
        Booking::factory()->for($notDue)->create();
    }

    expect(app(SendEventReminders::class)->handle())->toBe(0);
    Notification::assertNothingSent();
    expect($this->event->fresh()->reminder_sent_at)->toBeNull();
});

test('BR-N5: a due event with no confirmed bookings is marked as sent', function () {
    Notification::fake();
    $empty = Event::factory()->published()->create(['starts_at' => now()->addHour()]);

    app(SendEventReminders::class)->handle();

    expect($empty->fresh()->reminder_sent_at)->not->toBeNull();
    Notification::assertNotSentTo($empty->organizer, EventReminder::class);
});

test('an event that changed after the query gets no reminder', function () {
    Notification::fake();
    $this->event->forceFill(['status' => 'cancelled', 'cancelled_at' => now()])->save();

    expect(app(SendEventReminders::class)->remindEvent($this->event->id))->toBeFalse();
    Notification::assertNothingSent();
    expect($this->event->fresh()->reminder_sent_at)->toBeNull();
});

test('an error on one event does not stop the others', function () {
    Notification::fake();
    Exceptions::fake();
    $failing = Event::factory()->published()->create(['starts_at' => now()->addHour()]);

    $action = Mockery::mock(SendEventReminders::class)->makePartial();
    $action->shouldReceive('remindEvent')->with($failing->id)->andThrow(new LogicException('The event fails.'));
    $action->shouldReceive('remindEvent')->with($this->event->id)->passthru();

    expect($action->handle())->toBe(1)
        ->and($this->event->fresh()->reminder_sent_at)->not->toBeNull()
        ->and($failing->fresh()->reminder_sent_at)->toBeNull();
    Notification::assertCount(2);
    Exceptions::assertReported(LogicException::class);
});

test('BR-N6: the reminders are sent only after the transaction commits', function () {
    EventFacade::fake([NotificationSent::class]);

    DB::transaction(function () {
        app(SendEventReminders::class)->remindEvent($this->event->id);

        EventFacade::assertNotDispatched(NotificationSent::class);
    });

    EventFacade::assertDispatchedTimes(NotificationSent::class, 2);
});

test('BR-N6: no reminder and no mark when the transaction rolls back', function () {
    EventFacade::fake([NotificationSent::class]);

    try {
        DB::transaction(function () {
            app(SendEventReminders::class)->remindEvent($this->event->id);

            throw new LogicException('A later step fails.');
        });
    } catch (LogicException $exception) {
        expect($exception->getMessage())->toBe('A later step fails.');
    }

    EventFacade::assertNotDispatched(NotificationSent::class);
    expect($this->event->fresh()->reminder_sent_at)->toBeNull();
});
```

If `status` is cast to the `EventStatus` enum, `forceFill(['status' => 'cancelled'])`
still works; use `EventStatus::Cancelled` if PHPStan or the cast asks for it.

- [ ] **Step 2: Run the tests to see them fail**

Run: `docker compose exec app php artisan test --compact tests/Feature/SendEventRemindersTest.php`
Expected: FAIL, class `App\Actions\SendEventReminders\SendEventReminders` not found.

- [ ] **Step 3: Write the Action**

```php
<?php

namespace App\Actions\SendEventReminders;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Event;
use App\Notifications\EventReminder;
use Illuminate\Support\Facades\DB;

class SendEventReminders
{
    /**
     * Send the reminders for all published events that start in the next 24 hours and
     * have no reminder yet (BR-N4, BR-N5). Each event has its own transaction. An error
     * on one event is reported, and the run goes on with the next event. It returns the
     * number of handled events.
     */
    public function handle(): int
    {
        return Event::query()
            ->dueForReminder()
            ->orderBy('starts_at')
            ->pluck('id')
            ->filter(fn (int $eventId): bool => rescue(fn (): bool => $this->remindEvent($eventId), false))
            ->count();
    }

    /**
     * Lock the event row, check again that it needs the reminder, mark the reminder as
     * sent, and send `EventReminder` to the attendee of each confirmed booking after the
     * commit (BR-N5, BR-N6). The other write Actions lock the event row first, so the
     * confirmed bookings cannot change while this lock is held. It returns false when
     * the event changed after the query, for example when another run or a cancel came
     * first.
     */
    public function remindEvent(int $eventId): bool
    {
        return DB::transaction(function () use ($eventId): bool {
            $event = Event::query()->lockForUpdate()->find($eventId);

            if ($event === null || ! $event->needsReminder()) {
                return false;
            }

            $event->markReminderSent();
            $event->save();

            $event->bookings()
                ->where('status', BookingStatus::Confirmed)
                ->with('attendee')
                ->get()
                ->each(function (Booking $booking) use ($event): void {
                    $booking->setRelation('event', $event);
                    $booking->attendee->notify(new EventReminder($booking));
                });

            return true;
        });
    }
}
```

The failing event starts first (`orderBy('starts_at')`), so the test shows that the run
goes on after the error. The partial mock only replaces `remindEvent()` for the failing
ID; this is the only way to make one event fail on purpose.

The `if` here is not a business rule: the rule is in `needsReminder()`. The `null` case
covers an event that was deleted after the query (a draft can be deleted, but a draft is
never due; keep the check for safety).

- [ ] **Step 4: Run the tests to see them pass**

Run: `docker compose exec app php artisan test --compact tests/Feature/SendEventRemindersTest.php`
Expected: PASS (9 tests).

- [ ] **Step 5: Commit**

```bash
docker compose exec app vendor/bin/pint --format agent app/Actions/SendEventReminders/SendEventReminders.php tests/Feature/SendEventRemindersTest.php
git add app/Actions/SendEventReminders/SendEventReminders.php tests/Feature/SendEventRemindersTest.php
git commit -m "feat: add the SendEventReminders Action"
```

---

### Task 4: The command, the schedule and the BR-N7 test

**Files:**

- Create: `app/Console/Commands/SendEventReminders.php` (with
  `php artisan make:command SendEventReminders --no-interaction`)
- Modify: `routes/console.php`
- Test: `tests/Feature/SendEventRemindersCommandTest.php` (with
  `php artisan make:test --pest SendEventRemindersCommandTest --no-interaction`)
- Test: `tests/Feature/UpdateEventTest.php`

**Interfaces:**

- Consumes: `App\Actions\SendEventReminders\SendEventReminders::handle(): int` (Task 3).

- [ ] **Step 1: Write the failing tests**

`tests/Feature/SendEventRemindersCommandTest.php`:

```php
<?php

use App\Console\Commands\SendEventReminders;
use App\Models\Booking;
use App\Models\Event;
use App\Notifications\EventReminder;
use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Notification;

test('BR-N4: the command sends the reminders and prints the number of events', function () {
    Notification::fake();
    $event = Event::factory()->published()->create(['starts_at' => now()->addHours(3)]);
    $booking = Booking::factory()->for($event)->create();

    $this->artisan('events:send-reminders')
        ->expectsOutput('Reminders sent for 1 event.')
        ->assertSuccessful();

    Notification::assertSentTo($booking->attendee, EventReminder::class);
});

test('the command prints "events" for more or less than one event', function () {
    $this->artisan('events:send-reminders')
        ->expectsOutput('Reminders sent for 0 events.')
        ->assertSuccessful();
});

test('BR-N4: the command runs daily at 08:00 UTC without overlapping', function () {
    $scheduled = collect(app(Schedule::class)->events())
        ->first(fn (ScheduledEvent $event) => str_contains((string) $event->command, 'events:send-reminders'));

    expect($scheduled)->not->toBeNull()
        ->and($scheduled->expression)->toBe('0 8 * * *')
        ->and($scheduled->timezone)->toBe('UTC')
        ->and($scheduled->withoutOverlapping)->toBeTrue();
});
```

If the import of `App\Console\Commands\SendEventReminders` is unused, remove it.

Add to `tests/Feature/UpdateEventTest.php` (add `App\Models\Booking` and
`Illuminate\Support\Facades\Notification` to the `use` lines if they are missing):

```php
test('BR-N7: a start time change sends no email to the attendees', function () {
    Notification::fake();
    Booking::factory()->for($this->event)->create();

    $this->actingAs($this->organizer)
        ->put(route('events.update', $this->event), $this->validData)
        ->assertRedirect(route('events.show', $this->event));

    expect($this->event->fresh()->starts_at->utc()->toIso8601String())->toBe('2030-05-01T18:30:00+00:00');
    Notification::assertNothingSent();
});

test('BR-N5: a start time change does not reset the reminder', function () {
    $this->event->forceFill(['reminder_sent_at' => now()])->save();

    $this->actingAs($this->organizer)
        ->put(route('events.update', $this->event), $this->validData);

    expect($this->event->fresh()->reminder_sent_at)->not->toBeNull();
});
```

If the booking makes `seats_available` and `capacity` invalid for the update (60 seats,
1 booked), check the existing capacity rules in `Event::changeCapacity()` and keep the
`validData` capacity of 60: it is above the booked seats.

- [ ] **Step 2: Run the tests to see them fail**

Run: `docker compose exec app php artisan test --compact tests/Feature/SendEventRemindersCommandTest.php`
Expected: FAIL (the command `events:send-reminders` does not exist).

Run: `docker compose exec app php artisan test --compact tests/Feature/UpdateEventTest.php --filter="BR-N"`
Expected: PASS already. These two tests guard the owner decisions (BR-N5, BR-N7); no code
changes for them.

- [ ] **Step 3: Write the command and the schedule**

`app/Console/Commands/SendEventReminders.php`:

```php
<?php

namespace App\Console\Commands;

use App\Actions\SendEventReminders\SendEventReminders as SendEventRemindersAction;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

#[Signature('events:send-reminders')]
#[Description('Send the reminder emails for the events that start in the next 24 hours')]
class SendEventReminders extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(SendEventRemindersAction $sendEventReminders): int
    {
        $count = $sendEventReminders->handle();

        $this->info("Reminders sent for {$count} ".Str::plural('event', $count).'.');

        return self::SUCCESS;
    }
}
```

If `make:command` writes `$signature` and `$description` properties instead of the
attributes, use the attributes as above (Laravel 13 docs).

`routes/console.php`, add:

```php
use App\Console\Commands\SendEventReminders;
use Illuminate\Support\Facades\Schedule;

Schedule::command(SendEventReminders::class)
    ->dailyAt('08:00')
    ->timezone('UTC')
    ->withoutOverlapping();
```

- [ ] **Step 4: Run the tests to see them pass**

Run: `docker compose exec app php artisan test --compact tests/Feature/SendEventRemindersCommandTest.php tests/Feature/UpdateEventTest.php`
Expected: PASS.

Run: `docker compose exec app php artisan schedule:list`
Expected: one line for `events:send-reminders` with `0 8 * * *`.

- [ ] **Step 5: Commit**

```bash
docker compose exec app vendor/bin/pint --format agent app/Console/Commands/SendEventReminders.php routes/console.php tests/Feature/SendEventRemindersCommandTest.php tests/Feature/UpdateEventTest.php
git add app/Console/Commands/SendEventReminders.php routes/console.php tests/Feature/SendEventRemindersCommandTest.php tests/Feature/UpdateEventTest.php
git commit -m "feat: schedule the daily event reminders"
```

---

### Task 5: README, M5 rule check, handoff and plan

**Files:**

- Create: `app/Actions/SendEventReminders/README.md`
- Modify: `app/Actions/UpdateEvent/README.md` (only if it says something about
  notifications or reminders)
- Modify: `HANDOFF.md`
- Add: `docs/superpowers/plans/2026-10-08-m5-event-reminders.md`

- [ ] **Step 1: Write the `SendEventReminders` README**

Follow `app/Actions/CancelEvent/README.md`. Write in Simple English, for readers outside
the project. Explain: the `scheduler` service runs `schedule:work`; the schedule starts
`events:send-reminders` each day at 08:00 UTC, never two at the same time
(`withoutOverlapping()`). The command calls the Action and prints the number of events.
Explain which events are due (BR-N4), the one transaction for each event, the lock of the
event row, the check again under the lock, `reminder_sent_at` (BR-N5), the confirmed
bookings and their attendees, the queued emails after the commit (BR-N6), the
`rescue()` around each event (an error is logged and the run goes on), events with no
bookings, and that a start time change after the reminder sends no second reminder
(BR-N5, BR-N7). Add the Redis note (if Redis fails right after the commit, the event is
marked, but some or all emails do not go out). Explain that the reminder arrives between
0 and 24 hours before the start, because the command runs once a day.

One sequence diagram for each outcome, no `alt` blocks:

1. The reminders are sent (scheduler, command, Action, read the due event IDs; for one
   event: begin, lock, `needsReminder()`, `markReminderSent()`, update, read the
   confirmed bookings with attendees, queue `EventReminder` for each, commit, release).
2. The event changed after the query (lock, `needsReminder()` is false, commit with no
   change, no email).
3. No event is due (read the IDs, none, print "Reminders sent for 0 events.").

Render the diagrams locally:
`npx -y @mermaid-js/mermaid-cli -i app/Actions/SendEventReminders/README.md -o <scratch-dir>/send-event-reminders.md`
Expected: three SVG files and no errors.

- [ ] **Step 2: Check that every M5 rule has a test**

Run: `grep -rhoE "BR-N[0-9]+\b" tests | sort -u`
Expected: BR-N1, BR-N2, BR-N3, BR-N4, BR-N5, BR-N6 and BR-N7.

- [ ] **Step 3: Check the reminder in Mailpit (owner)**

Log in as `attendee@example.com` (password `password`) and book a seat on a published
event that starts in the next 24 hours (as `organizer@example.com`, create and publish
one if there is none). Then run:
`docker compose exec app php artisan events:send-reminders`
Expected: `Reminders sent for N events.` with N of 1 or more. In Mailpit
(`http://localhost:8025`), one email "Reminder: {title}" to `attendee@example.com` with
the start time in UTC, the venue, the reference, the seats and a "View event" button. Run
the command again: `Reminders sent for 0 events.` and no new email. The owner does this
check; the implementer asks for it and waits.

- [ ] **Step 4: Format and run all checks**

Run: `npm run check:fix`
Run: `docker compose exec app composer ci:check`
Expected: Pint, PHPStan, `vp check`, `tsc` and all tests pass.

- [ ] **Step 5: Update `HANDOFF.md`**

"Where we are": PRs 1 to 3 are merged (#37, #38, #39). The current PR is M5 PR 4
(`feat/event-reminders`), with the path of this plan. Write that it is open as a PR and
waits for the merge (no PR number). With this PR, M5 is complete: every rule BR-N1 to
BR-N7 has a test (from Step 2). Keep the notification pattern. Add the reminder notes:
the due rule, the transaction for each event, `reminder_sent_at`, the schedule, events
with no bookings, no second reminder after a start time change, and the migration
`add_reminder_sent_at_to_events_table`. "Next steps": the owner reviews this PR. Then
M5 is complete; split M6 (user accounts) into PRs with the owner.

- [ ] **Step 6: Commit**

```bash
git add app/Actions/SendEventReminders/README.md HANDOFF.md \
  docs/superpowers/plans/2026-10-08-m5-event-reminders.md
git commit -m "docs: document the event reminders and update handoff"
```

Add `app/Actions/UpdateEvent/README.md` to `git add` if Step 1 changed it.
