<?php

use App\Models\Event;
use App\Models\User;

beforeEach(function () {
    $this->organizer = User::factory()->create();
    $this->otherUser = User::factory()->create();

    $this->draft = Event::factory()->for($this->organizer, 'organizer')->create();
});

test('BR-E17: the organizer deletes a draft event', function () {
    $this->actingAs($this->organizer)
        ->delete(route('events.destroy', $this->draft))
        ->assertRedirect(route('organizer.events.index'))
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Event deleted.']);

    expect(Event::find($this->draft->id))->toBeNull();
});

test('BR-E17: the organizer can delete a draft that has started', function () {
    $event = Event::factory()->started()->for($this->organizer, 'organizer')->create();

    $this->actingAs($this->organizer)
        ->delete(route('events.destroy', $event))
        ->assertRedirect(route('organizer.events.index'));

    expect(Event::find($event->id))->toBeNull();
});

test('a visitor is sent to the login page', function () {
    $this->delete(route('events.destroy', $this->draft))->assertRedirect(route('login'));

    expect(Event::find($this->draft->id))->not->toBeNull();
});

test('BR-E17: another user gets a 404 for a draft event', function () {
    $this->actingAs($this->otherUser)
        ->delete(route('events.destroy', $this->draft))
        ->assertNotFound();

    expect(Event::find($this->draft->id))->not->toBeNull();
});

test('BR-E17: an admin cannot delete the draft event of another user', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->delete(route('events.destroy', $this->draft))
        ->assertForbidden();

    expect(Event::find($this->draft->id))->not->toBeNull();
});

test('BR-E17: the organizer cannot delete a published event', function () {
    $event = Event::factory()->published()->for($this->organizer, 'organizer')->create();

    $this->actingAs($this->organizer)
        ->delete(route('events.destroy', $event))
        ->assertForbidden();

    expect(Event::find($event->id))->not->toBeNull();
});

test('BR-E17: the organizer cannot delete a cancelled event', function () {
    $event = Event::factory()->cancelled()->for($this->organizer, 'organizer')->create();

    $this->actingAs($this->organizer)
        ->delete(route('events.destroy', $event))
        ->assertForbidden();

    expect(Event::find($event->id))->not->toBeNull();
});
