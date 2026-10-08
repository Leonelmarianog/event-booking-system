# M6 — DeleteAccount: a User Deletes the Account Without Damage to History

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** The "Delete account" page anonymizes the user instead of deleting the row
(BR-U4). The delete is blocked while the user organizes a published event that has not
started (BR-U1) or holds a confirmed booking for one (BR-U2). The drafts of the user are
deleted (BR-U3). A booking, a new event or a publish cannot slip in during a delete.

**Architecture:** The `DeleteAccount` Action runs one transaction: lock the user row,
`ensureCanBeDeleted()`, delete the drafts, delete the `password_reset_tokens` row of the
old email, delete the passkeys, `anonymize()`, save. `ProfileController@destroy` calls
it, then logs out and redirects home with a toast. `AccountCannotBeDeleted` belongs to
the `password` field, so the message shows in the open dialog and as a toast.
`ReserveSeats`, `CreateEvent` and `PublishEvent` lock the user row first and call
`User::ensureNotAnonymized()`, so they wait for a running delete and then refuse.

**Tech Stack:** Laravel 13, Inertia 3, React 19, Pest.

**Spec:** `docs/superpowers/specs/2026-09-28-event-booking-design.md` (sections 4, 5
and 7), `docs/design/business-rules.md` (BR-U1 to BR-U5),
`docs/superpowers/plans/milestones.md` (M6).

## Global Constraints

- Owner decisions: the placeholder email `deleted-user-{id}@deleted.invalid` (done in
  PR 1); lock the user row in `DeleteAccount`, `ReserveSeats`, `CreateEvent` and
  `PublishEvent`; only drafts are deleted, cancelled and past events stay; a blocked
  delete shows the toast and the same message under the password field; the new texts
  below.
- Lock order: the user row first, then the event row. `CancelBooking`, `CancelEvent`,
  `UpdateEvent` and `DeleteEvent` take no user lock, so no cycle is possible: none of
  them waits for a user row.
- `DeleteAccount::handle(User $user): void`, in one transaction, in this order:
    1. `User::query()->lockForUpdate()->findOrFail($user->id)`.
    2. `ensureCanBeDeleted()` (BR-U1, BR-U2).
    3. Delete the drafts of the user: `organizedEvents()->where('status',
EventStatus::Draft)->delete()` (BR-U3). Drafts have no bookings.
    4. Delete the `password_reset_tokens` row of the old email (read the email first).
    5. `passkeys()->delete()`.
    6. `anonymize()`, `save()` (BR-U4).
- `AccountCannotBeDeleted::field()` returns `'password'`.
- `User::ensureNotAnonymized()` throws `AccountDeleted` with the message
  `Your account was deleted.` The three Actions call it on the locked user row.
- `ProfileController@destroy`: validate the password (existing
  `ProfileDeleteRequest`), call the Action, `Auth::logout()`, invalidate the session,
  regenerate the token, flash the toast `Your account is deleted.` (`success`), redirect
  to `/` (the existing `home` route, which redirects to `/events`).
- New texts in `resources/js/components/delete-user.tsx`:
    - Section description: `Delete your account. Past events and bookings stay, with the
name "Deleted user".`
    - Dialog description: `Your drafts are deleted, and your name, email and password
are removed. Past events and bookings stay, with the name "Deleted user". You cannot
undo this. Enter your password to confirm.`
    - Keep the title, the warning box and the buttons.
- The starter-kit test `test_user_can_delete_their_account` in
  `tests/Feature/Settings/ProfileUpdateTest.php` asserts that the row is gone. It changes
  to assert that the row stays and is anonymized (the test stays, only its last
  assertion changes).
- Run commands inside the `app` container: `docker compose exec app <command>`. Pint
  `--dirty` does not work in the container (no git); pass the file paths. `vp check
--fix` also formats the code blocks of this plan; copy JSX from the plan with care.

## Review Focus

