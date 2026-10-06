<?php

use App\Models\Event;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

test('BR-E2: the database rejects a capacity of 0', function () {
    Event::factory()->create(['capacity' => 0, 'seats_available' => 0]);
})->throws(QueryException::class, 'events_capacity_check');

test('BR-E2: the database accepts a capacity of 1', function () {
    $event = Event::factory()->create(['capacity' => 1, 'seats_available' => 1]);

    expect($event->fresh()->capacity)->toBe(1);
});

test('BR-E8: the database rejects negative available seats', function () {
    Event::factory()->create(['capacity' => 10, 'seats_available' => -1]);
})->throws(QueryException::class, 'events_seats_available_check');

test('BR-E8: the database rejects more available seats than the capacity', function () {
    Event::factory()->create(['capacity' => 10, 'seats_available' => 11]);
})->throws(QueryException::class, 'events_seats_available_check');

test('BR-E8: the database accepts 0 available seats and a full event', function () {
    $soldOut = Event::factory()->create(['capacity' => 10, 'seats_available' => 0]);
    $empty = Event::factory()->create(['capacity' => 10, 'seats_available' => 10]);

    expect($soldOut->fresh()->seats_available)->toBe(0)
        ->and($empty->fresh()->seats_available)->toBe(10);
});

test('the database rejects an unknown status', function () {
    $event = Event::factory()->create();

    DB::table('events')->where('id', $event->id)->update(['status' => 'archived']);
})->throws(QueryException::class, 'events_status_check');

test('the database does not delete a user who organizes events', function () {
    $event = Event::factory()->create();

    DB::table('users')->where('id', $event->organizer_id)->delete();
})->throws(QueryException::class, 'events_organizer_id_foreign');
