<?php

use App\Enums\BookingStatus;
use App\Exceptions\Domain\AlreadyBooked;
use App\Exceptions\Domain\EventNotBookable;
use App\Exceptions\Domain\NotEnoughSeats;
use App\Models\Booking;
use App\Models\Event;
use App\Models\User;

beforeEach(function () {
    $this->organizer = User::factory()->create();
    $this->attendee = User::factory()->create();

    $this->event = Event::factory()->published()->for($this->organizer, 'organizer')
        ->create(['capacity' => 10, 'seats_available' => 10]);
});

test('BR-B7: reserve decreases the available seats and returns a confirmed booking', function () {
    $booking = $this->event->reserve($this->attendee, 3);

    expect($this->event->seats_available)->toBe(7)
        ->and($booking->exists)->toBeFalse()
        ->and($booking->event_id)->toBe($this->event->id)
        ->and($booking->user_id)->toBe($this->attendee->id)
        ->and($booking->quantity)->toBe(3)
        ->and($booking->status)->toBe(BookingStatus::Confirmed);
});

test('BR-B6: a user can book all the available seats', function () {
    $this->event->seats_available = 2;

    $this->event->reserve($this->attendee, 2);

    expect($this->event->seats_available)->toBe(0);
});

test('BR-B6: a user cannot book more than the available seats', function () {
    $this->event->seats_available = 2;

    $this->event->reserve($this->attendee, 3);
})->throws(NotEnoughSeats::class, 'Only 2 seats are left.');

test('BR-B2: a user cannot book a sold-out event', function () {
    $this->event->seats_available = 0;

    $this->event->reserve($this->attendee, 1);
})->throws(NotEnoughSeats::class, 'The event is sold out.');

test('BR-B2: a user cannot book a draft event', function () {
    $draft = Event::factory()->create();

    $draft->reserve($this->attendee, 1);
})->throws(EventNotBookable::class, 'Only a published event can be booked.');

test('BR-B2: a user cannot book a cancelled event', function () {
    $cancelled = Event::factory()->cancelled()->create();

    $cancelled->reserve($this->attendee, 1);
})->throws(EventNotBookable::class, 'Only a published event can be booked.');

test('BR-B2: a user cannot book an event that has started', function () {
    $started = Event::factory()->published()->started()->create();

    $started->reserve($this->attendee, 1);
})->throws(EventNotBookable::class, 'The event has started, so it cannot be booked.');

test('BR-B3: the organizer cannot book their own event', function () {
    $this->event->reserve($this->organizer, 1);
})->throws(EventNotBookable::class, 'You cannot book your own event.');

test('BR-B3: the organizer of a sold-out event gets the own-event error', function () {
    $this->event->seats_available = 0;

    $this->event->reserve($this->organizer, 1);
})->throws(EventNotBookable::class, 'You cannot book your own event.');

test('BR-B4: a user cannot book an event again while the first booking is confirmed', function () {
    Booking::factory()->for($this->event)->for($this->attendee, 'attendee')->create();

    $this->event->reserve($this->attendee, 1);
})->throws(AlreadyBooked::class, 'You already have a booking for this event.');

test('BR-B12: a user can book an event again after a cancelled booking', function () {
    Booking::factory()->cancelled()->for($this->event)->for($this->attendee, 'attendee')->create();

    $booking = $this->event->reserve($this->attendee, 1);

    expect($booking->isConfirmed())->toBeTrue();
});

test('NotEnoughSeats belongs to the quantity field', function () {
    expect((new NotEnoughSeats(2))->field())->toBe('quantity');
});

test('BR-B2: a published event with seats that has not started is bookable', function () {
    expect($this->event->isBookable())->toBeTrue()
        ->and($this->event->isSoldOut())->toBeFalse();
});

test('BR-B2: draft, cancelled, started and sold-out events are not bookable', function () {
    $soldOut = Event::factory()->published()->create(['capacity' => 5, 'seats_available' => 0]);

    expect(Event::factory()->create()->isBookable())->toBeFalse()
        ->and(Event::factory()->cancelled()->create()->isBookable())->toBeFalse()
        ->and(Event::factory()->published()->started()->create()->isBookable())->toBeFalse()
        ->and($soldOut->isBookable())->toBeFalse()
        ->and($soldOut->isSoldOut())->toBeTrue();
});

test('a started event with no seats is not sold out', function () {
    $event = Event::factory()->published()->started()->create(['capacity' => 5, 'seats_available' => 0]);

    expect($event->isSoldOut())->toBeFalse();
});

test('canBeBookedBy gives the same answer as reserve', function () {
    $booked = User::factory()->create();
    Booking::factory()->for($this->event)->for($booked, 'attendee')->create();

    expect($this->event->canBeBookedBy($this->attendee))->toBeTrue()
        ->and($this->event->canBeBookedBy($this->organizer))->toBeFalse()
        ->and($this->event->canBeBookedBy($booked))->toBeFalse();
});

test('confirmedBookingBy gives the confirmed booking of the user, not a cancelled one', function () {
    Booking::factory()->cancelled()->for($this->event)->for($this->attendee, 'attendee')->create();

    expect($this->event->confirmedBookingBy($this->attendee))->toBeNull();

    $confirmed = Booking::factory()->for($this->event)->for($this->attendee, 'attendee')->create();

    expect($this->event->confirmedBookingBy($this->attendee)?->is($confirmed))->toBeTrue();
});