1. **A booking or a publish during the delete.** After the delete commits, the waiting
   request sees an anonymized user and refuses. Pinned by the "anonymized user" tests in
   Task 3 (sequential: the locked re-read is the same path).
2. **A blocked delete changes nothing.** No draft deleted, no passkey deleted, not
   anonymized, still logged in. Pinned by tests in Task 1 and Task 2.
3. **Other users' drafts, reset tokens and passkeys stay.** Pinned by a test in Task 1.
4. **Cancelled and past events and old bookings stay** with the user as organizer or
   attendee. Pinned by a test in Task 1.
5. **A wrong password** gives the existing validation error and changes nothing. Pinned
   by the starter-kit test (kept) and a test in Task 2.

---

### Task 1: The `DeleteAccount` Action

**Files:**

- Create: `app/Actions/DeleteAccount/DeleteAccount.php`
- Modify: `app/Exceptions/Domain/AccountCannotBeDeleted.php` (add `field()`)
- Test: `tests/Feature/DeleteAccountTest.php` (with
  `php artisan make:test --pest DeleteAccountTest --no-interaction`)

**Interfaces:**

- Consumes: `User::ensureCanBeDeleted()`, `User::anonymize()`,
  `User::organizedEvents()`, `User::passkeys()` (Laravel Passkeys).
- Produces: `DeleteAccount::handle(User $user): void`.

- [ ] **Step 1: Write the failing tests**

```php
<?php

use App\Actions\DeleteAccount\DeleteAccount;
use App\Exceptions\Domain\AccountCannotBeDeleted;
use App\Models\Booking;
use App\Models\Event;
use App\Models\User;
use Illuminate\Support\Facades\DB;

function addPasskey(User $user): void
{
    $user->passkeys()->create([
        'name' => 'Laptop',
        'credential_id' => 'credential-'.$user->id,
        'credential' => ['id' => 'credential-'.$user->id],
    ]);
}

function addResetToken(string $email): void
{
    DB::table('password_reset_tokens')->insert([
        'email' => $email,
        'token' => 'hashed-token',
        'created_at' => now(),
    ]);
}

beforeEach(function () {
    $this->user = User::factory()->create(['email' => 'ada@example.com']);
});

test('BR-U4: the account is anonymized and the row stays', function () {
    app(DeleteAccount::class)->handle($this->user);

    $user = $this->user->fresh();

    expect($user)->not->toBeNull()
        ->and($user->isAnonymized())->toBeTrue()
        ->and($user->email)->toBe("deleted-user-{$user->id}@deleted.invalid");
});

test('BR-U3: the drafts of the user are deleted', function () {
    $draft = Event::factory()->for($this->user, 'organizer')->create();

    app(DeleteAccount::class)->handle($this->user);

    expect(Event::find($draft->id))->toBeNull();
});

test('BR-U4: cancelled and past events and old bookings stay', function () {
    $cancelled = Event::factory()->cancelled()->for($this->user, 'organizer')->create();
    $past = Event::factory()->published()->for($this->user, 'organizer')
        ->create(['starts_at' => now()->subDay()]);
    $otherPast = Event::factory()->published()->create(['starts_at' => now()->subDay()]);
    $booking = Booking::factory()->for($otherPast)->for($this->user, 'attendee')->create();

    app(DeleteAccount::class)->handle($this->user);

    expect(Event::find($cancelled->id))->not->toBeNull()
        ->and(Event::find($past->id)->organizer_id)->toBe($this->user->id)
        ->and($booking->fresh()->user_id)->toBe($this->user->id);
});

test('the passkeys and the password reset token of the user are deleted', function () {
    addPasskey($this->user);
    addResetToken('ada@example.com');

    app(DeleteAccount::class)->handle($this->user);

    expect($this->user->passkeys()->count())->toBe(0)
        ->and(DB::table('password_reset_tokens')->where('email', 'ada@example.com')->exists())->toBeFalse();
});

test('the data of other users stays', function () {
    $other = User::factory()->create(['email' => 'grace@example.com']);
    $otherDraft = Event::factory()->for($other, 'organizer')->create();
    addPasskey($other);
    addResetToken('grace@example.com');

    app(DeleteAccount::class)->handle($this->user);

    expect(Event::find($otherDraft->id))->not->toBeNull()
        ->and($other->passkeys()->count())->toBe(1)
        ->and(DB::table('password_reset_tokens')->where('email', 'grace@example.com')->exists())->toBeTrue()
        ->and($other->fresh()->isAnonymized())->toBeFalse();
});

test('BR-U1: a blocked delete changes nothing', function () {
    Event::factory()->published()->for($this->user, 'organizer')->create(['starts_at' => now()->addDay()]);
    $draft = Event::factory()->for($this->user, 'organizer')->create();
    addPasskey($this->user);
    addResetToken('ada@example.com');

    expect(fn () => app(DeleteAccount::class)->handle($this->user))
        ->toThrow(AccountCannotBeDeleted::class);

    expect($this->user->fresh()->isAnonymized())->toBeFalse()
        ->and(Event::find($draft->id))->not->toBeNull()
        ->and($this->user->passkeys()->count())->toBe(1)
        ->and(DB::table('password_reset_tokens')->where('email', 'ada@example.com')->exists())->toBeTrue();
});

test('BR-U2: a confirmed booking for an event that has not started blocks the delete', function () {
    $event = Event::factory()->published()->create(['starts_at' => now()->addDay()]);
    Booking::factory()->for($event)->for($this->user, 'attendee')->create();

    expect(fn () => app(DeleteAccount::class)->handle($this->user))
        ->toThrow(AccountCannotBeDeleted::class);

    expect($this->user->fresh()->isAnonymized())->toBeFalse();
});

test('the delete error belongs to the password field', function () {
    expect(AccountCannotBeDeleted::organizesUpcomingEvent()->field())->toBe('password')
        ->and(AccountCannotBeDeleted::holdsUpcomingBooking()->field())->toBe('password');
});
```

