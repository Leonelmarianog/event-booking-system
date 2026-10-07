<?php

use App\Enums\EventStatus;
use App\Models\Event;
use App\Models\User;

beforeEach(function () {
    $this->organizer = User::factory()->create();
    $this->otherUser = User::factory()->create();

    $this->draft = Event::factory()->for($this->organizer, 'organizer')->create();
});

test('BR-E9: the organizer publishes a draft event', function () {
    $this->freezeSecond();

    $this->actingAs($this->organizer)
        ->post(route('events.publication.store', $this->draft))
        ->assertRedirect(route('events.show', $this->draft))
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Event published.']);

    $event = $this->draft->fresh();

    expect($event->status)->toBe(EventStatus::Published)
        ->and($event->published_at->equalTo(now()))->toBeTrue();
});

test('a visitor is sent to the login page', function () {
    $this->post(route('events.publication.store', $this->draft))->assertRedirect(route('login'));

    expect($this->draft->fresh()->status)->toBe(EventStatus::Draft);
});

test('BR-E9: another user gets a 404 for a draft event', function () {
    $this->actingAs($this->otherUser)
        ->post(route('events.publication.store', $this->draft))
        ->assertNotFound();

    expect($this->draft->fresh()->status)->toBe(EventStatus::Draft);
});

test('BR-E9: another user gets a 403 for a published event', function () {
    $event = Event::factory()->published()->for($this->organizer, 'organizer')->create();

    $this->actingAs($this->otherUser)
        ->post(route('events.publication.store', $event))
        ->assertForbidden();
});

test('BR-A3: an admin cannot publish the event of another user', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->post(route('events.publication.store', $this->draft))
        ->assertForbidden();

    expect($this->draft->fresh()->status)->toBe(EventStatus::Draft);
});

test('BR-E10: a published event cannot be published again', function () {
    $event = Event::factory()->published()->for($this->organizer, 'organizer')->create();
    $publishedAt = $event->published_at;

    $this->actingAs($this->organizer)
        ->from(route('events.show', $event))
        ->withHeaders(['X-Inertia' => 'true'])
        ->post(route('events.publication.store', $event))
        ->assertRedirect(route('events.show', $event))
        ->assertInertiaFlash('toast', [
            'type' => 'error',
            'message' => 'Only a draft event can be published. This event is published.',
        ]);

    expect($event->fresh()->published_at->equalTo($publishedAt))->toBeTrue();
});

test('BR-E10: a cancelled event cannot be published', function () {
    $event = Event::factory()->cancelled()->for($this->organizer, 'organizer')->create();

    $this->actingAs($this->organizer)
        ->from(route('events.show', $event))
        ->withHeaders(['X-Inertia' => 'true'])
        ->post(route('events.publication.store', $event))
        ->assertRedirect(route('events.show', $event))
        ->assertInertiaFlash('toast', [
            'type' => 'error',
            'message' => 'Only a draft event can be published. This event is cancelled.',
        ]);

    expect($event->fresh()->status)->toBe(EventStatus::Cancelled);
});

test('BR-E10: a draft that has started cannot be published', function () {
    $event = Event::factory()->started()->for($this->organizer, 'organizer')->create();

    $this->actingAs($this->organizer)
        ->from(route('events.show', $event))
        ->withHeaders(['X-Inertia' => 'true'])
        ->post(route('events.publication.store', $event))
        ->assertRedirect(route('events.show', $event))
        ->assertInertiaFlash('toast', [
            'type' => 'error',
            'message' => 'The event has started, so it cannot be published.',
        ]);

    expect($event->fresh()->status)->toBe(EventStatus::Draft);
});
