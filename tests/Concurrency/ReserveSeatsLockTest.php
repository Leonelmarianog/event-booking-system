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
    config(['database.connections.other_request' => config('database.connections.pgsql')]);
    $this->otherRequest = DB::connection('other_request');

    $this->attendee = User::factory()->create();
    $this->event = Event::factory()->published()->create(['capacity' => 1, 'seats_available' => 1]);

    DB::statement("SET lock_timeout = '1s'");
});

afterEach(function () {
    if ($this->otherRequest->transactionLevel() > 0) {
        $this->otherRequest->rollBack();
    }

    DB::purge('other_request');
    DB::statement('RESET lock_timeout');
    DB::statement('TRUNCATE bookings, events, users RESTART IDENTITY CASCADE');
});

test('BR-B14: ReserveSeats waits for the lock of another request on the event row', function () {
    $this->otherRequest->beginTransaction();
    $this->otherRequest->select('SELECT id FROM events WHERE id = ? FOR KEY SHARE', [$this->event->id]);

    expect(fn () => app(ReserveSeats::class)->handle($this->event, $this->attendee, 1))
        ->toThrow(QueryException::class, 'lock timeout');

    expect(Booking::count())->toBe(0)
        ->and($this->event->fresh()->seats_available)->toBe(1);
});

test('BR-B14: ReserveSeats books the seat after the other request ends', function () {
    $this->otherRequest->beginTransaction();
    $this->otherRequest->select('SELECT id FROM events WHERE id = ? FOR KEY SHARE', [$this->event->id]);
    $this->otherRequest->rollBack();

    $booking = app(ReserveSeats::class)->handle($this->event, $this->attendee, 1);

    expect($booking->isConfirmed())->toBeTrue()
        ->and($this->event->fresh()->seats_available)->toBe(0);
});