If the `passkeys` table needs more columns than `name`, `credential_id` and
`credential`, read `vendor/laravel/passkeys/src/Passkey.php` (`$fillable`) and the
migration, and fill them in the helper.

- [ ] **Step 2: Run the tests to see them fail**

Run: `docker compose exec app php artisan test --compact tests/Feature/DeleteAccountTest.php`
Expected: FAIL, class `App\Actions\DeleteAccount\DeleteAccount` not found.

- [ ] **Step 3: Write the Action and the field**

`app/Actions/DeleteAccount/DeleteAccount.php`:

```php
<?php

namespace App\Actions\DeleteAccount;

use App\Enums\EventStatus;
use App\Exceptions\Domain\AccountCannotBeDeleted;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class DeleteAccount
{
    /**
     * Delete the account of the user: check the rules (BR-U1, BR-U2), delete the drafts
     * (BR-U3), the password reset token and the passkeys, and anonymize the user row
     * (BR-U4). The Action locks the user row first, the same as ReserveSeats,
     * CreateEvent and PublishEvent, so a booking or a publish cannot slip in.
     *
     * @throws AccountCannotBeDeleted
     */
    public function handle(User $user): void
    {
        DB::transaction(function () use ($user): void {
            $user = User::query()->lockForUpdate()->findOrFail($user->id);

            $user->ensureCanBeDeleted();

            $user->organizedEvents()->where('status', EventStatus::Draft)->delete();

            DB::table('password_reset_tokens')->where('email', $user->email)->delete();
            $user->passkeys()->delete();

            $user->anonymize();
            $user->save();
        });
    }
}
```

The table name comes from `config('auth.passwords.users.table')`; use it instead of the
literal if PHPStan or the reviewer prefers the config value.

In `app/Exceptions/Domain/AccountCannotBeDeleted.php`, add (follow `NotEnoughSeats.php`):

```php
    /**
     * The error shows in the delete dialog, under the password field.
     */
    public function field(): ?string
    {
        return 'password';
    }
```

