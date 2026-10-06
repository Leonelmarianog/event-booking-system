# M3 — Events Table, Event Model and Event Policy

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** The database can keep events, and one policy tells who can see, edit, publish,
cancel and delete an event. This PR has no routes and no pages.

**Architecture:** A migration adds `users.is_admin`. A second migration creates the
`events` table with the CHECK constraints of the ERD. The `Event` model has the
`EventStatus` enum cast and small state helpers. `EventPolicy` uses these helpers. The
later M3 PRs add the Actions, the routes and the pages.

**Tech Stack:** Laravel 13, PostgreSQL 18, Pest.

**Spec:** `docs/superpowers/specs/2026-09-28-event-booking-design.md` (sections 4 and 6),
`docs/design/business-rules.md`, `docs/design/erd.md`.

## Global Constraints

- The `events` table has only the columns that M3 uses: `id`, `organizer_id`, `title`,
  `description`, `venue`, `starts_at`, `capacity`, `seats_available`, `status`,
  `published_at`, `created_at`, `updated_at`. `cancelled_at` comes in M4.
  `reminder_sent_at` comes with the reminders. The future columns are not added.
- Time columns of `events` are `timestamptz`.
- `events.organizer_id` references `users.id` with `ON DELETE RESTRICT` (ERD).
- Indexes: `(status, starts_at)` and `(organizer_id)` (ERD).
- CHECK constraints: `capacity > 0` (BR-E2),
  `seats_available >= 0 AND seats_available <= capacity` (BR-E8),
  `status IN ('draft', 'published', 'cancelled')`.
- `users.is_admin` is a boolean, default `false`, not null. It is not mass assignable.
- An admin does not pass all checks. BR-A3 says that an admin cannot edit or publish
  the events of other users. So the policy has no `before()` method and no
  `Gate::before()`.
- Each test that covers a business rule starts with the ID of the rule. Tests of the
  factory, the admin flag and the status constraint cover no business rule and have no ID.
- Model methods that change state (`publish()`, `changeCapacity()`, `cancel()`) come in
  the PR of the Action that uses them, not in this PR.
- Run commands inside the `app` container: `docker compose exec app <command>`. Start
  the stack with `make up`.

## Review Focus

1. **Visitors.** A visitor (no user) can see a published event and nothing else. The
   other abilities return `false` for a visitor and do not cause an error.
2. **The start time is now.** An event that starts at this exact second has started.
   The organizer cannot edit it (BR-E5).
3. **An admin who is the organizer.** An admin can edit and publish their own events.
   BR-A3 blocks only the events of other users.
4. **Limits of the constraints.** `seats_available = 0` and
   `seats_available = capacity` are valid. `capacity = 1` is valid.
5. **BR-E16 is not complete in this PR.** Attendees of a cancelled event can see it only
   after M4 adds bookings. This PR tests the organizer, admin, other user and visitor
   cases.

---

### Task 1: `users.is_admin`

**Files:**

- Create: `database/migrations/<timestamp>_add_is_admin_to_users_table.php`
- Modify: `app/Models/User.php`
- Modify: `database/factories/UserFactory.php`
- Test: `tests/Feature/UserAdminFlagTest.php`

**Interfaces:**

- Produces: `User::$is_admin` (`bool`), `User::factory()->admin()`.

- [ ] **Step 1: Write the failing test**

Create the file with `php artisan make:test --pest UserAdminFlagTest --no-interaction`,
then replace its content:

```php
<?php

use App\Models\User;

test('a new user is not an admin', function () {
    $user = User::factory()->create();

    expect($user->fresh()->is_admin)->toBeFalse();
});

test('the admin factory state makes an admin', function () {
    $admin = User::factory()->admin()->create();

    expect($admin->fresh()->is_admin)->toBeTrue();
});

test('is_admin is not mass assignable', function () {
    $user = User::create([
        'name' => 'Mallory',
        'email' => 'mallory@example.com',
        'password' => 'password',
        'is_admin' => true,
    ]);

    expect($user->fresh()->is_admin)->toBeFalse();
});
```

- [ ] **Step 2: Run the test to make sure it fails**

