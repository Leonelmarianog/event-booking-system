<?php

use App\Enums\BookingStatus;
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
