<?php

use App\Models\Event;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->freezeSecond();

    $this->organizer = User::factory()->create();
});

/**
 * Create a published event of the organizer that starts at the given time.
 */
function publishedEvent(User $organizer, string $title, DateTimeInterface $startsAt): Event
{
    return Event::factory()->published()->for($organizer, 'organizer')
        ->create(['title' => $title, 'starts_at' => $startsAt]);
}

/**
 * The titles of the events on the page.
 *
 * @return list<string>
 */
function titlesOn(Assert $page): array
{
    return collect($page->toArray()['props']['events']['data'])->pluck('title')->all();
}

test('BR-E14: a visitor sees the upcoming published events', function () {
    $event = publishedEvent($this->organizer, 'Laravel Meetup', now()->addDays(2));
    $event->forceFill(['venue' => 'Main Hall', 'capacity' => 50, 'seats_available' => 0])->save();

    $this->get(route('events.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('events/index')
            ->where('events.data', [[
                'id' => $event->id,
                'title' => 'Laravel Meetup',
                'starts_at' => now()->addDays(2)->toIso8601String(),
                'venue' => 'Main Hall',
                'seats_available' => 0,
            ]])
            ->where('events.current_page', 1)
            ->where('events.last_page', 1)
        );
});

test('BR-E15: drafts are not in the list', function () {
    Event::factory()->for($this->organizer, 'organizer')->create(['starts_at' => now()->addDay()]);

    $this->get(route('events.index'))
        ->assertInertia(fn (Assert $page) => $page->where('events.data', []));
});

test('BR-E16: cancelled events are not in the list', function () {
    Event::factory()->cancelled()->for($this->organizer, 'organizer')->create(['starts_at' => now()->addDay()]);

    $this->get(route('events.index'))
        ->assertInertia(fn (Assert $page) => $page->where('events.data', []));
});

test('BR-E14: started events are not in the list', function () {
    publishedEvent($this->organizer, 'Yesterday', now()->subDay());
    publishedEvent($this->organizer, 'Now', now());

    $this->get(route('events.index'))
        ->assertInertia(fn (Assert $page) => $page->where('events.data', []));
});

test('the events come earliest first', function () {
    publishedEvent($this->organizer, 'In ten days', now()->addDays(10));
    publishedEvent($this->organizer, 'In two days', now()->addDays(2));
    publishedEvent($this->organizer, 'In five days', now()->addDays(5));

    $this->get(route('events.index'))
        ->assertInertia(fn (Assert $page) => expect(titlesOn($page))
            ->toBe(['In two days', 'In five days', 'In ten days']));
});

test('the list has 12 events on each page', function () {
    foreach (range(1, 13) as $day) {
        publishedEvent($this->organizer, "Day {$day}", now()->addDays($day));
    }

    $this->get(route('events.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('events.data', 12)
            ->where('events.current_page', 1)
            ->where('events.last_page', 2)
        );

    $this->get(route('events.index', ['page' => 2]))
        ->assertInertia(fn (Assert $page) => expect(titlesOn($page))->toBe(['Day 13']));
});

test('a logged-in user sees the same list', function () {
    publishedEvent($this->organizer, 'Laravel Meetup', now()->addDay());
    Event::factory()->for($this->organizer, 'organizer')->create(['starts_at' => now()->addDay()]);

    $this->actingAs($this->organizer)
        ->get(route('events.index'))
        ->assertInertia(fn (Assert $page) => expect(titlesOn($page))->toBe(['Laravel Meetup']));
});
