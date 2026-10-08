# M6 — Account Rules: When an Account Can Be Deleted and How It Is Anonymized

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** The `User` model knows when the account can be deleted (BR-U1, BR-U2) and how
to anonymize the account (BR-U4). This PR adds no route and changes no page; M6 PR 2
connects the rules to the "Delete account" page.

**Architecture:** Two migrations add `users.anonymized_at` and make `users.password`
nullable. `User` gets the relation `organizedEvents()`, `ensureCanBeDeleted()` (throws
`AccountCannotBeDeleted`), `isAnonymized()` and `anonymize()`. `anonymize()` changes
the attributes and saves nothing, like `Event::cancel()`. The factory gets the state
`anonymized()`.

**Tech Stack:** Laravel 13 migrations and Eloquent, Pest.

**Spec:** `docs/superpowers/specs/2026-09-28-event-booking-design.md` (sections 4 and
5), `docs/design/business-rules.md` (BR-U1 to BR-U5), `docs/design/erd.md` (`users`),
`docs/superpowers/plans/milestones.md` (M6).

## Global Constraints

- M6 is split into three PRs: 1 the account rules on the model (this PR), 2 the
  `DeleteAccount` Action on the existing "Delete account" page (`profile.destroy`),
  3 BR-U5 on every login path (password, passkey, password reset) and a middleware that
  ends open sessions of an anonymized user.
- Owner decisions for M6:
    - The placeholder email is `deleted-user-{id}@deleted.invalid`. The `.invalid`
      top-level domain never resolves, and the ID keeps it unique.
    - `DeleteAccount`, `ReserveSeats`, `CreateEvent` and `PublishEvent` lock the user
      row first (PR 2), so a booking or a publish cannot slip in during a delete.
    - Only drafts are deleted (BR-U3, PR 2). Cancelled and past events stay.
- Migrations:
    - `add_anonymized_at_to_users_table`: `timestampTz('anonymized_at')->nullable()`.
    - `make_password_nullable_on_users_table`: `string('password')->nullable()->change()`.
      `down()` makes it not nullable again. Do not run `migrate:fresh` on the
      development database; `php artisan migrate` is enough.
- `User::ensureCanBeDeleted()` checks in this order:
    1. BR-U1: the user organizes no published event that has not started. Otherwise:
       `AccountCannotBeDeleted::organizesUpcomingEvent()`.
    2. BR-U2: the user has no confirmed booking for an event that has not started.
       Otherwise: `AccountCannotBeDeleted::holdsUpcomingBooking()`.
- Error messages:
    - `You organize a published event that has not started. Cancel the event before you delete your account.`
    - `You have a booking for an event that has not started. Cancel the booking before you delete your account.`
- `User::anonymize()` (BR-U4) sets: `name` = `Deleted user`, `email` =
  `deleted-user-{id}@deleted.invalid`, `password` = null, `remember_token` = null,
  `two_factor_secret`, `two_factor_recovery_codes` and `two_factor_confirmed_at` = null,
  `anonymized_at` = now. It saves nothing and does not touch passkeys (PR 2 deletes
  them). `email_verified_at` stays.
- "Has not started" uses the existing scope `Event::upcoming()` (`starts_at > now`), the
  same edge as `Event::hasStarted()`.
- Run commands inside the `app` container: `docker compose exec app <command>`. Pint
  `--dirty` does not work in the container (no git); pass the file paths. `vp check
--fix` also formats the code blocks of this plan.

## Review Focus

1. **The start-time edge.** An event that starts exactly now has started, so it does not
   block the delete. Pinned by tests in Task 2.
2. **Draft and cancelled events that have not started** do not block (BR-U1 names only
   published events). Pinned by tests in Task 2.
3. **Cancelled bookings and bookings of past events** do not block. Pinned by tests in
   Task 2.
4. **Another user's events or bookings** do not block this user. Pinned by a test in
   Task 2.
