<?php

use App\Actions\CreateEvent\CreateEvent;
use App\Enums\EventStatus;
use App\Exceptions\Domain\AccountDeleted;
use App\Models\Event;
use App\Models\User;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->user = User::factory()->create();

    $this->validData = [
        'title' => 'Laravel Meetup',
        'description' => "Talks and pizza.\nBring a laptop.",
        'venue' => 'Main Hall',
        'starts_at' => '2030-05-01T18:30:00.000Z',
        'capacity' => 50,
    ];
});

test('a user can open the create event page', function () {
    $this->actingAs($this->user)
        ->get(route('events.create'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('events/create'));
});

test('a visitor is sent to the login page', function () {
    $this->get(route('events.create'))->assertRedirect(route('login'));
    $this->post(route('events.store'), $this->validData)->assertRedirect(route('login'));

    expect(Event::count())->toBe(0);
});

test('BR-E1: a user creates a draft event and is its organizer', function () {
    $response = $this->actingAs($this->user)
        ->post(route('events.store'), $this->validData);

    $event = Event::sole();

    $response
        ->assertRedirect(route('events.show', $event))
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Event created.']);

    expect($event->organizer_id)->toBe($this->user->id)
        ->and($event->status)->toBe(EventStatus::Draft)
        ->and($event->title)->toBe('Laravel Meetup')
        ->and($event->description)->toBe("Talks and pizza.\nBring a laptop.")
        ->and($event->venue)->toBe('Main Hall')
        ->and($event->starts_at->utc()->toIso8601String())->toBe('2030-05-01T18:30:00+00:00')
        ->and($event->capacity)->toBe(50)
        ->and($event->seats_available)->toBe(50)
        ->and($event->published_at)->toBeNull();
});

test('BR-E2: the capacity must be 1 or more', function () {
    $this->actingAs($this->user)
        ->post(route('events.store'), [...$this->validData, 'capacity' => 0])
        ->assertSessionHasErrors('capacity');

    expect(Event::count())->toBe(0);
});

test('BR-E3: the start time must be in the future', function () {
    $this->actingAs($this->user)
        ->post(route('events.store'), [
            ...$this->validData,
            'starts_at' => now()->subMinute()->toIso8601String(),
        ])
        ->assertSessionHasErrors('starts_at');

    expect(Event::count())->toBe(0);
});

test('the form rejects invalid data', function (array $override, string $field) {
    $this->actingAs($this->user)
        ->post(route('events.store'), [...$this->validData, ...$override])
        ->assertSessionHasErrors($field);

    expect(Event::count())->toBe(0);
})->with([
    'no title' => [['title' => ''], 'title'],
    'title too long' => [['title' => str_repeat('a', 256)], 'title'],
    'no description' => [['description' => ''], 'description'],
    'description too long' => [['description' => str_repeat('a', 5001)], 'description'],
    'no venue' => [['venue' => ''], 'venue'],
    'venue too long' => [['venue' => str_repeat('a', 256)], 'venue'],
    'no start time' => [['starts_at' => ''], 'starts_at'],
    'start time not a date' => [['starts_at' => 'not a date'], 'starts_at'],
    'capacity not a number' => [['capacity' => 'ten'], 'capacity'],
    'capacity too large' => [['capacity' => 10001], 'capacity'],
]);

test('a user who was deleted during the request cannot create an event', function () {
    $user = User::factory()->create();
    $loaded = User::find($user->id);
    $user->anonymize();
    $user->save();

    expect(fn () => app(CreateEvent::class)->handle($loaded, [
        'title' => 'Laravel Meetup',
        'description' => 'Talks.',
        'venue' => 'Main Hall',
        'starts_at' => CarbonImmutable::now()->addWeek(),
        'capacity' => 10,
    ]))->toThrow(AccountDeleted::class);
    expect(Event::count())->toBe(0);
});