Run: `docker compose exec app php artisan test --compact tests/Feature/UserAdminFlagTest.php`
Expected: FAIL. The column `is_admin` does not exist, and the method `admin()` is not
defined.

- [ ] **Step 3: Write the migration**

Run: `docker compose exec app php artisan make:migration add_is_admin_to_users_table --table=users --no-interaction`

```php
public function up(): void
{
    Schema::table('users', function (Blueprint $table) {
        $table->boolean('is_admin')->default(false)->after('password');
    });
}

public function down(): void
{
    Schema::table('users', function (Blueprint $table) {
        $table->dropColumn('is_admin');
    });
}
```

- [ ] **Step 4: Update the model and the factory**

In `app/Models/User.php`, add `@property bool $is_admin` to the class PHPDoc, after
`$password`. Add the cast in `casts()`:

```php
'is_admin' => 'boolean',
```

Do not add `is_admin` to `#[Fillable]`.

In `database/factories/UserFactory.php`, add `'is_admin' => false,` to `definition()`
and add this state after `withTwoFactor()`:

```php
/**
 * Indicate that the user is an admin.
 */
public function admin(): static
{
    return $this->state(fn (array $attributes) => [
        'is_admin' => true,
    ]);
}
```

- [ ] **Step 5: Run the test to make sure it passes**

Run: `docker compose exec app php artisan test --compact tests/Feature/UserAdminFlagTest.php`
Expected: PASS (3 tests).

- [ ] **Step 6: Commit**

```bash
git add database/migrations/*_add_is_admin_to_users_table.php app/Models/User.php \
  database/factories/UserFactory.php tests/Feature/UserAdminFlagTest.php
git commit -m "feat: add the admin flag to users"
```

### Task 2: `events` table, `EventStatus` and `Event` model

**Files:**

- Create: `app/Enums/EventStatus.php`
- Create: `app/Models/Event.php`
- Create: `database/factories/EventFactory.php`
- Create: `database/migrations/<timestamp>_create_events_table.php`
- Test: `tests/Feature/EventsTableTest.php`
- Test: `tests/Feature/EventModelTest.php`

**Interfaces:**

- Consumes: `User::factory()` (Task 1).
- Produces:
    - `enum EventStatus: string` with `Draft = 'draft'`, `Published = 'published'`,
      `Cancelled = 'cancelled'`.
    - `Event::isOrganizedBy(User $user): bool`, `Event::hasStarted(): bool`,
      `Event::isDraft(): bool`, `Event::isPublished(): bool`, `Event::isCancelled(): bool`.
    - `Event::factory()` (a future draft) with the states `published()`, `cancelled()` and
      `started()`.

- [ ] **Step 1: Write the failing tests**

Create both files with `php artisan make:test --pest <Name> --no-interaction`, then
replace their content.

`tests/Feature/EventsTableTest.php`:

```php
<?php

use App\Models\Event;
use Illuminate\Database\QueryException;

test('BR-E2: the database rejects a capacity of 0', function () {
    Event::factory()->create(['capacity' => 0, 'seats_available' => 0]);
})->throws(QueryException::class, 'events_capacity_check');

test('BR-E2: the database accepts a capacity of 1', function () {
    $event = Event::factory()->create(['capacity' => 1, 'seats_available' => 1]);

    expect($event->fresh()->capacity)->toBe(1);
});

test('BR-E8: the database rejects negative available seats', function () {
    Event::factory()->create(['capacity' => 10, 'seats_available' => -1]);
})->throws(QueryException::class, 'events_seats_available_check');

test('BR-E8: the database rejects more available seats than the capacity', function () {
    Event::factory()->create(['capacity' => 10, 'seats_available' => 11]);
})->throws(QueryException::class, 'events_seats_available_check');

test('BR-E8: the database accepts 0 available seats and a full event', function () {
    $soldOut = Event::factory()->create(['capacity' => 10, 'seats_available' => 0]);
    $empty = Event::factory()->create(['capacity' => 10, 'seats_available' => 10]);

    expect($soldOut->fresh()->seats_available)->toBe(0)
        ->and($empty->fresh()->seats_available)->toBe(10);
});

test('the database rejects an unknown status', function () {
    $event = Event::factory()->create();

    DB::table('events')->where('id', $event->id)->update(['status' => 'archived']);
})->throws(QueryException::class, 'events_status_check');

test('BR-U4: the database does not delete a user who organizes events', function () {
    $event = Event::factory()->create();

    DB::table('users')->where('id', $event->organizer_id)->delete();
})->throws(QueryException::class, 'events_organizer_id_foreign');
```