- [ ] **Step 4: Run the tests to see them pass**

Run: `docker compose exec app php artisan test --compact tests/Feature/DeleteAccountTest.php`
Expected: PASS (8 tests).

- [ ] **Step 5: Commit**

```bash
docker compose exec app vendor/bin/pint --format agent app/Actions/DeleteAccount/DeleteAccount.php app/Exceptions/Domain/AccountCannotBeDeleted.php tests/Feature/DeleteAccountTest.php
git add app/Actions/DeleteAccount/DeleteAccount.php app/Exceptions/Domain/AccountCannotBeDeleted.php tests/Feature/DeleteAccountTest.php
git commit -m "feat: add the DeleteAccount Action"
```

---

### Task 2: The "Delete account" page uses the Action

**Files:**

- Modify: `app/Http/Controllers/Settings/ProfileController.php`
- Modify: `resources/js/components/delete-user.tsx`
- Test: `tests/Feature/Settings/ProfileUpdateTest.php`
- Test: `tests/Feature/DeleteAccountTest.php`

**Interfaces:**

- Consumes: `DeleteAccount::handle(User $user): void` (Task 1).

- [ ] **Step 1: Write the failing tests**

In `tests/Feature/Settings/ProfileUpdateTest.php`, in
`test_user_can_delete_their_account`, replace `$this->assertNull($user->fresh());` with:

```php
        $this->assertNotNull($user->fresh());
        $this->assertTrue($user->fresh()->isAnonymized());
```

Add to `tests/Feature/DeleteAccountTest.php`:

```php
test('BR-U4: the page deletes the account, logs out and shows a toast', function () {
    $this->actingAs($this->user)
        ->delete(route('profile.destroy'), ['password' => 'password'])
        ->assertRedirect(route('home'))
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Your account is deleted.']);

    $this->assertGuest();
    expect($this->user->fresh()->isAnonymized())->toBeTrue();
});

test('BR-U1: a blocked delete shows the reason in the dialog and as a toast', function () {
    Event::factory()->published()->for($this->user, 'organizer')->create(['starts_at' => now()->addDay()]);
    $message = 'You organize a published event that has not started. Cancel the event before you delete your account.';

    $this->actingAs($this->user)
        ->from(route('profile.edit'))
        ->delete(route('profile.destroy'), ['password' => 'password'])
        ->assertRedirect(route('profile.edit'))
        ->assertSessionHasErrors(['password' => $message])
        ->assertInertiaFlash('toast', ['type' => 'error', 'message' => $message]);

    $this->assertAuthenticatedAs($this->user);
    expect($this->user->fresh()->isAnonymized())->toBeFalse();
});

test('a wrong password changes nothing', function () {
    $this->actingAs($this->user)
        ->from(route('profile.edit'))
        ->delete(route('profile.destroy'), ['password' => 'wrong-password'])
        ->assertSessionHasErrors('password');

    $this->assertAuthenticatedAs($this->user);
    expect($this->user->fresh()->isAnonymized())->toBeFalse();
});
```

- [ ] **Step 2: Run the tests to see them fail**

Run: `docker compose exec app php artisan test --compact tests/Feature/DeleteAccountTest.php tests/Feature/Settings/ProfileUpdateTest.php`
Expected: the page tests FAIL. `test_user_can_delete_their_account` fails because the
row is deleted; "a blocked delete" fails with a database error (foreign key) or a
missing toast; "a wrong password" may pass already.

- [ ] **Step 3: Use the Action in the controller**

In `ProfileController::destroy()`:

```php
    public function destroy(ProfileDeleteRequest $request, DeleteAccount $deleteAccount): RedirectResponse
    {
        $deleteAccount->handle($request->user());

        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Your account is deleted.')]);

        return redirect('/');
    }
```

