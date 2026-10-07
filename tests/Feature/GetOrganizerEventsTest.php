<?php

use App\Models\Event;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->freezeTime();

    $this->organizer = User::factory()->create();
});

/**
 * Create an event of the organizer that starts at the given time.
 */
function eventOf(User $organizer, string $title, DateTimeInterface $startsAt, string $state = 'draft'): Event
{
    $factory = Event::factory()->for($organizer, 'organizer');

    if ($state !== 'draft') {
        $factory = $factory->{$state}();
    }

    return $factory->create(['title' => $title, 'starts_at' => $startsAt]);
}

test('a visitor is sent to the login page', function () {
    $this->get(route('organizer.events.index'))->assertRedirect(route('login'));
});

test('the organizer sees the data of each event', function () {
    $event = eventOf($this->organizer, 'Laravel Meetup', now()->addDays(2), 'published');
    $event->forceFill(['capacity' => 50, 'seats_available' => 38])->save();

    $this->actingAs($this->organizer)
        ->get(route('organizer.events.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('organizer/events/index')
            ->where('upcoming', [[
                'id' => $event->id,
                'title' => 'Laravel Meetup',
                'starts_at' => now()->addDays(2)->toIso8601String(),
                'status' => 'published',
                'capacity' => 50,
                'seats_booked' => 12,
            ]])
            ->where('past', [])
        );
});

test('upcoming events come earliest first and past events most recent first', function () {
    eventOf($this->organizer, 'In ten days', now()->addDays(10), 'published');
    eventOf($this->organizer, 'In two days', now()->addDays(2));
    eventOf($this->organizer, 'In five days', now()->addDays(5), 'cancelled');
    eventOf($this->organizer, 'Three days ago', now()->subDays(3), 'published');
    eventOf($this->organizer, 'Now', now());
    eventOf($this->organizer, 'One day ago', now()->subDay(), 'cancelled');

    $this->actingAs($this->organizer)
        ->get(route('organizer.events.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('upcoming', fn ($rows) => collect($rows)->pluck('title')->all()
                === ['In two days', 'In five days', 'In ten days'])
            ->where('past', fn ($rows) => collect($rows)->pluck('title')->all()
                === ['Now', 'One day ago', 'Three days ago'])
        );
});

test('the page does not show the events of other users', function () {
    eventOf($this->organizer, 'Mine', now()->addDay());
    eventOf(User::factory()->create(), 'Not mine', now()->addDay(), 'published');

    $this->actingAs($this->organizer)
        ->get(route('organizer.events.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('upcoming', fn ($rows) => collect($rows)->pluck('title')->all() === ['Mine'])
        );
});

test('an admin sees only their own events', function () {
    $admin = User::factory()->admin()->create();
    eventOf($this->organizer, 'Not mine', now()->addDay());

    $this->actingAs($admin)
        ->get(route('organizer.events.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('upcoming', [])
            ->where('past', [])
        );
});
