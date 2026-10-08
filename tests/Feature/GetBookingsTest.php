<?php

use App\Models\Booking;
use App\Models\Event;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->freezeSecond();

    $this->attendee = User::factory()->create();
});

/**
 * Create a booking of the attendee for a published event with the given title and start
 * time.
 */
function bookingOf(User $attendee, string $title, DateTimeInterface $startsAt, bool $cancelled = false): Booking
{
    $event = Event::factory()->published()->create(['title' => $title, 'starts_at' => $startsAt]);

    $factory = Booking::factory()->for($event)->for($attendee, 'attendee');

    if ($cancelled) {
        $factory = $factory->cancelled();
    }

    return $factory->create();
}

test('a visitor is sent to the login page', function () {
    $this->get(route('bookings.index'))->assertRedirect(route('login'));
});

test('the attendee sees the data of each booking', function () {
    $event = Event::factory()->published()->create([
        'title' => 'Laravel Meetup',
        'venue' => 'Main Hall',
        'starts_at' => now()->addDays(2),
    ]);
    $booking = Booking::factory()->for($event)->for($this->attendee, 'attendee')->create(['quantity' => 3]);

    $this->actingAs($this->attendee)
        ->get(route('bookings.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('bookings/index')
            ->where('upcoming', [[
                'reference' => $booking->reference,
                'quantity' => 3,
                'status' => 'confirmed',
                'can_cancel' => true,
                'event_cancelled' => false,
                'event' => [
                    'id' => $event->id,
                    'title' => 'Laravel Meetup',
                    'venue' => 'Main Hall',
                    'starts_at' => now()->addDays(2)->toIso8601String(),
                ],
            ]])
            ->where('past', [])
        );
});

test('upcoming bookings come earliest first and past bookings most recent first', function () {
    bookingOf($this->attendee, 'In ten days', now()->addDays(10));
    bookingOf($this->attendee, 'In two days', now()->addDays(2));
    bookingOf($this->attendee, 'In five days', now()->addDays(5), cancelled: true);
    bookingOf($this->attendee, 'Three days ago', now()->subDays(3));
    bookingOf($this->attendee, 'Now', now());
    bookingOf($this->attendee, 'One day ago', now()->subDay(), cancelled: true);

    $this->actingAs($this->attendee)
        ->get(route('bookings.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('upcoming', fn ($rows) => collect($rows)->pluck('event.title')->all()
                === ['In two days', 'In five days', 'In ten days'])
            ->where('past', fn ($rows) => collect($rows)->pluck('event.title')->all()
                === ['Now', 'One day ago', 'Three days ago'])
        );
});

test('cancelled bookings show with their status', function () {
    bookingOf($this->attendee, 'Kept', now()->addDay());
    bookingOf($this->attendee, 'Dropped', now()->addDays(2), cancelled: true);

    $this->actingAs($this->attendee)
        ->get(route('bookings.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('upcoming', fn ($rows) => collect($rows)->pluck('status', 'event.title')->all()
                === ['Kept' => 'confirmed', 'Dropped' => 'cancelled'])
        );
});

test('BR-B12: a cancelled booking and a new booking for the same event both show', function () {
    $event = Event::factory()->published()->create(['starts_at' => now()->addDay()]);
    $cancelled = Booking::factory()->for($event)->for($this->attendee, 'attendee')->cancelled()->create();
    $confirmed = Booking::factory()->for($event)->for($this->attendee, 'attendee')->create();

    $this->actingAs($this->attendee)
        ->get(route('bookings.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('upcoming', fn ($rows) => collect($rows)->pluck('reference')->all()
                === [$cancelled->reference, $confirmed->reference])
        );
});

test('the page does not show the bookings of other users', function () {
    bookingOf($this->attendee, 'Mine', now()->addDay());
    bookingOf(User::factory()->create(), 'Not mine', now()->addDay());

    $this->actingAs($this->attendee)
        ->get(route('bookings.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('upcoming', fn ($rows) => collect($rows)->pluck('event.title')->all() === ['Mine'])
        );
});

test('the organizer does not see the bookings of their event on this page', function () {
    $organizer = User::factory()->create();
    $event = Event::factory()->for($organizer, 'organizer')->published()->create(['starts_at' => now()->addDay()]);
    Booking::factory()->for($event)->for($this->attendee, 'attendee')->create();

    $this->actingAs($organizer)
        ->get(route('bookings.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('upcoming', [])
            ->where('past', [])
        );
});

test('an admin sees only their own bookings', function () {
    $admin = User::factory()->admin()->create();
    bookingOf($this->attendee, 'Not mine', now()->addDay());

    $this->actingAs($admin)
        ->get(route('bookings.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('upcoming', [])
            ->where('past', [])
        );
});

test('BR-B10: only confirmed bookings of events that have not started can be cancelled', function () {
    bookingOf($this->attendee, 'Upcoming', now()->addDay());
    bookingOf($this->attendee, 'Cancelled', now()->addDays(2), cancelled: true);
    bookingOf($this->attendee, 'Started', now()->subDay());

    $this->actingAs($this->attendee)
        ->get(route('bookings.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('upcoming', fn ($rows) => collect($rows)->pluck('can_cancel', 'event.title')->all()
                === ['Upcoming' => true, 'Cancelled' => false])
            ->where('past', fn ($rows) => collect($rows)->pluck('can_cancel', 'event.title')->all()
                === ['Started' => false])
        );
});

test('BR-E13: the bookings of a cancelled event say that the event is cancelled', function () {
    bookingOf($this->attendee, 'Still on', now()->addDay());
    $booking = bookingOf($this->attendee, 'Called off', now()->addDays(2), cancelled: true);
    $booking->event->forceFill(['status' => 'cancelled', 'cancelled_at' => now()])->save();

    $this->actingAs($this->attendee)
        ->get(route('bookings.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('upcoming', fn ($rows) => collect($rows)->pluck('event_cancelled', 'event.title')->all()
                === ['Still on' => false, 'Called off' => true])
        );
});