Import `App\Actions\DeleteAccount\DeleteAccount` and `Inertia\Inertia`. Check that the
flash survives the session invalidate: `Inertia::flash()` writes to the session after
`invalidate()` here, so it is on the new session. If the test shows no flash, move the
`Inertia::flash()` call after `regenerateToken()` (it is already there) and check how
other controllers flash after a logout; rule on it in the ledger.

Update the docblock of `destroy()`: "Delete the account of the user with the
`DeleteAccount` Action (BR-U1 to BR-U4), then log out."

- [ ] **Step 4: Change the texts of the dialog**

In `resources/js/components/delete-user.tsx`:

- The `description` of the `Heading`:
  `Delete your account. Past events and bookings stay, with the name "Deleted user".`
- The `DialogDescription` text:
  `Your drafts are deleted, and your name, email and password are removed. Past events and bookings stay, with the name "Deleted user". You cannot undo this. Enter your password to confirm.`

Use `&quot;` or `{'"'}` if the linter rejects plain quotes in JSX text.

- [ ] **Step 5: Run the tests to see them pass**

Run: `docker compose exec app php artisan test --compact tests/Feature/DeleteAccountTest.php tests/Feature/Settings/ProfileUpdateTest.php`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
docker compose exec app vendor/bin/pint --format agent app/Http/Controllers/Settings/ProfileController.php tests/Feature/DeleteAccountTest.php tests/Feature/Settings/ProfileUpdateTest.php
npm run check:fix
git add app/Http/Controllers/Settings/ProfileController.php resources/js/components/delete-user.tsx tests/Feature/DeleteAccountTest.php tests/Feature/Settings/ProfileUpdateTest.php
git commit -m "feat: delete the account with DeleteAccount on the settings page"
```

---

### Task 3: Lock the user row in `ReserveSeats`, `CreateEvent` and `PublishEvent`

**Files:**

- Create: `app/Exceptions/Domain/AccountDeleted.php`
- Modify: `app/Models/User.php` (add `ensureNotAnonymized()`)
- Modify: `app/Actions/ReserveSeats/ReserveSeats.php`
- Modify: `app/Actions/CreateEvent/CreateEvent.php`
- Modify: `app/Actions/PublishEvent/PublishEvent.php`
- Test: `tests/Feature/UserModelTest.php`, `tests/Feature/ReserveSeatsTest.php`,
  `tests/Feature/CreateEventTest.php`, `tests/Feature/PublishEventTest.php`

**Interfaces:**

- Produces: `User::ensureNotAnonymized(): void` (throws `AccountDeleted`).

- [ ] **Step 1: Write the failing tests**

`tests/Feature/UserModelTest.php`:

```php
test('an anonymized user cannot act', function () {
    User::factory()->anonymized()->create()->ensureNotAnonymized();
})->throws(AccountDeleted::class, 'Your account was deleted.');

