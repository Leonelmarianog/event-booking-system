# M7 — Demo Seed Data

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** `make fresh` fills the local database with demo data that looks real: named
accounts to log in with, generated organizers and attendees, and events in every state
with bookings.

**Architecture:** `DatabaseSeeder` creates the users with `UserFactory` and the events
with `EventFactory`, with hand-written titles, venues and descriptions. It makes and
cancels the bookings with the model methods (`Event::reserve()`, `Booking::cancel()`,
`Event::cancel()`), not with the Actions. So the available seats always match the
bookings, and no email is sent. All times are relative to now.

**Tech Stack:** Laravel 13, Pest.

**Spec:** `docs/superpowers/specs/2026-09-28-event-booking-design.md` (section 10, "Seed
data"), `docs/superpowers/plans/milestones.md` (M7).

## Global Constraints

- M7 is split into three PRs: 1 the CSRF test (merged), 2 the demo seed data (this PR),
  3 the `README.md` and a run-through by a new person.
- Owner decisions for this PR:
    - 3 named accounts (one organizer only), 3 generated organizers and 180 generated
      attendees (186 users). 17 events: 13 upcoming published (so `/events` has a second
      page at 12 for each page), 2 drafts, 1 past, 1 cancelled. One upcoming event is
      sold out.
    - Both paginated pages have more than one page: `/events` (12 for each page, two
      pages) and the attendee list of "Jazz Night at the Harbour" (175 attendees, 50 for
      each page, four pages: 50, 50, 50 and 25).
    - Start times vary (morning, afternoon, evening), not all at the same hour.
    - Hand-written event titles, venues and descriptions (no Faker text for events).
    - No production guard. The production `migrate` role runs `migrate --force` only, and
      only `make fresh` runs the seeder. Faker is a dev dependency, so the seeder cannot
      run in the production image anyway. This PR removes the guard from the spec and
      from `milestones.md`.
- Named accounts, all with password `password` and a verified email:
  `organizer@example.com` (Olivia Bennett), `attendee@example.com` (Alex Carter),
  `admin@example.com` (Sam Rivera, admin). The events of the generated organizers show
  the "not your event" case when `organizer@` opens them.
- The first upcoming event starts in 3 days, so no reminder is due right after
  `make fresh` (the dev stack runs the scheduler each hour).
- Do not run `make fresh` or `migrate:fresh` on the dev database. It deletes the
  owner's local data. The owner runs it for the manual check.
- Run commands inside the `app` container: `docker compose exec app <command>`. Pint
  `--dirty` does not work in the container (no git); pass the file paths.

## Review Focus

1. **Seats match bookings.** For each event, `seats_available` equals the capacity
   minus the seats of its confirmed bookings. Pinned in Task 1.
2. **No email at seeding.** The seeder sends no notification. Pinned in Task 1.
3. **No reminder due after seeding.** No event is in `dueForReminder()`. Pinned in
   Task 1.
4. **Every page has something to show** for the named accounts: `/events` and the
   attendee list of "Jazz Night at the Harbour" have more than one page, "My events" of `organizer@` has upcoming, past and draft events, and "My bookings" of
   `attendee@` has confirmed, cancelled and "Event cancelled" bookings. Pinned in Task 1.
5. **The seeder is the same on every run** except the generated names and emails, so
   the tests are stable. The structure (events, bookings, quantities) has no
   randomness.

---

### Task 1: The demo seeder

**Files:**

- Test: `tests/Feature/DemoSeederTest.php` (with
  `php artisan make:test --pest DemoSeederTest --no-interaction`)
- Modify: `database/seeders/DatabaseSeeder.php`

- [ ] **Step 1: Write the tests**

```php
<?php

use App\Enums\BookingStatus;
use App\Enums\EventStatus;
use App\Models\Booking;
use App\Models\Event;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    Notification::fake();

    $this->seed(DatabaseSeeder::class);
});

test('the named accounts can log in with the password "password"', function () {
    $emails = ['organizer@example.com', 'attendee@example.com', 'admin@example.com'];

    foreach ($emails as $email) {
        $user = User::where('email', $email)->firstOrFail();

        expect(Hash::check('password', $user->password))->toBeTrue()
            ->and($user->email_verified_at)->not->toBeNull();
    }

    expect(User::where('email', 'admin@example.com')->first()->is_admin)->toBeTrue()
        ->and(User::where('is_admin', true)->count())->toBe(1);
});

test('the seeder makes 186 users', function () {
    expect(User::count())->toBe(186);
});

test('the events are in every state', function () {
    expect(Event::query()->published()->upcoming()->count())->toBe(13)
        ->and(Event::where('status', EventStatus::Draft)->count())->toBe(2)
        ->and(Event::query()->published()->where('starts_at', '<', now())->count())->toBe(1)
        ->and(Event::where('status', EventStatus::Cancelled)->count())->toBe(1)
        ->and(Event::query()->published()->upcoming()->where('seats_available', 0)->count())->toBe(1);
});

test('the available seats of each event match its confirmed bookings', function () {
    foreach (Event::with('bookings')->get() as $event) {
        $seatsBooked = $event->bookings
            ->where('status', BookingStatus::Confirmed)
            ->sum('quantity');

        expect($event->seats_available)->toBe($event->capacity - $seatsBooked, $event->title);
    }
});

test('the bookings of a cancelled event are cancelled with it', function () {
    $event = Event::where('status', EventStatus::Cancelled)->firstOrFail();

    expect($event->bookings)->not->toBeEmpty()
        ->and($event->bookings->every(fn (Booking $booking) => $booking->wasCancelledWithEvent()))->toBeTrue();
});

test('the attendee account has bookings in every state', function () {
    $attendee = User::where('email', 'attendee@example.com')->firstOrFail();
    $bookings = Booking::where('user_id', $attendee->id)->with('event')->get();

    $upcomingConfirmed = $bookings->filter(fn (Booking $booking) => $booking->isConfirmed() && ! $booking->event->hasStarted());
    $past = $bookings->filter(fn (Booking $booking) => $booking->event->hasStarted());
    $cancelledByAttendee = $bookings->filter(fn (Booking $booking) => $booking->isCancelled() && ! $booking->wasCancelledWithEvent());
    $cancelledWithEvent = $bookings->filter(fn (Booking $booking) => $booking->wasCancelledWithEvent());

    expect($upcomingConfirmed)->toHaveCount(3)
        ->and($past)->toHaveCount(1)
        ->and($cancelledByAttendee)->toHaveCount(1)
        ->and($cancelledWithEvent)->toHaveCount(1);
});

test('the organizer account has upcoming, past and draft events with attendees', function () {
    $organizer = User::where('email', 'organizer@example.com')->firstOrFail();
    $events = Event::where('organizer_id', $organizer->id)->get();

    expect($events->filter(fn (Event $event) => $event->isPublished() && ! $event->hasStarted()))->toHaveCount(5)
        ->and($events->filter(fn (Event $event) => $event->hasStarted()))->toHaveCount(1)
        ->and($events->filter(fn (Event $event) => $event->isDraft()))->toHaveCount(2)
        ->and($events->filter(fn (Event $event) => $event->isCancelled()))->toHaveCount(1)
        ->and(Event::where('title', 'Laravel Meetup Lisbon')->firstOrFail()->hasBookingBy(User::where('email', 'attendee@example.com')->firstOrFail()))->toBeTrue();
});

test('the generated organizers organize events too', function () {
    $organizer = User::where('email', 'organizer@example.com')->firstOrFail();

    expect(Event::where('organizer_id', '!=', $organizer->id)->distinct()->count('organizer_id'))->toBe(3);
});

test('the events page has a second page', function () {
    $this->get(route('events.index', ['page' => 2]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('events.data', 1));
});

test('the attendee list of the biggest event has four pages', function () {
    $admin = User::where('email', 'admin@example.com')->firstOrFail();
    $event = Event::where('title', 'Jazz Night at the Harbour')->firstOrFail();

    $this->actingAs($admin)
        ->get(route('events.attendees.index', ['event' => $event, 'page' => 3]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('attendees.data', 50));

    $this->actingAs($admin)
        ->get(route('events.attendees.index', ['event' => $event, 'page' => 4]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('attendees.data', 25));
});

test('the events do not all start at the same hour', function () {
    $hours = Event::all()->map(fn (Event $event) => $event->starts_at->format('H:i'))->unique();

    expect($hours->count())->toBeGreaterThan(5);
});

test('the seeder sends no email and leaves no reminder due', function () {
    Notification::assertNothingSent();

    expect(Event::query()->dueForReminder()->count())->toBe(0);
});
```

- [ ] **Step 2: Run the tests to see them fail**

Run: `docker compose exec app php artisan test --compact tests/Feature/DemoSeederTest.php`
Expected: FAIL. The starter-kit seeder makes one "Test User" and no events (for
example "the seeder makes 186 users" fails with 1).

- [ ] **Step 3: Write the seeder**

Replace `database/seeders/DatabaseSeeder.php`:

```php
<?php

namespace Database\Seeders;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Event;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Seeder;

/**
 * The demo data of `make fresh`: named accounts, generated organizers and attendees,
 * and events in every state with bookings. The bookings go through the model methods,
 * so the available seats match the bookings and no email is sent. All times are
 * relative to now. The first upcoming event starts in 3 days, so no reminder is due.
 */
class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * The seats of each generated booking, in turn.
     */
    private const QUANTITIES = [1, 2, 1, 1, 2, 1, 3, 1];

    private User $organizer;

    private User $attendee;

    /** @var Collection<int, User> */
    private Collection $guestOrganizers;

    /** @var Collection<int, User> */
    private Collection $crowd;

    /**
     * The first generated attendee of the next generated bookings.
     */
    private int $crowdOffset = 0;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->organizer = User::factory()->create(['name' => 'Olivia Bennett', 'email' => 'organizer@example.com']);
        $this->attendee = User::factory()->create(['name' => 'Alex Carter', 'email' => 'attendee@example.com']);
        User::factory()->admin()->create(['name' => 'Sam Rivera', 'email' => 'admin@example.com']);
        $this->guestOrganizers = User::factory(3)->create();
        $this->crowd = User::factory(180)->create();

        $this->seedUpcomingEvents();
        $this->seedPastEvent();
        $this->seedCancelledEvent();
        $this->seedDrafts();
    }

    /**
     * The 13 upcoming published events. "Intro to Pottery" is sold out, and the
     * attendee account books three events and cancels one booking.
     */
    private function seedUpcomingEvents(): void
    {
        [$marta, $james, $priya] = $this->guestOrganizers->all();

        $meetup = $this->publishedEvent($this->organizer, 'Laravel Meetup Lisbon', 'Impact Hub Lisbon', 'Talks on queues, testing and deployment, then pizza and drinks.', 3, '18:30', 60);
        $this->bookCrowd($meetup, 12);
        $this->book($meetup, $this->attendee, 2);

        $pottery = $this->publishedEvent($priya, 'Intro to Pottery', 'Clay Studio, Porto', 'A hands-on evening class for beginners. All materials are included.', 5, '19:00', 8);
        foreach ($this->crowd->slice(20, 4) as $user) {
            $this->book($pottery, $user, 2);
        }

        $trailRun = $this->publishedEvent($marta, 'Sunday Trail Run', 'Monsanto Park, Lisbon', 'A relaxed 10 km trail run with a coffee stop at the end.', 6, '09:00', 40);
        $this->bookCrowd($trailRun, 9);
        $this->book($trailRun, $this->attendee, 1);

        $this->bookCrowd($this->publishedEvent($this->organizer, 'Product Design Workshop', 'LX Factory, Lisbon', 'Learn to sketch, test and improve a product idea in one afternoon.', 8, '14:00', 25), 6);
        $this->bookCrowd($this->publishedEvent($james, 'Jazz Night at the Harbour', 'Armazém 16, Lisbon', 'A local quartet plays standards and new pieces in a riverside warehouse. Doors open at 20:30.', 10, '21:00', 300), 175);

        $photoWalk = $this->publishedEvent($marta, 'Photography Walk: Old Town', 'Alfama, Lisbon', 'A guided walk for photographers of all levels. Bring any camera.', 12, '10:30', 15);
        $this->bookCrowd($photoWalk, 5);
        $this->book($photoWalk, $this->attendee, 1);

        $this->bookCrowd($this->publishedEvent($this->organizer, 'Startup Pitch Evening', 'Startup Lisboa', 'Ten early-stage teams pitch to a panel of investors and the audience.', 14, '18:00', 80), 10);

        $boardGames = $this->publishedEvent($priya, 'Board Game Social', 'Café Gato, Porto', 'Meet new people over classic and modern board games.', 16, '19:30', 30);
        $this->bookCrowd($boardGames, 7);
        $this->cancelBooking($boardGames, $this->book($boardGames, $this->attendee, 1));

        $this->bookCrowd($this->publishedEvent($marta, 'Beginner Spanish Conversation', 'City Library, Coimbra', 'Practice everyday Spanish in small groups with a native speaker.', 18, '17:00', 20), 4);
        $this->bookCrowd($this->publishedEvent($this->organizer, 'Open Source Contribution Day', 'Faculty of Sciences, University of Lisbon', 'Pick an issue, pair with a maintainer and open your first pull request.', 21, '10:00', 50), 8);
        $this->bookCrowd($this->publishedEvent($james, 'Wine Tasting: Douro Valley', 'Vinho Wine Bar, Porto', 'Taste six wines from the Douro with a local sommelier.', 25, '20:00', 24), 3);
        $this->bookCrowd($this->publishedEvent($james, 'Yoga in the Park', 'Jardim da Estrela, Lisbon', 'An outdoor class for all levels. Bring a mat and water.', 30, '08:30', 35), 2);
        $this->publishedEvent($this->organizer, 'Cloud Infrastructure Talk', 'Online', 'How a small team runs Laravel in production with Docker and CI.', 45, '16:00', 200);
    }

    /**
     * An event that took place 20 days ago. It is booked while it is upcoming, then
     * moved to the past, as time would do.
     */
    private function seedPastEvent(): void
    {
        $event = $this->publishedEvent($this->organizer, 'JavaScript Meetup Lisbon', 'Impact Hub Lisbon', 'Lightning talks on TypeScript, React and testing.', 1, '18:30', 40);
        $this->bookCrowd($event, 11);
        $this->book($event, $this->attendee, 1);

        $event->starts_at = now()->subDays(20)->setTimeFromTimeString('18:30');
        $event->published_at = now()->subDays(45);
        $event->reminder_sent_at = $event->starts_at->subDay();
        $event->save();
    }

    /**
     * A cancelled event. Its bookings are cancelled with it, as the CancelEvent Action
     * does (BR-E13), but without the emails.
     */
    private function seedCancelledEvent(): void
    {
        $event = $this->publishedEvent($this->organizer, 'Sunset Kayak Tour', 'Belém Docks, Lisbon', 'A guided kayak tour along the river at sunset. Cancelled because of the weather forecast.', 9, '19:00', 12);
        $this->bookCrowd($event, 5);
        $this->book($event, $this->attendee, 2);

        $event->cancel();
        $event->bookings()
            ->where('status', BookingStatus::Confirmed)
            ->get()
            ->each(fn (Booking $booking) => $this->cancelBooking($event, $booking));
        $event->save();
    }

    /**
     * Two drafts of the organizer account.
     */
    private function seedDrafts(): void
    {
        Event::factory()->for($this->organizer, 'organizer')->create([
            'title' => 'Data Visualisation Workshop',
            'venue' => 'LX Factory, Lisbon',
            'description' => 'Turn a spreadsheet into clear charts. Details to follow.',
            'starts_at' => now()->addDays(35)->setTimeFromTimeString('14:00'),
            'capacity' => 30,
        ]);

        Event::factory()->for($this->organizer, 'organizer')->create([
            'title' => 'Street Food Market',
            'venue' => 'Ribeira, Porto',
            'description' => 'Local cooks, live music and long tables. The program is not final yet.',
            'starts_at' => now()->addDays(40)->setTimeFromTimeString('12:00'),
            'capacity' => 300,
        ]);
    }

    /**
     * Create a published event of the organizer that starts the given number of days
     * from now, at the given time.
     */
    private function publishedEvent(User $organizer, string $title, string $venue, string $description, int $days, string $time, int $capacity): Event
    {
        return Event::factory()->published()->for($organizer, 'organizer')->create([
            'title' => $title,
            'venue' => $venue,
            'description' => $description,
            'starts_at' => now()->addDays($days)->setTimeFromTimeString($time),
            'capacity' => $capacity,
        ]);
    }

    /**
     * Book seats of the event for the given number of generated attendees. Each call
     * starts 7 attendees further on, so the events have different people.
     */
    private function bookCrowd(Event $event, int $count): void
    {
        for ($index = 0; $index < $count; $index++) {
            $user = $this->crowd[($this->crowdOffset + $index) % $this->crowd->count()];

            $this->book($event, $user, self::QUANTITIES[$index % count(self::QUANTITIES)]);
        }

        $this->crowdOffset += 7;
    }

    /**
     * Book seats of the event for the user and save both.
     */
    private function book(Event $event, User $user, int $quantity): Booking
    {
        $booking = $event->reserve($user, $quantity);
        $event->save();
        $booking->save();

        return $booking;
    }

    /**
     * Cancel the booking, give its seats back to the event and save both.
     */
    private function cancelBooking(Event $event, Booking $booking): void
    {
        $booking->setRelation('event', $event);
        $booking->cancel();
        $booking->save();
        $event->save();
    }
}
```

The seats of each event stay within its capacity: the most seats booked is 263 of 300
("Jazz Night at the Harbour", 175 attendees), and "Intro to Pottery" has exactly 8 of 8.

- [ ] **Step 4: Run the tests**

Run: `docker compose exec app php artisan test --compact tests/Feature/DemoSeederTest.php`
Expected: PASS (12 tests).

- [ ] **Step 5: Commit**

```bash
docker compose exec app vendor/bin/pint --format agent database/seeders/DatabaseSeeder.php tests/Feature/DemoSeederTest.php
git add database/seeders/DatabaseSeeder.php tests/Feature/DemoSeederTest.php
git commit -m "feat: seed demo accounts and events in every state"
```

---

### Task 2: Docs, handoff and plan

**Files:**

- Modify: `docs/superpowers/specs/2026-09-28-event-booking-design.md` (section 10, "Seed
  data")
- Modify: `docs/superpowers/plans/milestones.md` (M7 scope)
- Modify: `HANDOFF.md`
- Add: `docs/superpowers/plans/2026-10-08-m7-demo-seed-data.md`

- [ ] **Step 1: Update the spec**

Replace the "Seed data" paragraph with:

```markdown
Demo users, all with password `password`: `organizer@example.com`,
`attendee@example.com` and `admin@example.com` (admin), plus 3 generated organizers and 180 generated attendees. 17 events in mixed
states (upcoming, sold out, past, cancelled, draft) with bookings, at different times of
the day. `/events` has two pages and the attendee list of the biggest event has four. The seeder makes the
bookings with the model methods, so no email is sent. Only `make fresh` runs the
seeder; the production `migrate` role never does.
```

- [ ] **Step 2: Update `milestones.md`**

In the M7 scope, replace "The seed data: the demo users and about 10 events in
different states. The seeder stops when `APP_ENV=production`." with "The seed data: the
demo users and about 17 events in different states."

- [ ] **Step 3: Update `HANDOFF.md`**

"Where we are": M7 PR 1 is merged (#44). The current PR (`feat/demo-seed-data`) is M7
PR 2, with the path of this plan; open as a PR, waits for the merge (no PR number). Add
the owner decisions of this PR from the Global Constraints and the named accounts.
Shorten the PR 1 notes to "M7 PR 1 notes". "Next steps": the owner reviews this PR.
Then M7 PR 3 (README and run-through). Note for PR 3: `make setup` migrates but does
not seed; spec success criterion 1 says the local stack has seeded demo data, so the
README run-through must include `make fresh` (or `make setup` must seed).

- [ ] **Step 4: Format and run all checks**

Run: `npm run check:fix`
Run: `docker compose exec app composer ci:check`
Expected: all pass.

- [ ] **Step 5: Commit**

```bash
git add docs/superpowers/specs/2026-09-28-event-booking-design.md docs/superpowers/plans/milestones.md HANDOFF.md docs/superpowers/plans/2026-10-08-m7-demo-seed-data.md
git commit -m "docs: describe the demo seed data and update handoff"
```

- [ ] **Step 6: Manual check (owner)**

The owner runs `make fresh` (it deletes the local data), then logs in as each named
account and checks `/events` (two pages), "My events", "My bookings", the attendee list
of "Laravel Meetup Lisbon", the attendee list of "Jazz Night at the Harbour" as
`admin@` (four pages), and that Mailpit has no new email.
