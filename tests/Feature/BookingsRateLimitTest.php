<?php

use App\Models\Booking;
use App\Models\Event;
use App\Models\User;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->event = Event::factory()->published()->create(['capacity' => 10, 'seats_available' => 10]);

    foreach (range(1, 10) as $attempt) {
        $this->actingAs($this->user)->post(route('events.bookings.store', $this->event), ['quantity' => 1]);
    }
});

test('an Inertia request over the limit goes back with an error toast', function () {
    $this->actingAs($this->user)
        ->from(route('events.show', $this->event))
        ->withHeaders(['X-Inertia' => 'true'])
        ->post(route('events.bookings.store', $this->event), ['quantity' => 1])
        ->assertRedirect(route('events.show', $this->event))
        ->assertInertiaFlash('toast', [
            'type' => 'error',
            'message' => 'Too many booking requests. Wait one minute and try again.',
        ]);
});

test('a plain request over the limit gets a 429', function () {
    $this->actingAs($this->user)
        ->post(route('events.bookings.store', $this->event), ['quantity' => 1])
        ->assertTooManyRequests();
});

test('the limit is for each user', function () {
    $this->actingAs(User::factory()->create())
        ->post(route('events.bookings.store', $this->event), ['quantity' => 1])
        ->assertRedirect(route('events.show', $this->event));

    expect(Booking::count())->toBe(2);
});
