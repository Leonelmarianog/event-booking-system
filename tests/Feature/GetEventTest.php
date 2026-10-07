<?php

use App\Models\Booking;
use App\Models\Event;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->organizer = User::factory()->create(['name' => 'Olivia Organizer']);
    $this->otherUser = User::factory()->create();
    $this->admin = User::factory()->admin()->create();
});

test('BR-E14: a visitor can see a published event', function () {
    $event = Event::factory()->published()->for($this->organizer, 'organizer')->create([
        'title' => 'Laravel Meetup',
        'description' => "Talks and pizza.\nBring a laptop.",
        'venue' => 'Main Hall',
        'starts_at' => '2030-05-01 18:30:00+00',
        'capacity' => 50,
        'seats_available' => 0,
    ]);

    $this->get(route('events.show', $event))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('events/show')
            ->where('event', [
                'id' => $event->id,
                'title' => 'Laravel Meetup',
                'description' => "Talks and pizza.\nBring a laptop.",
                'venue' => 'Main Hall',
                'starts_at' => '2030-05-01T18:30:00+00:00',
                'capacity' => 50,
                'seats_available' => 0,
                'status' => 'published',
                'organizer_name' => 'Olivia Organizer',
            ])
        );
});

test('BR-E14: another user can see a published event', function () {
    $event = Event::factory()->published()->for($this->organizer, 'organizer')->create();

    $this->actingAs($this->otherUser)
        ->get(route('events.show', $event))
        ->assertOk();
});

test('BR-E15: the organizer can see their draft event', function () {
    $event = Event::factory()->for($this->organizer, 'organizer')->create();

    $this->actingAs($this->organizer)
        ->get(route('events.show', $event))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('events/show')
            ->where('event.status', 'draft')
        );
});

test('BR-E15: another user gets a 404 for a draft event', function () {
    $event = Event::factory()->for($this->organizer, 'organizer')->create();

    $this->actingAs($this->otherUser)
        ->get(route('events.show', $event))
        ->assertNotFound();
});

test('BR-E15: a visitor gets a 404 for a draft event', function () {
    $event = Event::factory()->for($this->organizer, 'organizer')->create();

    $this->get(route('events.show', $event))
        ->assertNotFound();
});

test('BR-E16: the organizer can see their cancelled event', function () {
    $event = Event::factory()->cancelled()->for($this->organizer, 'organizer')->create();

    $this->actingAs($this->organizer)
        ->get(route('events.show', $event))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('event.status', 'cancelled'));
});

test('BR-E16: another user and a visitor get a 404 for a cancelled event', function () {
    $event = Event::factory()->cancelled()->for($this->organizer, 'organizer')->create();

    $this->actingAs($this->otherUser)
        ->get(route('events.show', $event))
        ->assertNotFound();

    auth()->logout();

    $this->get(route('events.show', $event))
        ->assertNotFound();
});

test('BR-A4: an admin can see the draft and cancelled events of other users', function () {
    $draft = Event::factory()->for($this->organizer, 'organizer')->create();
    $cancelled = Event::factory()->cancelled()->for($this->organizer, 'organizer')->create();

    $this->actingAs($this->admin)->get(route('events.show', $draft))->assertOk();
    $this->actingAs($this->admin)->get(route('events.show', $cancelled))->assertOk();
});

test('an event that does not exist gives a 404', function () {
    $this->get('/events/999999')->assertNotFound();
});

test('an event ID that is not a number gives a 404', function () {
    $this->get('/events/abc')->assertNotFound();
});

test('BR-E4: the organizer sees the edit link', function () {
    $event = Event::factory()->published()->for($this->organizer, 'organizer')->create();

    $this->actingAs($this->organizer)
        ->get(route('events.show', $event))
        ->assertInertia(fn (Assert $page) => $page->where('can.update', true));
});

test('BR-E4: other users and visitors do not see the edit link', function () {
    $event = Event::factory()->published()->for($this->organizer, 'organizer')->create();

    $this->actingAs($this->otherUser)
        ->get(route('events.show', $event))
        ->assertInertia(fn (Assert $page) => $page->where('can.update', false));

    auth()->logout();

    $this->get(route('events.show', $event))
        ->assertInertia(fn (Assert $page) => $page->where('can.update', false));
});

test('BR-E5: the organizer does not see the edit link on a started event', function () {
    $event = Event::factory()->published()->started()->for($this->organizer, 'organizer')->create();

    $this->actingAs($this->organizer)
        ->get(route('events.show', $event))
        ->assertInertia(fn (Assert $page) => $page->where('can.update', false));
});

test('BR-E9: the organizer sees the publish button on a draft that has not started', function () {
    $event = Event::factory()->for($this->organizer, 'organizer')->create();

    $this->actingAs($this->organizer)
        ->get(route('events.show', $event))
        ->assertInertia(fn (Assert $page) => $page->where('can.publish', true));
});

