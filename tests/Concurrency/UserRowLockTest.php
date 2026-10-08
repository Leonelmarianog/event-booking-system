<?php

use App\Actions\CreateEvent\CreateEvent;
use App\Actions\PublishEvent\PublishEvent;
use App\Actions\ReserveSeats\ReserveSeats;
use App\Models\Booking;
use App\Models\Event;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/*
 * These tests use a second database connection as "an account delete that is running".
 * It holds a FOR KEY SHARE lock on the user row. That lock blocks SELECT ... FOR UPDATE,
 * but not the INSERT of an event or a booking that points to the user. So the Actions
 * wait only because of their explicit lock on the user row, which keeps a booking,
 * a new event or a publish from slipping into an account delete (BR-U5).
 */

beforeEach(function () {
    config(['database.connections.other_request' => config('database.connections.pgsql')]);
    $this->otherRequest = DB::connection('other_request');

    // Committed rows, so that the second connection can see them.
    $this->user = User::factory()->create();

    // Give up after 1 second of waiting for a lock, instead of waiting forever.
    DB::statement("SET lock_timeout = '1s'");
});

afterEach(function () {
    if ($this->otherRequest->transactionLevel() > 0) {
        $this->otherRequest->rollBack();
    }

    DB::purge('other_request');
    DB::statement('RESET lock_timeout');

    // The rows are really saved, so delete them by hand.
    DB::statement('TRUNCATE bookings, events, users RESTART IDENTITY CASCADE');
});

/**
 * The other request locks the user row and keeps the lock.
 */
function holdUserRow(User $user): void
{
    test()->otherRequest->beginTransaction();
    test()->otherRequest->select('SELECT id FROM users WHERE id = ? FOR KEY SHARE', [$user->id]);
}

test('ReserveSeats waits for a lock on the user row', function () {
    $event = Event::factory()->published()->create(['capacity' => 1, 'seats_available' => 1]);
    holdUserRow($this->user);

    expect(fn () => app(ReserveSeats::class)->handle($event, $this->user, 1))
        ->toThrow(QueryException::class, 'lock timeout');
    expect(Booking::count())->toBe(0);
});

test('CreateEvent waits for a lock on the user row', function () {
    holdUserRow($this->user);

    expect(fn () => app(CreateEvent::class)->handle($this->user, [
        'title' => 'Laravel Meetup',
        'description' => 'Talks.',
        'venue' => 'Main Hall',
        'starts_at' => CarbonImmutable::now()->addWeek(),
        'capacity' => 10,
    ]))->toThrow(QueryException::class, 'lock timeout');
    expect(Event::count())->toBe(0);
});

test('PublishEvent waits for a lock on the organizer row', function () {
    $draft = Event::factory()->for($this->user, 'organizer')->create(['starts_at' => now()->addWeek()]);
    holdUserRow($this->user);

    expect(fn () => app(PublishEvent::class)->handle($draft))
        ->toThrow(QueryException::class, 'lock timeout');
    expect($draft->fresh()->isDraft())->toBeTrue();
});