test('a normal user can act', function () {
    User::factory()->create()->ensureNotAnonymized();
})->throwsNoExceptions();
```

(add `use App\Exceptions\Domain\AccountDeleted;`).

Each Action test file gets one test that calls the Action directly with a user who was
anonymized after the request loaded it. This is the state a waiting request sees after
the delete commits. Read each file's `beforeEach` first and use its names.

`tests/Feature/ReserveSeatsTest.php`:

```php
test('a user who was deleted during the request cannot book', function () {
    $loaded = User::find($this->attendee->id);
    $this->attendee->anonymize();
    $this->attendee->save();

    expect(fn () => app(ReserveSeats::class)->handle($this->event, $loaded, 1))
        ->toThrow(AccountDeleted::class);
    expect(Booking::count())->toBe(0)
        ->and($this->event->fresh()->seats_available)->toBe(10);
});
```

`tests/Feature/CreateEventTest.php` (import `App\Actions\CreateEvent\CreateEvent`,
`App\Exceptions\Domain\AccountDeleted`, `App\Models\Event`, `App\Models\User` and
`Carbon\CarbonImmutable` if missing):

```php
test('a user who was deleted during the request cannot create an event', function () {
    $user = User::factory()->create();
    $loaded = User::find($user->id);
    $user->anonymize();
    $user->save();

    expect(fn () => app(CreateEvent::class)->handle($loaded, [
        'title' => 'Laravel Meetup',
        'description' => 'Talks.',
        'venue' => 'Main Hall',
        'starts_at' => CarbonImmutable::now()->addWeek(),
        'capacity' => 10,
    ]))->toThrow(AccountDeleted::class);
    expect(Event::count())->toBe(0);
});
```

`tests/Feature/PublishEventTest.php` (imports as needed):

```php
test('an organizer who was deleted during the request cannot publish', function () {
    $organizer = User::factory()->create();
    $draft = Event::factory()->for($organizer, 'organizer')->create(['starts_at' => now()->addWeek()]);
    $organizer->anonymize();
    $organizer->save();

    expect(fn () => app(PublishEvent::class)->handle($draft))
        ->toThrow(AccountDeleted::class);
    expect($draft->fresh()->isDraft())->toBeTrue();
});
```

- [ ] **Step 2: Run the tests to see them fail**

Run: `docker compose exec app php artisan test --compact tests/Feature/UserModelTest.php tests/Feature/ReserveSeatsTest.php tests/Feature/CreateEventTest.php tests/Feature/PublishEventTest.php --filter="deleted|anonymized user|normal user"`
Expected: FAIL (`AccountDeleted` or `ensureNotAnonymized` missing).

- [ ] **Step 3: Write the exception and the model method**

`app/Exceptions/Domain/AccountDeleted.php`:

```php
<?php

namespace App\Exceptions\Domain;

/**
 * BR-U5: the user deleted the account, so the user cannot act any more.
 */
class AccountDeleted extends DomainException
{
    public function __construct()
    {
        parent::__construct(__('Your account was deleted.'));
    }
}
```

In `app/Models/User.php`, after `isAnonymized()`:

```php
    /**
     * Check that the user did not delete the account (BR-U5).
     *
     * @throws AccountDeleted
     */
    public function ensureNotAnonymized(): void
    {
        if ($this->isAnonymized()) {
            throw new AccountDeleted;
        }
    }
```

- [ ] **Step 4: Lock the user row in the three Actions**

`ReserveSeats::handle()`, first lines of the transaction, before the event lock:

```php
            $attendee = User::query()->lockForUpdate()->findOrFail($attendee->id);
            $attendee->ensureNotAnonymized();
```

`CreateEvent::handle()`: wrap the body in `DB::transaction()`, and start with:

```php
            $organizer = User::query()->lockForUpdate()->findOrFail($organizer->id);
            $organizer->ensureNotAnonymized();
```

`PublishEvent::handle()`: wrap the body in `DB::transaction()`:

```php
        return DB::transaction(function () use ($event): Event {
            User::query()->lockForUpdate()->findOrFail($event->organizer_id)->ensureNotAnonymized();

            $event = Event::query()->lockForUpdate()->findOrFail($event->id);
            $event->publish();
            $event->save();

            return $event;
        });