5. **A null password** passes through the `hashed` cast and is stored as null, and the
   placeholder email stays unique for two users. Pinned by tests in Task 1.

---

### Task 1: Columns, `anonymize()` and `isAnonymized()`

**Files:**

- Create: `database/migrations/<timestamp>_add_anonymized_at_to_users_table.php` (with
  `php artisan make:migration add_anonymized_at_to_users_table --no-interaction`)
- Create: `database/migrations/<timestamp>_make_password_nullable_on_users_table.php`
  (with `php artisan make:migration make_password_nullable_on_users_table --no-interaction`)
- Modify: `app/Models/User.php`
- Modify: `database/factories/UserFactory.php`
- Test: `tests/Feature/UserModelTest.php` (with
  `php artisan make:test --pest UserModelTest --no-interaction`)

**Interfaces:**

- Produces: `User::anonymize(): void`, `User::isAnonymized(): bool`, the property
  `CarbonImmutable|Carbon|null $anonymized_at` (follow the type of the other dates in the
  `User` docblock), `string|null $password`, the factory state
  `UserFactory::anonymized()`.

- [ ] **Step 1: Write the failing tests**

```php
<?php

use App\Models\User;

test('BR-U4: anonymize replaces the personal data and keeps the row', function () {
    $this->freezeSecond();
    $user = User::factory()->withTwoFactor()->create(['name' => 'Ada Lovelace']);

    $user->anonymize();

    expect($user->name)->toBe('Deleted user')
        ->and($user->email)->toBe("deleted-user-{$user->id}@deleted.invalid")
        ->and($user->password)->toBeNull()
        ->and($user->remember_token)->toBeNull()
        ->and($user->two_factor_secret)->toBeNull()
        ->and($user->two_factor_recovery_codes)->toBeNull()
        ->and($user->two_factor_confirmed_at)->toBeNull()
        ->and($user->anonymized_at->equalTo(now()))->toBeTrue()
        ->and($user->isAnonymized())->toBeTrue()
        ->and($user->isDirty())->toBeTrue();
});

test('BR-U4: the anonymized user is stored with a null password', function () {
    $user = User::factory()->create();

    $user->anonymize();
    $user->save();

    $stored = $user->fresh();

    expect($stored)->not->toBeNull()
        ->and($stored->password)->toBeNull()
        ->and($stored->anonymized_at)->not->toBeNull()
        ->and($stored->isAnonymized())->toBeTrue();
});

test('BR-U4: two anonymized users get different emails', function () {
    $first = User::factory()->create();
    $second = User::factory()->create();

    $first->anonymize();
    $first->save();
    $second->anonymize();
    $second->save();

    expect($first->fresh()->email)->not->toBe($second->fresh()->email);
});

test('a new user is not anonymized', function () {
    $user = User::factory()->create();

    expect($user->fresh()->anonymized_at)->toBeNull()
        ->and($user->isAnonymized())->toBeFalse();
});

test('the anonymized factory state makes an anonymized user', function () {
    $user = User::factory()->anonymized()->create();

    expect($user->fresh()->isAnonymized())->toBeTrue()
        ->and($user->fresh()->name)->toBe('Deleted user')
        ->and($user->fresh()->password)->toBeNull();
});
```

- [ ] **Step 2: Run the tests to see them fail**

Run: `docker compose exec app php artisan test --compact tests/Feature/UserModelTest.php`
Expected: FAIL (`anonymize` does not exist, or the `anonymized` state is missing).

- [ ] **Step 3: Write the migrations**

`add_anonymized_at_to_users_table`:

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
        Schema::table('users', function (Blueprint $table) {
            $table->timestampTz('anonymized_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('anonymized_at');
        });
    }
};
```

`make_password_nullable_on_users_table`:

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
        Schema::table('users', function (Blueprint $table) {
            $table->string('password')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('password')->nullable(false)->change();
        });
    }
};
```

