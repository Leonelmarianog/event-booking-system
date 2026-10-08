<?php

use App\Actions\DeleteAccount\DeleteAccount;
use App\Models\Booking;
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

test('BR-U5: a deleted user cannot log in with the old email and password', function () {
    $user = User::factory()->create(['email' => 'ada@example.com']);
    $user->anonymize();
    $user->save();

    $this->post(route('login.store'), ['email' => 'ada@example.com', 'password' => 'password'])
        ->assertSessionHasErrors('email');

    $this->assertGuest();
});

test('BR-U5: nobody can log in with the placeholder email', function () {
    $user = User::factory()->create();
    $user->anonymize();
    $user->save();

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

    app(DeleteAccount::class)->handle($user);

    expect($user->fresh()->remember_token)->toBeNull()
        ->and($user->passkeys()->count())->toBe(0);
});
