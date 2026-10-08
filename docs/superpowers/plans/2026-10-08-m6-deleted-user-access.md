# M6 — Deleted User Access: a Deleted User Cannot Get Back In

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A deleted (anonymized) user cannot log in, cannot keep using a session that is
still open on another device, and gets no more email (BR-U5). This is the last PR of M6:
with it, every rule BR-U1 to BR-U5 has a test.

**Architecture:** `User::routeNotificationForMail()` returns null for an anonymized
user, so the mail channel sends nothing, also for queued notifications and for a
password reset link to the placeholder email. A middleware `LogOutDeletedUser` in the
web group ends the session of an anonymized user and redirects to the login page with
no message. Password login and "remember me" are already closed by PR 1 and PR 2 (null
password, null remember token); this PR pins them with tests.

**Tech Stack:** Laravel 13, Fortify, Pest.

**Spec:** `docs/superpowers/specs/2026-09-28-event-booking-design.md` (sections 4 and
6), `docs/design/business-rules.md` (BR-U5), `docs/superpowers/plans/milestones.md`
(M6).

## Global Constraints

- Owner decisions:
    - A request from a session of a deleted user ends that session and redirects to the
      login page with **no message** (log out silently).
    - The owner wants email verification at some point. Keep the starter kit's
      `verified` middleware as it is (also on `DELETE /settings/profile`). Record the
      wish in `HANDOFF.md`; this PR does not change verification.
- `User::routeNotificationForMail(Notification $notification): ?string` returns `null`
  when `isAnonymized()`, else `$this->email`. The mail channel then skips the email
  (`MailChannel::send()` returns early when the route is empty).
- `App\Http\Middleware\LogOutDeletedUser`: when `$request->user()?->isAnonymized()`,
  call `Auth::guard('web')->logoutCurrentDevice()`, invalidate the session, regenerate
  the token, and return `to_route('login')`. Otherwise pass on. Register it in `bootstrap/app.php` with
  `$middleware->web(append: [...])`, before `HandleAppearance`.
- Passkey login: `DeleteAccount` deletes the passkeys, so no passkey can sign in. A real
  WebAuthn sign-in cannot be faked in a test; the middleware closes this path anyway,
  because every request after a login passes through it.
- Tests check "no email" with `Event::fake([MessageSending::class])` and the real
  `sync` queue and `array` mailer: `MessageSending` is dispatched only when the mailer
  really sends.
- Run commands inside the `app` container: `docker compose exec app <command>`. Pint
  `--dirty` does not work in the container (no git); pass the file paths. `vp check
--fix` also formats the code blocks of this plan.

## Review Focus

1. **A session that was open before the delete** (another device). The next request
   logs it out. Pinned by a test in Task 2.
2. **Inertia and JSON requests** from such a session. Also logged out. Pinned by a test
   in Task 2 (Inertia header).
3. **Guests and normal users** are not affected by the middleware. Pinned by tests in
   Task 2.
4. **A password reset** for the old email or the placeholder email sends no email and
   logs nobody in. Pinned by tests in Task 1 and Task 3.
5. **A queued email** for a user who was deleted before the worker ran. Not sent. Pinned
   by a test in Task 1.

---

### Task 1: No email to a deleted user

**Files:**

- Modify: `app/Models/User.php`
- Test: `tests/Feature/DeletedUserAccessTest.php` (with
  `php artisan make:test --pest DeletedUserAccessTest --no-interaction`)

**Interfaces:**

- Produces: `User::routeNotificationForMail(Notification $notification): ?string`.

- [ ] **Step 1: Write the failing tests**

```php
<?php

use App\Models\Booking;
use App\Models\Event;
use App\Models\User;
use App\Notifications\BookingCancelled;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Support\Facades\Event as EventFacade;

test('BR-U5: a deleted user gets no email, also from a queued notification', function () {
    EventFacade::fake([MessageSending::class]);
    $user = User::factory()->create();
    $booking = Booking::factory()->cancelled()->for($user, 'attendee')->create();
    $user->anonymize();
    $user->save();

    $user->notify(new BookingCancelled($booking));

    EventFacade::assertNotDispatched(MessageSending::class);
});

test('a normal user still gets the email', function () {
    EventFacade::fake([MessageSending::class]);
    $user = User::factory()->create();
    $booking = Booking::factory()->cancelled()->for($user, 'attendee')->create();

    $user->notify(new BookingCancelled($booking));

    EventFacade::assertDispatched(MessageSending::class);
});

test('BR-U5: a password reset for the placeholder email sends no email', function () {
    EventFacade::fake([MessageSending::class]);
    $user = User::factory()->anonymized()->create();

    $this->post(route('password.email'), ['email' => $user->email]);

    EventFacade::assertNotDispatched(MessageSending::class);
    $this->assertGuest();
});

test('BR-U5: a password reset for the old email finds no user and sends no email', function () {
    EventFacade::fake([MessageSending::class]);
    $user = User::factory()->create(['email' => 'ada@example.com']);
    $user->anonymize();
    $user->save();

    $this->post(route('password.email'), ['email' => 'ada@example.com']);

    EventFacade::assertNotDispatched(MessageSending::class);
    $this->assertGuest();
});
```