Add `use Illuminate\Support\Facades\DB;` to the imports.

`tests/Feature/EventModelTest.php`:

```php
<?php

use App\Enums\EventStatus;
use App\Models\Event;
use App\Models\User;

test('a new event from the factory is a future draft with all seats available', function () {
    $event = Event::factory()->create(['capacity' => 25])->fresh();

    expect($event->status)->toBe(EventStatus::Draft)
        ->and($event->isDraft())->toBeTrue()
        ->and($event->hasStarted())->toBeFalse()
        ->and($event->seats_available)->toBe(25)
        ->and($event->published_at)->toBeNull();
});

test('the factory states set the status', function () {
    expect(Event::factory()->published()->create()->fresh()->isPublished())->toBeTrue()
        ->and(Event::factory()->cancelled()->create()->fresh()->isCancelled())->toBeTrue();
});

test('BR-E5: an event that starts now has started', function () {
    $this->freezeTime();

    $event = Event::factory()->create(['starts_at' => now()]);

    expect($event->hasStarted())->toBeTrue();
});

test('BR-E5: an event that starts in one second has not started', function () {
    $this->freezeTime();

    $event = Event::factory()->create(['starts_at' => now()->addSecond()]);

    expect($event->hasStarted())->toBeFalse();
});

test('BR-E4: an event knows its organizer', function () {
    $organizer = User::factory()->create();
    $event = Event::factory()->for($organizer, 'organizer')->create();

    expect($event->isOrganizedBy($organizer))->toBeTrue()
        ->and($event->isOrganizedBy(User::factory()->create()))->toBeFalse();
});
```

- [ ] **Step 2: Run the tests to make sure they fail**

Run: `docker compose exec app php artisan test --compact tests/Feature/EventsTableTest.php tests/Feature/EventModelTest.php`
Expected: FAIL. The class `App\Models\Event` does not exist.

- [ ] **Step 3: Create the enum**

Run: `docker compose exec app php artisan make:enum Enums/EventStatus --string --no-interaction`

```php
<?php

namespace App\Enums;

enum EventStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
    case Cancelled = 'cancelled';
}
```

- [ ] **Step 4: Create the model, the factory and the migration**

Run: `docker compose exec app php artisan make:model Event --migration --factory --no-interaction`

The migration:

```php
public function up(): void
{
    Schema::create('events', function (Blueprint $table) {
        $table->id();
        $table->foreignId('organizer_id')->constrained('users')->restrictOnDelete();
        $table->string('title');
        $table->text('description');
        $table->string('venue');
        $table->timestampTz('starts_at');
        $table->integer('capacity');
        $table->integer('seats_available');
        $table->string('status')->default('draft');
        $table->timestampTz('published_at')->nullable();
        $table->timestampsTz();

        $table->index(['status', 'starts_at']);
        $table->index('organizer_id');
    });

    DB::statement('ALTER TABLE events ADD CONSTRAINT events_capacity_check CHECK (capacity > 0)');
    DB::statement('ALTER TABLE events ADD CONSTRAINT events_seats_available_check CHECK (seats_available >= 0 AND seats_available <= capacity)');
    DB::statement("ALTER TABLE events ADD CONSTRAINT events_status_check CHECK (status IN ('draft', 'published', 'cancelled'))");
}

public function down(): void
{
    Schema::dropIfExists('events');
}
```

Add `use Illuminate\Support\Facades\DB;` to the imports.

`app/Models/Event.php`:

```php
<?php

namespace App\Models;

use App\Enums\EventStatus;
use Database\Factories\EventFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $organizer_id
 * @property string $title
 * @property string $description
 * @property string $venue
 * @property Carbon $starts_at
 * @property int $capacity
 * @property int $seats_available
 * @property EventStatus $status
 * @property Carbon|null $published_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Event extends Model
{
    /** @use HasFactory<EventFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'published_at' => 'datetime',
            'status' => EventStatus::class,
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function organizer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'organizer_id');
    }

    public function isOrganizedBy(User $user): bool
    {
        return $this->organizer_id === $user->id;
    }

    /**
     * An event has started when its start time is now or in the past.
     */
    public function hasStarted(): bool
    {
        return ! $this->starts_at->isFuture();
    }

    public function isDraft(): bool
    {
        return $this->status === EventStatus::Draft;
    }

    public function isPublished(): bool
    {
        return $this->status === EventStatus::Published;
    }

    public function isCancelled(): bool
    {
        return $this->status === EventStatus::Cancelled;
    }
}
```

`database/factories/EventFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Enums\EventStatus;
use App\Models\Event;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Event>
 */
class EventFactory extends Factory
{
    /**
     * Define the model's default state: a future draft with all seats available.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organizer_id' => User::factory(),
            'title' => fake()->sentence(3),
            'description' => fake()->paragraph(),
            'venue' => fake()->city(),
            'starts_at' => now()->addDays(fake()->numberBetween(1, 60))->startOfHour(),
            'capacity' => 50,
            'seats_available' => fn (array $attributes) => $attributes['capacity'],
            'status' => EventStatus::Draft,
            'published_at' => null,
        ];
    }

    /**
     * Indicate that the event is published.
     */
    public function published(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => EventStatus::Published,
            'published_at' => now(),
        ]);
    }

    /**
     * Indicate that the event is cancelled.
     */
    public function cancelled(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => EventStatus::Cancelled,
        ]);
    }

    /**
     * Indicate that the event started one hour ago.
     */
    public function started(): static
    {
        return $this->state(fn (array $attributes) => [
            'starts_at' => now()->subHour(),
        ]);
    }
}
```

- [ ] **Step 5: Run the tests to make sure they pass**

Run: `docker compose exec app php artisan test --compact tests/Feature/EventsTableTest.php tests/Feature/EventModelTest.php`
Expected: PASS (12 tests).

- [ ] **Step 6: Commit**

```bash
git add app/Enums/EventStatus.php app/Models/Event.php database/factories/EventFactory.php \
  database/migrations/*_create_events_table.php tests/Feature/EventsTableTest.php \
  tests/Feature/EventModelTest.php
git commit -m "feat: add the events table and the event model"
```

### Task 3: `EventPolicy`

**Files:**

- Create: `app/Policies/EventPolicy.php`
- Test: `tests/Feature/EventPolicyTest.php`

**Interfaces:**

- Consumes: `User::factory()->admin()` (Task 1), `Event::factory()` and its states,
  `Event::isOrganizedBy()`, `hasStarted()`, `isDraft()`, `isPublished()`,
  `isCancelled()` (Task 2).
- Produces: the abilities `view` (also for visitors), `update`, `publish`, `cancel`,
  `delete` on `Event`. Laravel finds the policy by its name.

- [ ] **Step 1: Write the failing test**

Create the file with `php artisan make:test --pest EventPolicyTest --no-interaction`,
then replace its content:

```php
<?php

use App\Models\Event;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

beforeEach(function () {
    $this->organizer = User::factory()->create();
    $this->otherUser = User::factory()->create();
    $this->admin = User::factory()->admin()->create();
});

// View: BR-E14, BR-E15, BR-E16, BR-A4

test('BR-E14: everyone can see a published event, also visitors', function () {
    $event = Event::factory()->published()->for($this->organizer, 'organizer')->create();

    expect(Gate::forUser(null)->allows('view', $event))->toBeTrue()
        ->and($this->otherUser->can('view', $event))->toBeTrue()
        ->and($this->organizer->can('view', $event))->toBeTrue();
});

test('BR-E15: only the organizer and admins can see a draft event', function () {
    $event = Event::factory()->for($this->organizer, 'organizer')->create();

    expect($this->organizer->can('view', $event))->toBeTrue()
        ->and($this->admin->can('view', $event))->toBeTrue()
        ->and($this->otherUser->can('view', $event))->toBeFalse()
        ->and(Gate::forUser(null)->allows('view', $event))->toBeFalse();
});

test('BR-E16: the organizer and admins can see a cancelled event', function () {
    $event = Event::factory()->cancelled()->for($this->organizer, 'organizer')->create();

    expect($this->organizer->can('view', $event))->toBeTrue()
        ->and($this->admin->can('view', $event))->toBeTrue()
        ->and($this->otherUser->can('view', $event))->toBeFalse()
        ->and(Gate::forUser(null)->allows('view', $event))->toBeFalse();
});

test('BR-A4: an admin can see the draft and cancelled events of all users', function () {
    $draft = Event::factory()->create();
    $cancelled = Event::factory()->cancelled()->create();

    expect($this->admin->can('view', $draft))->toBeTrue()
        ->and($this->admin->can('view', $cancelled))->toBeTrue();
});

// Update: BR-E4, BR-E5, BR-A3

test('BR-E4: only the organizer can edit an event', function () {
    $event = Event::factory()->for($this->organizer, 'organizer')->create();

    expect($this->organizer->can('update', $event))->toBeTrue()
        ->and($this->otherUser->can('update', $event))->toBeFalse()
        ->and(Gate::forUser(null)->allows('update', $event))->toBeFalse();
});

test('BR-E4: the organizer can edit a published event that has not started', function () {
    $event = Event::factory()->published()->for($this->organizer, 'organizer')->create();

    expect($this->organizer->can('update', $event))->toBeTrue();
});

test('BR-E5: nobody can edit a cancelled event', function () {
    $event = Event::factory()->cancelled()->for($this->organizer, 'organizer')->create();

    expect($this->organizer->can('update', $event))->toBeFalse();
});

test('BR-E5: nobody can edit a started event', function () {
    $event = Event::factory()->published()->started()->for($this->organizer, 'organizer')->create();

    expect($this->organizer->can('update', $event))->toBeFalse();
});

test('BR-E5: nobody can edit an event that starts now', function () {
    $this->freezeTime();
    $event = Event::factory()->for($this->organizer, 'organizer')->create(['starts_at' => now()]);

    expect($this->organizer->can('update', $event))->toBeFalse();
});

test('BR-A3: an admin cannot edit the events of other users', function () {
    $event = Event::factory()->for($this->organizer, 'organizer')->create();

    expect($this->admin->can('update', $event))->toBeFalse();
});

test('BR-A3: an admin can edit their own events', function () {
    $event = Event::factory()->for($this->admin, 'organizer')->create();

    expect($this->admin->can('update', $event))->toBeTrue();
});

// Publish: BR-E9, BR-A3

test('BR-E9: only the organizer can publish an event', function () {
    $event = Event::factory()->for($this->organizer, 'organizer')->create();

    expect($this->organizer->can('publish', $event))->toBeTrue()
        ->and($this->otherUser->can('publish', $event))->toBeFalse()
        ->and(Gate::forUser(null)->allows('publish', $event))->toBeFalse();
});

test('BR-A3: an admin cannot publish the events of other users', function () {
    $event = Event::factory()->for($this->organizer, 'organizer')->create();

    expect($this->admin->can('publish', $event))->toBeFalse();
});

test('BR-A3: an admin can publish their own events', function () {
    $event = Event::factory()->for($this->admin, 'organizer')->create();

    expect($this->admin->can('publish', $event))->toBeTrue();
});

// Cancel: BR-E11, BR-A1

test('BR-E11: the organizer can cancel an event', function () {
    $event = Event::factory()->published()->for($this->organizer, 'organizer')->create();

    expect($this->organizer->can('cancel', $event))->toBeTrue()
        ->and($this->otherUser->can('cancel', $event))->toBeFalse()
        ->and(Gate::forUser(null)->allows('cancel', $event))->toBeFalse();
});

test('BR-E11: an admin can cancel any event', function () {
    $event = Event::factory()->published()->for($this->organizer, 'organizer')->create();

    expect($this->admin->can('cancel', $event))->toBeTrue();
});

// Delete: BR-E17

test('BR-E17: the organizer can delete a draft event', function () {
    $event = Event::factory()->for($this->organizer, 'organizer')->create();

    expect($this->organizer->can('delete', $event))->toBeTrue()
        ->and($this->otherUser->can('delete', $event))->toBeFalse()
        ->and($this->admin->can('delete', $event))->toBeFalse()
        ->and(Gate::forUser(null)->allows('delete', $event))->toBeFalse();
});

test('BR-E17: nobody can delete a published or cancelled event', function () {
    $published = Event::factory()->published()->for($this->organizer, 'organizer')->create();
    $cancelled = Event::factory()->cancelled()->for($this->organizer, 'organizer')->create();

    expect($this->organizer->can('delete', $published))->toBeFalse()
        ->and($this->organizer->can('delete', $cancelled))->toBeFalse();
});
```

