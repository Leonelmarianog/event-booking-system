<?php

use App\Models\Event;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('a missing event shows the event not found page', function () {
    $this->get('/events/999999')
        ->assertNotFound()
        ->assertInertia(fn (Assert $page) => $page
            ->component('errors/not-found')
            ->where('title', 'Event not found')
            ->where('message', 'This event does not exist, or you cannot see it.')
        );
});

test('a visitor on a draft event gets the event not found page', function () {
    $event = Event::factory()->create();

    $this->get(route('events.show', $event))
        ->assertNotFound()
        ->assertInertia(fn (Assert $page) => $page
            ->component('errors/not-found')
            ->where('title', 'Event not found')
        );
});

test('an event ID that is not a number shows the event not found page', function () {
    $this->get('/events/abc')
        ->assertNotFound()
        ->assertInertia(fn (Assert $page) => $page
            ->component('errors/not-found')
            ->where('title', 'Event not found')
        );
});

test('an unknown URL shows the page not found page with the user in the navbar', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/no-such-page')
        ->assertNotFound()
        ->assertInertia(fn (Assert $page) => $page
            ->component('errors/not-found')
            ->where('title', 'Page not found')
            ->where('message', 'This page does not exist, or you cannot see it.')
            ->where('auth.user.id', $user->id)
        );
});

test('a missing event gives the navbar data, for a visitor and for a user', function () {
    $this->get('/events/999999')
        ->assertNotFound()
        ->assertInertia(fn (Assert $page) => $page->where('auth.user', null));

    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/events/999999')
        ->assertNotFound()
        ->assertInertia(fn (Assert $page) => $page->where('auth.user.id', $user->id));
});

test('a missing event runs the whole web middleware: appearance and asset version', function () {
    $this->withUnencryptedCookie('appearance', 'light')
        ->get('/events/999999')
        ->assertNotFound()
        ->assertDontSee('class="dark"', false)
        ->assertHeader('Vary', 'X-Inertia');
});

test('a JSON request keeps the JSON 404', function () {
    $this->getJson('/events/999999')
        ->assertNotFound()
        ->assertJsonStructure(['message'])
        ->assertHeaderMissing('X-Inertia');
});

test('other errors do not change', function () {
    $event = Event::factory()->published()->create();

    $this->actingAs(User::factory()->create())
        ->get(route('events.edit', $event))
        ->assertForbidden();
});
