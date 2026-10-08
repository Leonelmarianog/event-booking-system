<?php

use App\Actions\ReserveSeats\ReserveSeats;
use App\Models\Booking;
use App\Models\Event;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/*
 * These tests use a second database connection as "another booking request". It holds
 * a FOR KEY SHARE lock on the event row. That lock blocks SELECT ... FOR UPDATE, but
 * not a plain UPDATE of the event or the INSERT of a booking. So ReserveSeats waits
 * only because of its explicit row lock (BR-B14).
 */

beforeEach(function () {
    // A second connection that plays "another booking request".
    config(['database.connections.other_request' => config('database.connections.pgsql')]);
    $this->otherRequest = DB::connection('other_request');

    // Committed rows, so that the second connection can see them.
    $this->attendee = User::factory()->create();
    $this->event = Event::factory()->published()->create(['capacity' => 1, 'seats_available' => 1]);

    // Give up after 1 second of waiting for a lock, instead of waiting forever.
    DB::statement("SET lock_timeout = '1s'");
});

afterEach(function () {
    // End the other request, which removes its lock.
    if ($this->otherRequest->transactionLevel() > 0) {
        $this->otherRequest->rollBack();
    }

    // Close the second connection and undo the timeout.
    DB::purge('other_request');
    DB::statement('RESET lock_timeout');

    // The rows are really saved, so delete them by hand.
    DB::statement('TRUNCATE bookings, events, users RESTART IDENTITY CASCADE');
});

test('BR-B14: ReserveSeats waits for the lock of another request on the event row', function () {
    // The other request puts the weak lock on the event row and keeps it.
    $this->otherRequest->beginTransaction();
    $this->otherRequest->select('SELECT id FROM events WHERE id = ? FOR KEY SHARE', [$this->event->id]);

    // ReserveSeats must wait for it (its FOR UPDATE), so it stops with a lock timeout.
    expect(fn () => app(ReserveSeats::class)->handle($this->event, $this->attendee, 1))
        ->toThrow(QueryException::class, 'lock timeout');

    // Nothing was booked.
    expect(Booking::count())->toBe(0)
        ->and($this->event->fresh()->seats_available)->toBe(1);
});

test('BR-B14: ReserveSeats books the seat after the other request ends', function () {
    // The other request takes the same lock, then ends and removes it.
    $this->otherRequest->beginTransaction();
    $this->otherRequest->select('SELECT id FROM events WHERE id = ? FOR KEY SHARE', [$this->event->id]);
    $this->otherRequest->rollBack();

    // With no lock in the way, ReserveSeats books the seat normally.
    $booking = app(ReserveSeats::class)->handle($this->event, $this->attendee, 1);

    expect($booking->isConfirmed())->toBeTrue()
        ->and($this->event->fresh()->seats_available)->toBe(0);
});
