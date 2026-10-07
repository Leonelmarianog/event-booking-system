<?php

use App\Models\Booking;
use App\Models\Event;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

test('BR-B5: the database rejects a quantity of 0', function () {
    Booking::factory()->create(['quantity' => 0]);
})->throws(QueryException::class, 'bookings_quantity_check');

test('BR-B5: the database rejects a quantity of 5', function () {
    Booking::factory()->create(['quantity' => 5]);
})->throws(QueryException::class, 'bookings_quantity_check');

test('BR-B5: the database accepts a quantity of 1 and of 4', function () {
    $one = Booking::factory()->create(['quantity' => 1]);
    $four = Booking::factory()->create(['quantity' => 4]);

    expect($one->fresh()->quantity)->toBe(1)
        ->and($four->fresh()->quantity)->toBe(4);
});

test('the database rejects an unknown booking status', function () {
    $booking = Booking::factory()->create();

    DB::table('bookings')->where('id', $booking->id)->update(['status' => 'pending_payment']);
})->throws(QueryException::class, 'bookings_status_check');

test('BR-B4: the database rejects a second confirmed booking of the same user for the same event', function () {
    $booking = Booking::factory()->create();

    Booking::factory()->for($booking->event)->for($booking->attendee, 'attendee')->create();
})->throws(QueryException::class, 'bookings_event_id_user_id_confirmed_unique');

test('BR-B12: the database accepts a new confirmed booking after a cancelled one', function () {
    $cancelled = Booking::factory()->cancelled()->create();

    $confirmed = Booking::factory()->for($cancelled->event)->for($cancelled->attendee, 'attendee')->create();

    expect($confirmed->fresh()->isConfirmed())->toBeTrue();
});

test('BR-B4: two users can each have a confirmed booking for the same event', function () {
    $event = Event::factory()->published()->create();

    Booking::factory()->for($event)->create();
    Booking::factory()->for($event)->create();

    expect($event->bookings()->count())->toBe(2);
});

test('BR-B8: the database rejects a duplicate reference', function () {
    $booking = Booking::factory()->create();

    DB::table('bookings')->insert([
        'reference' => $booking->reference,
        'event_id' => $booking->event_id,
        'user_id' => User::factory()->create()->id,
        'quantity' => 1,
        'status' => 'confirmed',
    ]);
})->throws(QueryException::class, 'bookings_reference_unique');

test('the database does not delete an event that has bookings', function () {
    $booking = Booking::factory()->create();

    DB::table('events')->where('id', $booking->event_id)->delete();
})->throws(QueryException::class, 'bookings_event_id_foreign');

test('BR-U4: the database does not delete a user who has bookings', function () {
    $booking = Booking::factory()->create();

    DB::table('users')->where('id', $booking->user_id)->delete();
})->throws(QueryException::class, 'bookings_user_id_foreign');
