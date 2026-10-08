<?php

use App\Enums\BookingStatus;
use App\Exceptions\Domain\EventHasStarted;
use App\Exceptions\Domain\InvalidStateTransition;
use App\Models\Booking;
use App\Models\Event;
use App\Models\User;

test('the factory makes a confirmed booking of one seat for a published event', function () {
    $booking = Booking::factory()->create()->fresh();

    expect($booking->status)->toBe(BookingStatus::Confirmed)
        ->and($booking->quantity)->toBe(1)
        ->and($booking->cancelled_at)->toBeNull()
        ->and($booking->event->isPublished())->toBeTrue()
        ->and($booking->isConfirmed())->toBeTrue()
        ->and($booking->isCancelled())->toBeFalse();
});

test('the cancelled factory state makes a cancelled booking', function () {
    $booking = Booking::factory()->cancelled()->create()->fresh();

    expect($booking->status)->toBe(BookingStatus::Cancelled)
        ->and($booking->cancelled_at)->not->toBeNull()
        ->and($booking->isCancelled())->toBeTrue()
        ->and($booking->isConfirmed())->toBeFalse();
});

test('BR-B8: a new booking gets a ULID reference', function () {
    $booking = Booking::factory()->create();

    expect($booking->reference)->toMatch('/^[0-9a-hjkmnp-tv-z]{26}$/')
        ->and($booking->id)->toBeInt();
});

test('BR-B8: each booking gets a different reference', function () {
    $first = Booking::factory()->create();
    $second = Booking::factory()->create();

    expect($first->reference)->not->toBe($second->reference);
});

test('a booking belongs to an event and to an attendee', function () {
    $event = Event::factory()->published()->create();
    $user = User::factory()->create();

    $booking = Booking::factory()->for($event)->for($user, 'attendee')->create();

    expect($booking->event->is($event))->toBeTrue()
        ->and($booking->attendee->is($user))->toBeTrue()
        ->and($booking->isMadeBy($user))->toBeTrue()
        ->and($booking->isMadeBy(User::factory()->create()))->toBeFalse()
        ->and($event->bookings->pluck('id')->all())->toBe([$booking->id])
        ->and($user->bookings->pluck('id')->all())->toBe([$booking->id]);
});

test('BR-E16: an event knows which users have a booking for it, also a cancelled one', function () {
    $event = Event::factory()->cancelled()->create();
    $confirmed = Booking::factory()->for($event)->create();
    $cancelled = Booking::factory()->cancelled()->for($event)->create();

    expect($event->hasBookingBy($confirmed->attendee))->toBeTrue()
        ->and($event->hasBookingBy($cancelled->attendee))->toBeTrue()
        ->and($event->hasBookingBy(User::factory()->create()))->toBeFalse();
});

test('BR-B10: the booking status follows the state diagram', function (BookingStatus $from, BookingStatus $to, bool $allowed) {
    expect($from->canTransitionTo($to))->toBe($allowed);
})->with([
    'confirmed to cancelled' => [BookingStatus::Confirmed, BookingStatus::Cancelled, true],
    'confirmed to confirmed' => [BookingStatus::Confirmed, BookingStatus::Confirmed, false],
    'cancelled to confirmed' => [BookingStatus::Cancelled, BookingStatus::Confirmed, false],
    'cancelled to cancelled' => [BookingStatus::Cancelled, BookingStatus::Cancelled, false],
]);

test('BR-B10, BR-B11: cancelling a confirmed booking releases its seats', function () {
    $this->freezeSecond();
    $event = Event::factory()->published()->create(['capacity' => 10, 'seats_available' => 6]);
    $booking = Booking::factory()->for($event)->create(['quantity' => 3]);

    $booking->cancel();

    expect($booking->isCancelled())->toBeTrue()
        ->and($booking->cancelled_at->equalTo(now()))->toBeTrue()
        ->and($booking->event->seats_available)->toBe(9);
});

test('BR-B10: a cancelled booking cannot be cancelled again', function () {
    $event = Event::factory()->published()->create(['capacity' => 10, 'seats_available' => 6]);
    $booking = Booking::factory()->cancelled()->for($event)->create(['quantity' => 3]);

    expect(fn () => $booking->cancel())
        ->toThrow(InvalidStateTransition::class, 'Only a confirmed booking can be cancelled. This booking is cancelled.');

    expect($booking->event->seats_available)->toBe(6);
});

test('BR-B10: a booking of a started event cannot be cancelled', function () {
    $event = Event::factory()->published()->started()->create(['capacity' => 10, 'seats_available' => 6]);
    $booking = Booking::factory()->for($event)->create(['quantity' => 3]);

    expect(fn () => $booking->cancel())
        ->toThrow(EventHasStarted::class, 'The event has started, so the booking cannot be cancelled.');

    expect($booking->isConfirmed())->toBeTrue()
        ->and($booking->event->seats_available)->toBe(6);
});

test('BR-B10: only a confirmed booking of an event that has not started can be cancelled', function () {
    $upcoming = Event::factory()->published()->create();
    $started = Event::factory()->published()->started()->create();

    expect(Booking::factory()->for($upcoming)->create()->canBeCancelled())->toBeTrue()
        ->and(Booking::factory()->cancelled()->for($upcoming)->create()->canBeCancelled())->toBeFalse()
        ->and(Booking::factory()->for($started)->create()->canBeCancelled())->toBeFalse();
});
