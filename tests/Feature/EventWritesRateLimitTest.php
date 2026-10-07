<?php

use App\Models\Event;
use App\Models\User;

beforeEach(function () {
    $this->user = User::factory()->create();

    $this->validData = [
        'title' => 'Laravel Meetup',
        'description' => 'Talks and pizza.',
        'venue' => 'Main Hall',
        'starts_at' => '2030-05-01T18:30:00.000Z',
        'capacity' => 50,
    ];

    foreach (range(1, 20) as $attempt) {
        $this->actingAs($this->user)->post(route('events.store'), $this->validData);
    }
});

test('an Inertia request over the limit goes back with an error toast', function () {
    $this->actingAs($this->user)
        ->from(route('events.create'))
        ->withHeaders(['X-Inertia' => 'true'])
        ->post(route('events.store'), $this->validData)
        ->assertRedirect(route('events.create'))
        ->assertInertiaFlash('toast', [
            'type' => 'error',
            'message' => 'Too many changes. Wait one minute and try again.',
        ]);

    expect(Event::count())->toBe(20);
});

test('a plain request over the limit gets a 429', function () {
    $this->actingAs($this->user)
        ->post(route('events.store'), $this->validData)
        ->assertTooManyRequests();

    expect(Event::count())->toBe(20);
});

test('the limit is for each user', function () {
    $this->actingAs(User::factory()->create())
        ->post(route('events.store'), $this->validData)
        ->assertRedirect();

    expect(Event::count())->toBe(21);
});

test('the update route uses the same limit', function () {
    $event = Event::factory()->for($this->user, 'organizer')->create();

    $this->actingAs($this->user)
        ->put(route('events.update', $event), $this->validData)
        ->assertTooManyRequests();
});
