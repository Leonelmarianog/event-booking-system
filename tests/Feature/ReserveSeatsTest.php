<?php

use App\Actions\ReserveSeats\ReserveSeats;
use App\Exceptions\Domain\AccountDeleted;
use App\Models\Booking;
use App\Models\Event;
use App\Models\User;
use App\Notifications\BookingConfirmed;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event as EventFacade;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    $this->organizer = User::factory()->create();
    $this->attendee = User::factory()->create();

    $this->event = Event::factory()->published()->for($this->organizer, 'organizer')
        ->create(['capacity' => 10, 'seats_available' => 10]);
});

test('BR-B7: a user books seats and the booking is confirmed', function () {
    $response = $this->actingAs($this->attendee)
        ->post(route('events.bookings.store', $this->event), ['quantity' => 2]);

    $booking = Booking::sole();

    $response->assertRedirect(route('events.show', $this->event))
        ->assertInertiaFlash('toast', [
            'type' => 'success',
            'message' => "You booked 2 seats. Reference: {$booking->reference}.",
        ]);

    expect($booking->isConfirmed())->toBeTrue()
        ->and($booking->quantity)->toBe(2)
        ->and($booking->isMadeBy($this->attendee))->toBeTrue()
        ->and($booking->event_id)->toBe($this->event->id)
        ->and($this->event->fresh()->seats_available)->toBe(8);
});

test('BR-B7: the toast says "seat" for one seat', function () {
    $this->actingAs($this->attendee)
        ->post(route('events.bookings.store', $this->event), ['quantity' => 1])
        ->assertInertiaFlash('toast.message', 'You booked 1 seat. Reference: '.Booking::sole()->reference.'.');
});

test('BR-B8: each booking gets its own reference', function () {
    $otherAttendee = User::factory()->create();

    $this->actingAs($this->attendee)->post(route('events.bookings.store', $this->event), ['quantity' => 1]);
    $this->actingAs($otherAttendee)->post(route('events.bookings.store', $this->event), ['quantity' => 1]);

    expect(Booking::pluck('reference')->unique())->toHaveCount(2);
});

test('BR-B1: a visitor is sent to the login page', function () {
    $this->post(route('events.bookings.store', $this->event), ['quantity' => 1])
        ->assertRedirect(route('login'));

    expect(Booking::count())->toBe(0);
});

test('BR-B2: another user gets a 404 for a draft event', function () {
    $draft = Event::factory()->for($this->organizer, 'organizer')->create();

    $this->actingAs($this->attendee)
        ->post(route('events.bookings.store', $draft), ['quantity' => 1])
        ->assertNotFound();
});

test('BR-B2: a user who can see a cancelled event cannot book it', function () {
    $cancelled = Event::factory()->cancelled()->create();
    Booking::factory()->cancelled()->for($cancelled)->for($this->attendee, 'attendee')->create();

    $this->actingAs($this->attendee)
        ->from(route('events.show', $cancelled))
        ->post(route('events.bookings.store', $cancelled), ['quantity' => 1])
        ->assertRedirect(route('events.show', $cancelled))
        ->assertInertiaFlash('toast', ['type' => 'error', 'message' => 'Only a published event can be booked.']);

    expect(Booking::count())->toBe(1);
});

test('BR-B2: a user cannot book an event that has started', function () {
    $started = Event::factory()->published()->started()->create();

    $this->actingAs($this->attendee)
        ->post(route('events.bookings.store', $started), ['quantity' => 1])
        ->assertInertiaFlash('toast.message', 'The event has started, so it cannot be booked.');

    expect(Booking::count())->toBe(0);
});

test('BR-B3: the organizer cannot book their own event', function () {
    $this->actingAs($this->organizer)
        ->post(route('events.bookings.store', $this->event), ['quantity' => 1])
        ->assertInertiaFlash('toast.message', 'You cannot book your own event.');

    expect(Booking::count())->toBe(0)
        ->and($this->event->fresh()->seats_available)->toBe(10);
});

test('BR-B4: a user cannot book the same event twice', function () {
    $this->actingAs($this->attendee)->post(route('events.bookings.store', $this->event), ['quantity' => 1]);

    $this->actingAs($this->attendee)
        ->post(route('events.bookings.store', $this->event), ['quantity' => 1])
        ->assertInertiaFlash('toast.message', 'You already have a booking for this event.');

    expect(Booking::count())->toBe(1)
        ->and($this->event->fresh()->seats_available)->toBe(9);
});