- [ ] **Step 2: Run the test to make sure it fails**

Run: `docker compose exec app php artisan test --compact tests/Feature/EventPolicyTest.php`
Expected: FAIL. Without a policy, all checks return `false`, so the tests that expect
`true` fail.

- [ ] **Step 3: Write the policy**

Run: `docker compose exec app php artisan make:policy EventPolicy --model=Event --no-interaction`,
then replace its content:

```php
<?php

namespace App\Policies;

use App\Models\Event;
use App\Models\User;

class EventPolicy
{
    /**
     * BR-E14, BR-E15, BR-E16 and BR-A4. Attendees of a cancelled event come in M4.
     */
    public function view(?User $user, Event $event): bool
    {
        if ($event->isPublished()) {
            return true;
        }

        if ($user === null) {
            return false;
        }

        return $user->is_admin || $event->isOrganizedBy($user);
    }

    /**
     * BR-E4, BR-E5 and BR-A3.
     */
    public function update(User $user, Event $event): bool
    {
        return $event->isOrganizedBy($user)
            && ! $event->isCancelled()
            && ! $event->hasStarted();
    }

    /**
     * BR-E9 and BR-A3. The model checks the state of the event (BR-E10).
     */
    public function publish(User $user, Event $event): bool
    {
        return $event->isOrganizedBy($user);
    }

    /**
     * BR-E11 and BR-A1. The model checks the state of the event (BR-E12).
     */
    public function cancel(User $user, Event $event): bool
    {
        return $user->is_admin || $event->isOrganizedBy($user);
    }

    /**
     * BR-E17.
     */
    public function delete(User $user, Event $event): bool
    {
        return $event->isOrganizedBy($user) && $event->isDraft();
    }
}
```

- [ ] **Step 4: Run the test to make sure it passes**

Run: `docker compose exec app php artisan test --compact tests/Feature/EventPolicyTest.php`
Expected: PASS (18 tests).

- [ ] **Step 5: Commit**

```bash
git add app/Policies/EventPolicy.php tests/Feature/EventPolicyTest.php
git commit -m "feat: add the event policy"
```

### Task 4: Checks and handoff

**Files:**

- Modify: `HANDOFF.md`

- [ ] **Step 1: Format and check**

Run: `docker compose exec app vendor/bin/pint --dirty --format agent`
Run: `docker compose exec app composer ci:check`
Expected: Pint, PHPStan, `vp check`, `tsc` and all tests pass.

- [ ] **Step 2: Check the migrations both ways**

Run: `docker compose exec app php artisan migrate:fresh` and then
`docker compose exec app php artisan migrate:rollback --step=2`, then
`docker compose exec app php artisan migrate`.
Expected: no errors.

- [ ] **Step 3: Update `HANDOFF.md`**

"Where we are": M3 has started. Describe this PR and the agreed split of M3 into eight
PRs. Note that the attendee part of BR-E16 comes in M4. "Next steps": the owner
reviews this PR, then PR 2 (`GetEvent`).

- [ ] **Step 4: Format Markdown and commit**

Run: `npm run check:fix`

```bash
git add HANDOFF.md docs/superpowers/plans/2026-10-06-m3-events-and-policy.md
git commit -m "docs: add the M3 events plan and update handoff"
```