Run: `docker compose exec app php artisan migrate --no-interaction`
Expected: both migrations run on the development database.

- [ ] **Step 4: Change the model and the factory**

In `app/Models/User.php`:

1. In the class docblock: change `@property string $password` to
   `@property string|null $password`; add `@property Carbon|null $anonymized_at` after
   `$two_factor_confirmed_at` (use the same date type as the other dates there).
2. Add `'anonymized_at' => 'datetime',` to `casts()`.
3. Add after `bookings()`:

```php
    /**
     * Replace the personal data of the user and remove the ways to log in (BR-U4). The
     * row stays, so that events and bookings keep their history. Saves nothing.
     */
    public function anonymize(): void
    {
        $this->name = 'Deleted user';
        $this->email = "deleted-user-{$this->id}@deleted.invalid";
        $this->password = null;
        $this->remember_token = null;
        $this->two_factor_secret = null;
        $this->two_factor_recovery_codes = null;
        $this->two_factor_confirmed_at = null;
        $this->anonymized_at = now();
    }

    /**
     * Whether the user deleted the account.
     */
    public function isAnonymized(): bool
    {
        return $this->anonymized_at !== null;
    }
```

`remember_token` may need `$this->setRememberToken(null)` if PHPStan complains; both
write the same column.

In `database/factories/UserFactory.php`, add after `admin()` (follow its docblock
style):

```php
    /**
     * Indicate that the user deleted the account.
     */
    public function anonymized(): static
    {
        return $this->state(fn (array $attributes) => [
            'name' => 'Deleted user',
            'password' => null,
            'remember_token' => null,
            'anonymized_at' => now(),
        ])->afterCreating(function (User $user): void {
            $user->forceFill(['email' => "deleted-user-{$user->id}@deleted.invalid"])->save();
        });
    }
```

Import `App\Models\User` in the factory if it is missing.

- [ ] **Step 5: Run the tests to see them pass**