test('BR-B12: a user can book again after a cancelled booking', function () {
    Booking::factory()->cancelled()->for($this->event)->for($this->attendee, 'attendee')->create();

    $this->actingAs($this->attendee)
        ->post(route('events.bookings.store', $this->event), ['quantity' => 1])
        ->assertRedirect(route('events.show', $this->event));

    expect($this->event->confirmedBookingBy($this->attendee))->not->toBeNull();
});

test('BR-B6: a user cannot book more than the available seats', function () {
    $this->event->forceFill(['seats_available' => 2])->save();

    $this->actingAs($this->attendee)
        ->from(route('events.show', $this->event))
        ->post(route('events.bookings.store', $this->event), ['quantity' => 3])
        ->assertRedirect(route('events.show', $this->event))
        ->assertSessionHasErrors(['quantity' => 'Only 2 seats are left.']);

    expect(Booking::count())->toBe(0)
        ->and($this->event->fresh()->seats_available)->toBe(2);
});

test('BR-B5: the quantity must be from 1 to 4', function (mixed $quantity) {
    $this->actingAs($this->attendee)
        ->post(route('events.bookings.store', $this->event), ['quantity' => $quantity])
        ->assertSessionHasErrors('quantity');

    expect(Booking::count())->toBe(0);
})->with([0, 5, 'two', null]);

test('BR-B5: a user can book 4 seats', function () {
    $this->actingAs($this->attendee)
        ->post(route('events.bookings.store', $this->event), ['quantity' => 4])
        ->assertSessionHasNoErrors();

    expect(Booking::sole()->quantity)->toBe(4);
});

test('BR-N1: the attendee gets a booking confirmed email', function () {
    Notification::fake();

    $this->actingAs($this->attendee)
        ->post(route('events.bookings.store', $this->event), ['quantity' => 2]);

    $booking = Booking::sole();

    Notification::assertSentTo(
        $this->attendee,
        BookingConfirmed::class,
        fn (BookingConfirmed $notification) => $notification->booking->is($booking),
    );
    Notification::assertNotSentTo($this->organizer, BookingConfirmed::class);
    Notification::assertCount(1);
});

test('BR-N1: no email when the booking fails a rule', function () {
    Notification::fake();

    $this->actingAs($this->attendee)
        ->post(route('events.bookings.store', $this->event), ['quantity' => 2]);
    $this->actingAs($this->attendee)
        ->post(route('events.bookings.store', $this->event), ['quantity' => 1])
        ->assertInertiaFlash('toast.message', 'You already have a booking for this event.');

    Notification::assertSentTimes(BookingConfirmed::class, 1);
});

test('BR-N6: the email is sent only after the transaction commits', function () {
    EventFacade::fake([NotificationSent::class]);

    DB::transaction(function () {
        app(ReserveSeats::class)->handle($this->event, $this->attendee, 1);

        EventFacade::assertNotDispatched(NotificationSent::class);
    });

    EventFacade::assertDispatchedTimes(NotificationSent::class, 1);
    EventFacade::assertDispatched(
        NotificationSent::class,
        fn (NotificationSent $sent) => $sent->notification instanceof BookingConfirmed
            && $sent->notifiable->is($this->attendee),
    );
});

test('BR-N6: no email when the transaction rolls back', function () {
    EventFacade::fake([NotificationSent::class]);

    try {
        DB::transaction(function () {
            app(ReserveSeats::class)->handle($this->event, $this->attendee, 1);

            throw new LogicException('A later step fails.');
        });
    } catch (LogicException $exception) {
        expect($exception->getMessage())->toBe('A later step fails.');
    }

    EventFacade::assertNotDispatched(NotificationSent::class);
    expect(Booking::count())->toBe(0);
});

test('a user who was deleted during the request cannot book', function () {
    $loaded = User::find($this->attendee->id);
    $this->attendee->anonymize();
    $this->attendee->save();

    expect(fn () => app(ReserveSeats::class)->handle($this->event, $loaded, 1))
        ->toThrow(AccountDeleted::class);
    expect(Booking::count())->toBe(0)
        ->and($this->event->fresh()->seats_available)->toBe(10);
});