test('BR-E10: the organizer does not see the publish button on a published or started event', function () {
    $published = Event::factory()->published()->for($this->organizer, 'organizer')->create();
    $started = Event::factory()->started()->for($this->organizer, 'organizer')->create();

    $this->actingAs($this->organizer)
        ->get(route('events.show', $published))
        ->assertInertia(fn (Assert $page) => $page->where('can.publish', false));

    $this->actingAs($this->organizer)
        ->get(route('events.show', $started))
        ->assertInertia(fn (Assert $page) => $page->where('can.publish', false));
});

test('BR-A3: an admin does not see the publish button on the draft of another user', function () {
    $event = Event::factory()->for($this->organizer, 'organizer')->create();

    $this->actingAs($this->admin)
        ->get(route('events.show', $event))
        ->assertInertia(fn (Assert $page) => $page->where('can.publish', false));
});

test('BR-E17: the organizer sees the delete button on a draft', function () {
    $event = Event::factory()->for($this->organizer, 'organizer')->create();

    $this->actingAs($this->organizer)
        ->get(route('events.show', $event))
        ->assertInertia(fn (Assert $page) => $page->where('can.delete', true));
});

test('BR-E17: the organizer does not see the delete button on a published event', function () {
    $event = Event::factory()->published()->for($this->organizer, 'organizer')->create();

    $this->actingAs($this->organizer)
        ->get(route('events.show', $event))
        ->assertInertia(fn (Assert $page) => $page->where('can.delete', false));
});

test('BR-E17: an admin does not see the delete button on the draft of another user', function () {
    $event = Event::factory()->for($this->organizer, 'organizer')->create();

    $this->actingAs($this->admin)
        ->get(route('events.show', $event))
        ->assertInertia(fn (Assert $page) => $page->where('can.delete', false));
});

// Booking box: BR-B1 to BR-B4

test('BR-B2: a user sees the booking form on a bookable event', function () {
    $event = Event::factory()->published()->create(['capacity' => 10, 'seats_available' => 10]);

    $this->actingAs($this->otherUser)
        ->get(route('events.show', $event))
        ->assertInertia(fn (Assert $page) => $page
            ->where('booking', ['state' => 'available', 'max_quantity' => 4])
        );
});

test('BR-B6: the form allows at most the available seats', function () {
    $event = Event::factory()->published()->create(['capacity' => 10, 'seats_available' => 2]);

    $this->actingAs($this->otherUser)
        ->get(route('events.show', $event))
        ->assertInertia(fn (Assert $page) => $page
            ->where('booking', ['state' => 'available', 'max_quantity' => 2])
        );
});

test('BR-B4: a user with a confirmed booking sees the booking, not the form', function () {
    $event = Event::factory()->published()->create();
    $booking = Booking::factory()->for($event)->for($this->otherUser, 'attendee')->create(['quantity' => 3]);

    $this->actingAs($this->otherUser)
        ->get(route('events.show', $event))
        ->assertInertia(fn (Assert $page) => $page
            ->where('booking', ['state' => 'booked', 'reference' => $booking->reference, 'quantity' => 3])
        );
});

test('BR-B12: a user with only a cancelled booking sees the form', function () {
    $event = Event::factory()->published()->create();
    Booking::factory()->cancelled()->for($event)->for($this->otherUser, 'attendee')->create();

    $this->actingAs($this->otherUser)
        ->get(route('events.show', $event))
        ->assertInertia(fn (Assert $page) => $page->where('booking.state', 'available'));
});

test('BR-B1: a visitor sees a login link on a bookable event', function () {
    $event = Event::factory()->published()->create();

    $this->get(route('events.show', $event))
        ->assertInertia(fn (Assert $page) => $page->where('booking', ['state' => 'login']));
});

test('BR-B2: users and visitors see "Sold out" on a sold-out event', function () {
    $event = Event::factory()->published()->create(['capacity' => 5, 'seats_available' => 0]);

    $this->actingAs($this->otherUser)
        ->get(route('events.show', $event))
        ->assertInertia(fn (Assert $page) => $page->where('booking', ['state' => 'sold_out']));

    auth()->logout();

    $this->get(route('events.show', $event))
        ->assertInertia(fn (Assert $page) => $page->where('booking', ['state' => 'sold_out']));
});

test('BR-B3: the organizer sees no booking box on their bookable event', function () {
    $event = Event::factory()->published()->for($this->organizer, 'organizer')->create();

    $this->actingAs($this->organizer)
        ->get(route('events.show', $event))
        ->assertInertia(fn (Assert $page) => $page->where('booking', null));
});

test('BR-B2: there is no booking box on draft, cancelled and started events', function () {
    $draft = Event::factory()->for($this->organizer, 'organizer')->create();
    $cancelled = Event::factory()->cancelled()->for($this->organizer, 'organizer')->create();
    $started = Event::factory()->published()->started()->create();

    foreach ([$draft, $cancelled] as $event) {
        $this->actingAs($this->organizer)
            ->get(route('events.show', $event))
            ->assertInertia(fn (Assert $page) => $page->where('booking', null));
    }

    $this->actingAs($this->otherUser)
        ->get(route('events.show', $started))
        ->assertInertia(fn (Assert $page) => $page->where('booking', null));
});