Run: `docker compose exec app php artisan test --compact tests/Feature/UserModelTest.php tests/Feature/UserAdminFlagTest.php`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
docker compose exec app vendor/bin/pint --format agent app/Models/User.php database/factories/UserFactory.php tests/Feature/UserModelTest.php database/migrations/*_add_anonymized_at_to_users_table.php database/migrations/*_make_password_nullable_on_users_table.php
git add app/Models/User.php database/factories/UserFactory.php tests/Feature/UserModelTest.php database/migrations/*_add_anonymized_at_to_users_table.php database/migrations/*_make_password_nullable_on_users_table.php
git commit -m "feat: add anonymized_at and the anonymize rule to users"
```

---

### Task 2: `ensureCanBeDeleted()` (BR-U1, BR-U2)

**Files:**

- Create: `app/Exceptions/Domain/AccountCannotBeDeleted.php`
- Modify: `app/Models/User.php`
- Test: `tests/Feature/UserModelTest.php`

**Interfaces:**

- Produces: `User::organizedEvents(): HasMany`, `User::ensureCanBeDeleted(): void`
  (throws `AccountCannotBeDeleted`), `AccountCannotBeDeleted::organizesUpcomingEvent()`,
  `AccountCannotBeDeleted::holdsUpcomingBooking()`.

- [ ] **Step 1: Write the failing tests**

Add to `tests/Feature/UserModelTest.php` (and the `use` lines
`App\Exceptions\Domain\AccountCannotBeDeleted`, `App\Models\Booking` and
`App\Models\Event`):

```php
test('a user with no events and no bookings can be deleted', function () {
    $user = User::factory()->create();

    $user->ensureCanBeDeleted();
})->throwsNoExceptions();

test('BR-U1: a user who organizes a published event that has not started cannot be deleted', function () {
    $user = User::factory()->create();
    Event::factory()->published()->for($user, 'organizer')->create(['starts_at' => now()->addDay()]);

    $user->ensureCanBeDeleted();
})->throws(
    AccountCannotBeDeleted::class,
    'You organize a published event that has not started. Cancel the event before you delete your account.',
);

test('BR-U1: drafts, cancelled events and started events do not block the delete', function () {
    $this->freezeSecond();
    $user = User::factory()->create();
    Event::factory()->for($user, 'organizer')->create(['starts_at' => now()->addDay()]);
    Event::factory()->cancelled()->for($user, 'organizer')->create(['starts_at' => now()->addDay()]);
    Event::factory()->published()->for($user, 'organizer')->create(['starts_at' => now()]);
    Event::factory()->published()->for($user, 'organizer')->create(['starts_at' => now()->subDay()]);

    $user->ensureCanBeDeleted();
})->throwsNoExceptions();

test('BR-U2: a user with a confirmed booking for an event that has not started cannot be deleted', function () {
    $user = User::factory()->create();
    $event = Event::factory()->published()->create(['starts_at' => now()->addDay()]);
    Booking::factory()->for($event)->for($user, 'attendee')->create();

    $user->ensureCanBeDeleted();
})->throws(
    AccountCannotBeDeleted::class,
    'You have a booking for an event that has not started. Cancel the booking before you delete your account.',
);

test('BR-U2: cancelled bookings and bookings of started events do not block the delete', function () {
    $this->freezeSecond();
    $user = User::factory()->create();
    $upcoming = Event::factory()->published()->create(['starts_at' => now()->addDay()]);
    $started = Event::factory()->published()->create(['starts_at' => now()]);
    Booking::factory()->cancelled()->for($upcoming)->for($user, 'attendee')->create();
    Booking::factory()->for($started)->for($user, 'attendee')->create();

    $user->ensureCanBeDeleted();
})->throwsNoExceptions();

test('BR-U1: the organizer check comes before the booking check', function () {
    $user = User::factory()->create();
    Event::factory()->published()->for($user, 'organizer')->create(['starts_at' => now()->addDay()]);
    $other = Event::factory()->published()->create(['starts_at' => now()->addDay()]);
    Booking::factory()->for($other)->for($user, 'attendee')->create();

    $user->ensureCanBeDeleted();
})->throws(AccountCannotBeDeleted::class, 'You organize a published event');

test('events and bookings of other users do not block the delete', function () {
    $user = User::factory()->create();
    $event = Event::factory()->published()->create(['starts_at' => now()->addDay()]);
    Booking::factory()->for($event)->create();

    $user->ensureCanBeDeleted();
})->throwsNoExceptions();
```

`throwsNoExceptions()` exists in the installed Pest (`TestCall::throwsNoExceptions()`).

- [ ] **Step 2: Run the tests to see them fail**

Run: `docker compose exec app php artisan test --compact tests/Feature/UserModelTest.php --filter="deleted|delete"`
Expected: FAIL (`ensureCanBeDeleted` does not exist).

- [ ] **Step 3: Write the exception**

`app/Exceptions/Domain/AccountCannotBeDeleted.php` (follow `EventHasStarted.php`):

```php
<?php

namespace App\Exceptions\Domain;

/**
 * The account cannot be deleted while the user takes part in an event that has not
 * started.
 */
class AccountCannotBeDeleted extends DomainException
{
    /**
     * BR-U1: the user organizes a published event that has not started.
     */
    public static function organizesUpcomingEvent(): self
    {
        return new self(__('You organize a published event that has not started. Cancel the event before you delete your account.'));
    }

