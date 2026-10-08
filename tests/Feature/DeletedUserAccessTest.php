<?php

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