The `Booking` factory creates its own published event. If the `cancelled()` booking
state needs an event, it gets the factory default.

- [ ] **Step 2: Run the tests to see them fail**

Run: `docker compose exec app php artisan test --compact tests/Feature/DeletedUserAccessTest.php`
Expected: the first test and the placeholder-reset test FAIL (`MessageSending` was
dispatched). The other two pass already; they guard the change.

- [ ] **Step 3: Add the mail route to `User`**

After `ensureNotAnonymized()` (import `Illuminate\Notifications\Notification`):

```php
    /**
     * The email address for notifications. A deleted user gets no email (BR-U5), also
     * from a notification that was queued before the delete.
     */
    public function routeNotificationForMail(Notification $notification): ?string
    {
        return $this->isAnonymized() ? null : $this->email;
    }
```

- [ ] **Step 4: Run the tests to see them pass**

Run: `docker compose exec app php artisan test --compact tests/Feature/DeletedUserAccessTest.php tests/Feature/Auth`
Expected: PASS (the starter-kit auth tests still pass: normal users get their emails).

- [ ] **Step 5: Commit**

```bash
docker compose exec app vendor/bin/pint --format agent app/Models/User.php tests/Feature/DeletedUserAccessTest.php
git add app/Models/User.php tests/Feature/DeletedUserAccessTest.php
git commit -m "feat: send no email to a deleted user"
```

---

### Task 2: End the sessions of a deleted user

**Files:**

- Create: `app/Http/Middleware/LogOutDeletedUser.php` (with
  `php artisan make:middleware LogOutDeletedUser --no-interaction`)
- Modify: `bootstrap/app.php`
- Test: `tests/Feature/DeletedUserAccessTest.php`

**Interfaces:**

- Consumes: `User::isAnonymized()`.

- [ ] **Step 1: Write the failing tests**

Add to `tests/Feature/DeletedUserAccessTest.php`:

```php
test('BR-U5: a session that was open before the delete is logged out', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    $user->anonymize();
    $user->save();

    $this->get(route('bookings.index'))
        ->assertRedirect(route('login'))
        ->assertInertiaFlashMissing('toast');

    $this->assertGuest();
});

test('BR-U5: an Inertia request from such a session is logged out too', function () {
    $user = User::factory()->anonymized()->create();

    $this->actingAs($user)
        ->get(route('events.index'), ['X-Inertia' => 'true'])
        ->assertRedirect(route('login'));

    $this->assertGuest();
});

test('a public page still works for guests', function () {
    $this->get(route('events.index'))->assertOk();
});

test('a normal user stays logged in', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('bookings.index'))->assertOk();

    $this->assertAuthenticatedAs($user);
});
```

`Inertia::flash()` writes to the session key `Inertia\Support\SessionKey::FLASH_DATA`,
not `toast`. Use the Inertia test macro for a missing flash if one exists
(`assertInertiaFlashMissing('toast')`, see
`vendor/inertiajs/inertia-laravel/src/Testing/TestResponseMacros.php`); otherwise use
`->assertSessionMissing(SessionKey::FLASH_DATA)`.

- [ ] **Step 2: Run the tests to see them fail**

Run: `docker compose exec app php artisan test --compact tests/Feature/DeletedUserAccessTest.php --filter="logged out"`
Expected: FAIL (the anonymized user gets the page).

- [ ] **Step 3: Write and register the middleware**

`app/Http/Middleware/LogOutDeletedUser.php`:

```php
<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class LogOutDeletedUser
{
    /**
     * End the session of a user who deleted the account, for example a session that is
     * still open on another device (BR-U5). The user goes to the login page with no
     * message.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->isAnonymized()) {
            Auth::guard('web')->logoutCurrentDevice();

            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return to_route('login');
        }

        return $next($request);
    }
}
```

In `bootstrap/app.php`, add `LogOutDeletedUser::class` as the first entry of
`$middleware->web(append: [...])` and import it.

`logoutCurrentDevice()` writes no new remember token, so the anonymized row keeps none.

- [ ] **Step 4: Run the tests to see them pass**

Run: `docker compose exec app php artisan test --compact tests/Feature/DeletedUserAccessTest.php tests/Feature/DashboardTest.php tests/Feature/Auth`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
docker compose exec app vendor/bin/pint --format agent app/Http/Middleware/LogOutDeletedUser.php bootstrap/app.php tests/Feature/DeletedUserAccessTest.php
git add app/Http/Middleware/LogOutDeletedUser.php bootstrap/app.php tests/Feature/DeletedUserAccessTest.php
git commit -m "feat: log out the open sessions of a deleted user"
```

---

### Task 3: Pin the closed login paths

**Files:**

- Test: `tests/Feature/DeletedUserAccessTest.php`

- [ ] **Step 1: Write the tests**

```php
test('BR-U5: a deleted user cannot log in with the old email and password', function () {
    $user = User::factory()->create(['email' => 'ada@example.com']);
    $user->anonymize();
    $user->save();

    $this->post(route('login.store'), ['email' => 'ada@example.com', 'password' => 'password'])
        ->assertSessionHasErrors('email');

    $this->assertGuest();
});

