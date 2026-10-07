<?php

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
