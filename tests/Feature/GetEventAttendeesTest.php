<?php

use App\Models\Booking;
use App\Models\Event;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->freezeSecond();

    $this->organizer = User::factory()->create();
    $this->event = Event::factory()->published()->for($this->organizer, 'organizer')
        ->create(['title' => 'Laravel Meetup', 'capacity' => 50, 'seats_available' => 50]);
});

/**
 * Create a confirmed booking for the event, made the given number of minutes ago. It
 * also takes the seats from the event.
 */
function attendeeBooking(Event $event, string $name, int $minutesAgo, int $quantity = 1): Booking
{
    $event->decrement('seats_available', $quantity);

    return Booking::factory()
        ->for($event)
        ->for(User::factory()->create(['name' => $name, 'email' => strtolower($name).'@example.com']), 'attendee')
        ->create(['quantity' => $quantity, 'created_at' => now()->subMinutes($minutesAgo)]);
}

test('a visitor is sent to the login page', function () {
    $this->get(route('events.attendees.index', $this->event))->assertRedirect(route('login'));
});

test('BR-A2: another user gets a 403', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('events.attendees.index', $this->event))
        ->assertForbidden();
});

test('BR-A2: an admin can see the attendee list of any event', function () {
    $this->actingAs(User::factory()->admin()->create())
        ->get(route('events.attendees.index', $this->event))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('organizer/events/attendees'));
});

test('the organizer sees the event and the data of each attendee', function () {
    $booking = attendeeBooking($this->event, 'Ana', 30, quantity: 3);

    $this->actingAs($this->organizer)
        ->get(route('events.attendees.index', $this->event))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('organizer/events/attendees')
            ->where('event', [
                'id' => $this->event->id,
                'title' => 'Laravel Meetup',
                'starts_at' => $this->event->starts_at->toIso8601String(),
                'capacity' => 50,
                'seats_booked' => 3,
            ])
            ->where('attendees.total', 1)
            ->where('attendees.data', [[
                'reference' => $booking->reference,
                'name' => 'Ana',
                'email' => 'ana@example.com',
                'quantity' => 3,
                'booked_at' => now()->subMinutes(30)->toIso8601String(),
            ]])
        );
});

test('the list has only confirmed bookings, first booked first', function () {
    attendeeBooking($this->event, 'Second', 20);
    attendeeBooking($this->event, 'First', 40);
    Booking::factory()->cancelled()->for($this->event)->create(['created_at' => now()->subHour()]);
    attendeeBooking($this->event, 'Third', 10);

    $this->actingAs($this->organizer)
        ->get(route('events.attendees.index', $this->event))
        ->assertInertia(fn (Assert $page) => $page
            ->where('attendees.total', 3)
            ->where('event.seats_booked', 3)
            ->where('attendees.data', fn ($rows) => collect($rows)->pluck('name')->all()
                === ['First', 'Second', 'Third'])
        );
});

test('the list has 50 attendees on each page', function () {
    foreach (range(1, 51) as $number) {
        $newest = Booking::factory()->for($this->event)->create(['created_at' => now()->subMinutes(100 - $number)]);
    }

    $this->actingAs($this->organizer)
        ->get(route('events.attendees.index', $this->event))
        ->assertInertia(fn (Assert $page) => $page
            ->has('attendees.data', 50)
            ->where('attendees.total', 51)
            ->where('attendees.last_page', 2)
        );

    $this->actingAs($this->organizer)
        ->get(route('events.attendees.index', ['event' => $this->event, 'page' => 2]))
        ->assertInertia(fn (Assert $page) => $page
            ->has('attendees.data', 1)
            ->where('attendees.data.0.reference', $newest->reference)
            ->where('attendees.total', 51)
            ->where('attendees.current_page', 2)
        );
});

test('an event with no bookings has an empty list', function () {
    $this->actingAs($this->organizer)
        ->get(route('events.attendees.index', $this->event))
        ->assertInertia(fn (Assert $page) => $page
            ->where('attendees.total', 0)
            ->where('attendees.data', [])
            ->where('event.seats_booked', 0)
        );
});

test('an event that the user cannot see gives a 404', function () {
    $draft = Event::factory()->for($this->organizer, 'organizer')->create();

    $this->actingAs(User::factory()->create())
        ->get(route('events.attendees.index', $draft))
        ->assertNotFound();
});
