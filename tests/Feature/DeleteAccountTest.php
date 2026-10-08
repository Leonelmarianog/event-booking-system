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