    /**
     * BR-U2: the user has a confirmed booking for an event that has not started.
     */
    public static function holdsUpcomingBooking(): self
    {
        return new self(__('You have a booking for an event that has not started. Cancel the booking before you delete your account.'));
    }
}
```

- [ ] **Step 4: Add the relation and the check to `User`**

Add after `bookings()` (import `App\Enums\BookingStatus`,
`App\Exceptions\Domain\AccountCannotBeDeleted` and
`Illuminate\Database\Eloquent\Builder` where needed; add
`@property-read Collection<int, Event> $organizedEvents` to the class docblock):

```php
    /**
     * The events that the user organizes, in all statuses.
     *
     * @return HasMany<Event, $this>
     */
    public function organizedEvents(): HasMany
    {
        return $this->hasMany(Event::class, 'organizer_id');
    }
```

Add before `anonymize()`:

```php
    /**
     * Check that the user can delete the account: the user organizes no published event
     * that has not started (BR-U1), and has no confirmed booking for an event that has
     * not started (BR-U2).
     *
     * @throws AccountCannotBeDeleted
     */
    public function ensureCanBeDeleted(): void
    {
        if ($this->organizedEvents()->published()->upcoming()->exists()) {
            throw AccountCannotBeDeleted::organizesUpcomingEvent();
        }

        $holdsUpcomingBooking = $this->bookings()
            ->where('status', BookingStatus::Confirmed)
            ->whereHas('event', fn (Builder $query) => $query->upcoming())
            ->exists();

        if ($holdsUpcomingBooking) {
            throw AccountCannotBeDeleted::holdsUpcomingBooking();
        }
    }
```

If PHPStan asks for the generic type of the `Builder` in the closure, use
`Builder<Event>` in a `@param` on a typed closure, or the style that `Event` scopes
already use.

- [ ] **Step 5: Run the tests to see them pass**

Run: `docker compose exec app php artisan test --compact tests/Feature/UserModelTest.php`
Expected: PASS (all tests of the file).

- [ ] **Step 6: Commit**

```bash
docker compose exec app vendor/bin/pint --format agent app/Exceptions/Domain/AccountCannotBeDeleted.php app/Models/User.php tests/Feature/UserModelTest.php
git add app/Exceptions/Domain/AccountCannotBeDeleted.php app/Models/User.php tests/Feature/UserModelTest.php
git commit -m "feat: add the rules that block an account delete"
```

---

### Task 3: ERD, handoff and plan

**Files:**

- Modify: `docs/design/erd.md` (`users.password` is nullable)
- Modify: `HANDOFF.md`
- Add: `docs/superpowers/plans/2026-10-08-m6-account-rules.md`

- [ ] **Step 1: Update the ERD**

In `docs/design/erd.md`, mark `users.password` as nullable in the diagram, in the same
way as `anonymized_at` (for example `string password "nullable, removed when the user
deletes the account"`). If the ERD has a table of the `users` columns, update it the
same way. Render the ERD diagram locally:
`npx -y @mermaid-js/mermaid-cli -i docs/design/erd.md -o <scratch-dir>/erd.md`
Expected: no errors.

- [ ] **Step 2: Format and run all checks**

Run: `npm run check:fix`
Run: `docker compose exec app composer ci:check`
Expected: Pint, PHPStan, `vp check`, `tsc` and all tests pass.

- [ ] **Step 3: Update `HANDOFF.md`**

"Where we are": M5 is complete (PR 4 merged as #40). M6 (User accounts) is split into
the three PRs of the Global Constraints. The current PR is M6 PR 1
(`feat/account-rules`), with the path of this plan. Write that it is open as a PR and
waits for the merge (no PR number). Replace the M5 notes at the top with short "M5
notes" (keep the notification pattern, it is useful for later emails) and add the M6
decisions and this PR's notes from the Global Constraints. Say that the "Delete
account" page still calls `$user->delete()`, which fails with a database error for a
user with events or bookings, until PR 2. "Next steps": the owner reviews this PR. Then
M6 PR 2 (`DeleteAccount`).

- [ ] **Step 4: Commit**

```bash
git add docs/design/erd.md HANDOFF.md docs/superpowers/plans/2026-10-08-m6-account-rules.md
git commit -m "docs: document the account rules and update handoff"
```