test('BR-U5: nobody can log in with the placeholder email', function () {
    $user = User::factory()->anonymized()->create();

    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])
        ->assertSessionHasErrors('email');

    $this->assertGuest();
});

test('BR-U5: a deleted user has no remember token and no passkeys', function () {
    $user = User::factory()->create();
    $user->passkeys()->create([
        'name' => 'Laptop',
        'credential_id' => 'credential-'.$user->id,
        'credential' => ['id' => 'credential-'.$user->id],
    ]);

    app(\App\Actions\DeleteAccount\DeleteAccount::class)->handle($user);

    expect($user->fresh()->remember_token)->toBeNull()
        ->and($user->passkeys()->count())->toBe(0);
});
```

Import `App\Actions\DeleteAccount\DeleteAccount` at the top instead of the full class
name. The login route may be named `login.store` or `login`; check
`php artisan route:list --name=login` and use the POST route.

These tests pin behavior that PR 1 and PR 2 already give (null password, null remember
token, deleted passkeys). They pass at once. Check that each can fail: give the user a
password again in a scratch run (`$user->forceFill(['password' => 'password'])->save()`
after `anonymize()`) and see the first test fail; revert.

- [ ] **Step 2: Run the tests**

Run: `docker compose exec app php artisan test --compact tests/Feature/DeletedUserAccessTest.php`
Expected: PASS.

- [ ] **Step 3: Commit**

```bash
docker compose exec app vendor/bin/pint --format agent tests/Feature/DeletedUserAccessTest.php
git add tests/Feature/DeletedUserAccessTest.php
git commit -m "test: pin the closed login paths of a deleted user"
```

---

### Task 4: Docs, M6 rule check, browser check, handoff and plan

**Files:**

- Modify: `app/Actions/DeleteAccount/README.md`
- Modify: `docs/design/business-rules.md` (BR-U5 "Enforced by")
- Modify: `HANDOFF.md`
- Add: `docs/superpowers/plans/2026-10-08-m6-deleted-user-access.md`

- [ ] **Step 1: Update the docs**

In `app/Actions/DeleteAccount/README.md`, replace "The other ways to log in (...) are
closed in the next PR of M6 (BR-U5)." with a short section "After the delete" in Simple
English: the null password and remember token close password login and "remember me";
the deleted passkeys close passkey login; the middleware `LogOutDeletedUser` ends a
session that is still open on another device, with no message; a deleted user gets no
email (`routeNotificationForMail()`), also from a queued notification; a password reset
finds no user for the old email and sends nothing to the placeholder email.

In `docs/design/business-rules.md`, change the "Enforced by" of BR-U5 from `Model` to
`Model, Middleware` (keep the table aligned; `vp check --fix` formats it).

- [ ] **Step 2: Check that every M6 rule has a test**

Run: `grep -rhoE "BR-U[0-9]+\b" tests | sort -u`
Expected: BR-U1, BR-U2, BR-U3, BR-U4 and BR-U5.

- [ ] **Step 3: Format and run all checks**

Run: `npm run check:fix`
Run: `docker compose exec app composer ci:check`
Expected: all pass.

- [ ] **Step 4: Browser check (owner)**

Give the owner exact steps: two browsers (or a normal and a private window) logged in
as the same new, verified user; delete the account in one; reload a page in the other
and land on the login page with no message. Remind the owner to verify the new user's
email via Mailpit first (the `verified` middleware is still on the delete route).

- [ ] **Step 5: Update `HANDOFF.md`**

"Where we are": PRs 1 and 2 are merged (#41, #42). The current PR is M6 PR 3
(`feat/deleted-user-access`), with the path of this plan; open as a PR, waits for the
merge (no PR number). With this PR, M6 is complete: every rule BR-U1 to BR-U5 has a
test. Shorten the PR 2 notes to "M6 PR 2 notes" and add this PR's notes. Replace the
"Email verification" next step with the owner's wish: the owner wants email
verification at some point; the `verified` middleware stays (a user must verify the
email before deleting the account); the v1 scope text that says verification is off must
change when that work is planned. "Next steps": the owner reviews this PR. Then M6 is
complete; split M7 (Demo ready) into PRs with the owner.

- [ ] **Step 6: Commit**

```bash
git add app/Actions/DeleteAccount/README.md docs/design/business-rules.md HANDOFF.md \
  docs/superpowers/plans/2026-10-08-m6-deleted-user-access.md
git commit -m "docs: document how a deleted user stays out and update handoff"
```
