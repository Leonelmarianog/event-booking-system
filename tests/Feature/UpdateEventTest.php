<?php

use App\Enums\EventStatus;
use App\Models\Booking;
use App\Models\Event;
use App\Models\User;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->organizer = User::factory()->create();
    $this->otherUser = User::factory()->create();

    $this->event = Event::factory()->published()->for($this->organizer, 'organizer')->create([
        'title' => 'Old title',
        'capacity' => 50,
        'seats_available' => 50,
    ]);

    $this->validData = [
        'title' => 'Laravel Meetup',
        'description' => "Talks and pizza.\nBring a laptop.",
        'venue' => 'Main Hall',
        'starts_at' => '2030-05-01T18:30:00.000Z',
        'capacity' => 60,
    ];
});

test('the organizer can open the edit page', function () {
    $this->actingAs($this->organizer)
        ->get(route('events.edit', $this->event))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('events/edit')
            ->where('event.id', $this->event->id)
            ->where('event.title', 'Old title')
        );
});

test('a visitor is sent to the login page', function () {
    $this->get(route('events.edit', $this->event))->assertRedirect(route('login'));
    $this->put(route('events.update', $this->event), $this->validData)->assertRedirect(route('login'));
});

test('BR-E4: the organizer updates the event', function () {
    $this->actingAs($this->organizer)
        ->put(route('events.update', $this->event), $this->validData)
        ->assertRedirect(route('events.show', $this->event))
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Event updated.']);

    $event = $this->event->fresh();

    expect($event->title)->toBe('Laravel Meetup')
        ->and($event->description)->toBe("Talks and pizza.\nBring a laptop.")
        ->and($event->venue)->toBe('Main Hall')
        ->and($event->starts_at->utc()->toIso8601String())->toBe('2030-05-01T18:30:00+00:00')
        ->and($event->capacity)->toBe(60)
        ->and($event->seats_available)->toBe(60)
        ->and($event->status)->toBe(EventStatus::Published);
});

test('BR-E4: another user gets a 403 for a published event', function () {
    $this->actingAs($this->otherUser)->get(route('events.edit', $this->event))->assertForbidden();
    $this->actingAs($this->otherUser)->put(route('events.update', $this->event), $this->validData)->assertForbidden();

    expect($this->event->fresh()->title)->toBe('Old title');
});

test('BR-E4: another user gets a 404 for a draft event', function () {
    $draft = Event::factory()->for($this->organizer, 'organizer')->create();

    $this->actingAs($this->otherUser)->get(route('events.edit', $draft))->assertNotFound();
    $this->actingAs($this->otherUser)->put(route('events.update', $draft), $this->validData)->assertNotFound();
});

test('BR-A3: an admin gets a 403 for the event of another user', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)->get(route('events.edit', $this->event))->assertForbidden();
    $this->actingAs($admin)->put(route('events.update', $this->event), $this->validData)->assertForbidden();
});

test('BR-E5: nobody can edit a cancelled event', function () {
    $event = Event::factory()->cancelled()->for($this->organizer, 'organizer')->create();

    $this->actingAs($this->organizer)->get(route('events.edit', $event))->assertForbidden();
    $this->actingAs($this->organizer)->put(route('events.update', $event), $this->validData)->assertForbidden();
});

test('BR-E5: nobody can edit a started event', function () {
    $event = Event::factory()->published()->started()->for($this->organizer, 'organizer')->create();

    $this->actingAs($this->organizer)->get(route('events.edit', $event))->assertForbidden();
    $this->actingAs($this->organizer)->put(route('events.update', $event), $this->validData)->assertForbidden();
});

test('BR-E3: the start time must be in the future', function () {
    $this->actingAs($this->organizer)
        ->put(route('events.update', $this->event), [
            ...$this->validData,
            'starts_at' => now()->subMinute()->toIso8601String(),
        ])
        ->assertSessionHasErrors('starts_at');

    expect($this->event->fresh()->title)->toBe('Old title');
});

test('BR-E2: the capacity must be 1 or more', function () {
    $this->actingAs($this->organizer)
        ->put(route('events.update', $this->event), [...$this->validData, 'capacity' => 0])
        ->assertSessionHasErrors('capacity');
});

test('BR-E7: a larger capacity adds available seats', function () {
    $this->event->forceFill(['seats_available' => 38])->save();

    $this->actingAs($this->organizer)
        ->put(route('events.update', $this->event), [...$this->validData, 'capacity' => 60]);

    expect($this->event->fresh()->seats_available)->toBe(48);
});

test('BR-E6: the capacity cannot be less than the booked seats', function () {
    $this->event->forceFill(['seats_available' => 38])->save();

    $this->actingAs($this->organizer)
        ->from(route('events.edit', $this->event))
        ->withHeaders(['X-Inertia' => 'true'])
        ->put(route('events.update', $this->event), [...$this->validData, 'capacity' => 11])
        ->assertRedirect(route('events.edit', $this->event))
        ->assertSessionHasErrors(['capacity' => 'The capacity cannot be less than the 12 booked seats.'])
        ->assertInertiaFlash('toast', [
            'type' => 'error',
            'message' => 'The capacity cannot be less than the 12 booked seats.',
        ]);

    $event = $this->event->fresh();

    expect($event->title)->toBe('Old title')
        ->and($event->capacity)->toBe(50)
        ->and($event->seats_available)->toBe(38);
});

test('BR-N7: a start time change sends no email to the attendees', function () {
    Notification::fake();
    Booking::factory()->for($this->event)->create();

    $this->actingAs($this->organizer)
        ->put(route('events.update', $this->event), $this->validData)
        ->assertRedirect(route('events.show', $this->event));

    expect($this->event->fresh()->starts_at->utc()->toIso8601String())->toBe('2030-05-01T18:30:00+00:00');
    Notification::assertNothingSent();
});

test('BR-N5: a start time change does not reset the reminder', function () {
    $this->event->forceFill(['reminder_sent_at' => now()])->save();

    $this->actingAs($this->organizer)
        ->put(route('events.update', $this->event), $this->validData);

    expect($this->event->fresh()->reminder_sent_at)->not->toBeNull();
});