```

Update each docblock: the Action locks the user row first, so it waits for a running
account delete and then refuses with `AccountDeleted` (BR-U5). Add `@throws
AccountDeleted`. In `ReserveSeats`, the notification `notify()` must use the locked
`$attendee`; it is the same variable.

- [ ] **Step 5: Run the tests to see them pass**

Run: `docker compose exec app php artisan test --compact tests/Feature/UserModelTest.php tests/Feature/ReserveSeatsTest.php tests/Feature/CreateEventTest.php tests/Feature/PublishEventTest.php tests/Feature/BookingsRateLimitTest.php`
Expected: PASS (all tests of these files).

- [ ] **Step 6: Commit**

```bash
docker compose exec app vendor/bin/pint --format agent app/Exceptions/Domain/AccountDeleted.php app/Models/User.php app/Actions/ReserveSeats/ReserveSeats.php app/Actions/CreateEvent/CreateEvent.php app/Actions/PublishEvent/PublishEvent.php tests/Feature/UserModelTest.php tests/Feature/ReserveSeatsTest.php tests/Feature/CreateEventTest.php tests/Feature/PublishEventTest.php
git add app/Exceptions/Domain/AccountDeleted.php app/Models/User.php app/Actions/ReserveSeats/ReserveSeats.php app/Actions/CreateEvent/CreateEvent.php app/Actions/PublishEvent/PublishEvent.php tests/Feature/UserModelTest.php tests/Feature/ReserveSeatsTest.php tests/Feature/CreateEventTest.php tests/Feature/PublishEventTest.php
git commit -m "feat: lock the user row so no booking or publish slips into an account delete"
```

---

### Task 4: READMEs, browser check, handoff and plan

**Files:**

- Create: `app/Actions/DeleteAccount/README.md`
- Modify: `app/Actions/ReserveSeats/README.md`, `app/Actions/CreateEvent/README.md`,
  `app/Actions/PublishEvent/README.md`
- Modify: `HANDOFF.md`
- Add: `docs/superpowers/plans/2026-10-08-m6-delete-account.md`

- [ ] **Step 1: Write the `DeleteAccount` README**

Follow `app/Actions/CancelEvent/README.md`, Simple English, for readers outside the
project. Explain: the route `DELETE /settings/profile` with `auth` and the password
check (`ProfileDeleteRequest`); the transaction and its steps in order; why the user row
is locked first and which Actions also lock it; what stays (cancelled and past events,
bookings, with "Deleted user"); what goes (drafts, passkeys, the reset token, the name,
email and password); the logout and the toast; a blocked delete with the message in the
dialog and as a toast; that BR-U5 on the other login paths comes in M6 PR 3.

One sequence diagram for each outcome, no `alt` blocks:

1. The account is deleted.
2. The delete is blocked (BR-U1 or BR-U2): rollback, redirect back with the error under
   the password field and a toast.
3. The password is wrong: validation error, the Action does not run.

In the READMEs of `ReserveSeats`, `CreateEvent` and `PublishEvent`, add that the Action
locks the user row first and refuses with `AccountDeleted` when the user deleted the
account; add the user lock to their "success" diagram before the event lock.

Render the diagrams of the four READMEs locally:
`npx -y @mermaid-js/mermaid-cli -i <readme> -o <scratch-dir>/<name>.md`
Expected: no errors.

- [ ] **Step 2: Format and run all checks**

Run: `npm run check:fix`
Run: `docker compose exec app composer ci:check`
Expected: Pint, PHPStan, `vp check`, `tsc` and all tests pass.

- [ ] **Step 3: Browser check (owner)**

Ask the owner to check, with exact steps: a blocked delete (a user with an upcoming
booking sees the message under the password field and a toast, and stays logged in); a
successful delete (a new user with no events and no bookings is logged out, lands on
`/events` with the toast "Your account is deleted.", and the new texts in the dialog).
Use a new user for the successful delete; never delete `organizer@example.com` or
`attendee@example.com`.

- [ ] **Step 4: Update `HANDOFF.md`**

"Where we are": PR 1 is merged (#41). The current PR is M6 PR 2
(`feat/delete-account`), with the path of this plan. Write that it is open as a PR and
waits for the merge (no PR number). Replace the PR 1 notes with short "M6 PR 1 notes",
and add this PR's notes from the Global Constraints. Remove "Until PR 2, the Delete
account page still calls `$user->delete()`". "Next steps": the owner reviews this PR.
Then M6 PR 3 (BR-U5 on every login path, the session middleware, and the password reset
test that was moved to PR 3).

- [ ] **Step 5: Commit**

```bash
git add app/Actions/DeleteAccount/README.md app/Actions/ReserveSeats/README.md \
  app/Actions/CreateEvent/README.md app/Actions/PublishEvent/README.md HANDOFF.md \
  docs/superpowers/plans/2026-10-08-m6-delete-account.md
git commit -m "docs: document DeleteAccount and the user lock, update handoff"
```
