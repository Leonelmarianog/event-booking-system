<?php

use App\Models\Event;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

beforeEach(function () {
    $this->organizer = User::factory()->create();
    $this->otherUser = User::factory()->create();
    $this->admin = User::factory()->admin()->create();
});

// View: BR-E14, BR-E15, BR-E16, BR-A4

test('BR-E14: everyone can see a published event, also visitors', function () {
    $event = Event::factory()->published()->for($this->organizer, 'organizer')->create();

    expect(Gate::forUser(null)->allows('view', $event))->toBeTrue()
        ->and($this->otherUser->can('view', $event))->toBeTrue()
        ->and($this->organizer->can('view', $event))->toBeTrue();
});

test('BR-E15: only the organizer and admins can see a draft event', function () {
    $event = Event::factory()->for($this->organizer, 'organizer')->create();

    expect($this->organizer->can('view', $event))->toBeTrue()
        ->and($this->admin->can('view', $event))->toBeTrue()
        ->and($this->otherUser->can('view', $event))->toBeFalse()
        ->and(Gate::forUser(null)->allows('view', $event))->toBeFalse();
});

test('BR-E16: the organizer and admins can see a cancelled event', function () {
    $event = Event::factory()->cancelled()->for($this->organizer, 'organizer')->create();

    expect($this->organizer->can('view', $event))->toBeTrue()
        ->and($this->admin->can('view', $event))->toBeTrue()
        ->and($this->otherUser->can('view', $event))->toBeFalse()
        ->and(Gate::forUser(null)->allows('view', $event))->toBeFalse();
});

test('BR-A4: an admin can see the draft and cancelled events of all users', function () {
    $draft = Event::factory()->create();
    $cancelled = Event::factory()->cancelled()->create();

    expect($this->admin->can('view', $draft))->toBeTrue()
        ->and($this->admin->can('view', $cancelled))->toBeTrue();
});

// Update: BR-E4, BR-E5, BR-A3

test('BR-E4: only the organizer can edit an event', function () {
    $event = Event::factory()->for($this->organizer, 'organizer')->create();

    expect($this->organizer->can('update', $event))->toBeTrue()
        ->and($this->otherUser->can('update', $event))->toBeFalse()
        ->and(Gate::forUser(null)->allows('update', $event))->toBeFalse();
});

test('BR-E4: the organizer can edit a published event that has not started', function () {
    $event = Event::factory()->published()->for($this->organizer, 'organizer')->create();

    expect($this->organizer->can('update', $event))->toBeTrue();
});

test('BR-E5: nobody can edit a cancelled event', function () {
    $event = Event::factory()->cancelled()->for($this->organizer, 'organizer')->create();

    expect($this->organizer->can('update', $event))->toBeFalse();
});

test('BR-E5: nobody can edit a started event', function () {
    $event = Event::factory()->published()->started()->for($this->organizer, 'organizer')->create();

    expect($this->organizer->can('update', $event))->toBeFalse();
});

test('BR-E5: nobody can edit an event that starts now', function () {
    $this->freezeTime();
    $event = Event::factory()->for($this->organizer, 'organizer')->create(['starts_at' => now()]);

    expect($this->organizer->can('update', $event))->toBeFalse();
});

test('BR-A3: an admin cannot edit the events of other users', function () {
    $event = Event::factory()->for($this->organizer, 'organizer')->create();

    expect($this->admin->can('update', $event))->toBeFalse();
});

test('BR-A3: an admin can edit their own events', function () {
    $event = Event::factory()->for($this->admin, 'organizer')->create();

    expect($this->admin->can('update', $event))->toBeTrue();
});

test('BR-E4: another user gets a 404 for a draft event that they cannot see', function () {
    $event = Event::factory()->for($this->organizer, 'organizer')->create();

    expect(Gate::forUser($this->otherUser)->inspect('update', $event)->status())->toBe(404);
});

test('BR-E4: another user gets a 403 for a published event', function () {
    $event = Event::factory()->published()->for($this->organizer, 'organizer')->create();

    $response = Gate::forUser($this->otherUser)->inspect('update', $event);

    expect($response->denied())->toBeTrue()
        ->and($response->status())->toBeNull();
});

test('BR-A3: an admin gets a 403 for the draft event of another user', function () {
    $event = Event::factory()->for($this->organizer, 'organizer')->create();

    $response = Gate::forUser($this->admin)->inspect('update', $event);

    expect($response->denied())->toBeTrue()
        ->and($response->status())->toBeNull();
});

// Publish: BR-E9, BR-A3

test('BR-E9: only the organizer can publish an event', function () {
    $event = Event::factory()->for($this->organizer, 'organizer')->create();

    expect($this->organizer->can('publish', $event))->toBeTrue()
        ->and($this->otherUser->can('publish', $event))->toBeFalse()
        ->and(Gate::forUser(null)->allows('publish', $event))->toBeFalse();
});

test('BR-E9: another user gets a 404 for a draft event that they cannot see', function () {
    $event = Event::factory()->for($this->organizer, 'organizer')->create();

    expect(Gate::forUser($this->otherUser)->inspect('publish', $event)->status())->toBe(404);
});

test('BR-E9: another user gets a 403 for a published event', function () {
    $event = Event::factory()->published()->for($this->organizer, 'organizer')->create();

    $response = Gate::forUser($this->otherUser)->inspect('publish', $event);

    expect($response->denied())->toBeTrue()
        ->and($response->status())->toBeNull();
});

test('BR-A3: an admin cannot publish the events of other users', function () {
    $event = Event::factory()->for($this->organizer, 'organizer')->create();

    expect($this->admin->can('publish', $event))->toBeFalse();
});

test('BR-A3: an admin can publish their own events', function () {
    $event = Event::factory()->for($this->admin, 'organizer')->create();

    expect($this->admin->can('publish', $event))->toBeTrue();
});

// Cancel: BR-E11, BR-A1

test('BR-E11: the organizer can cancel an event', function () {
    $event = Event::factory()->published()->for($this->organizer, 'organizer')->create();

    expect($this->organizer->can('cancel', $event))->toBeTrue()
        ->and($this->otherUser->can('cancel', $event))->toBeFalse()
        ->and(Gate::forUser(null)->allows('cancel', $event))->toBeFalse();
});

test('BR-E11: an admin can cancel any event', function () {
    $event = Event::factory()->published()->for($this->organizer, 'organizer')->create();

    expect($this->admin->can('cancel', $event))->toBeTrue();
});

// Delete: BR-E17

test('BR-E17: the organizer can delete a draft event', function () {
    $event = Event::factory()->for($this->organizer, 'organizer')->create();

    expect($this->organizer->can('delete', $event))->toBeTrue()
        ->and($this->otherUser->can('delete', $event))->toBeFalse()
        ->and($this->admin->can('delete', $event))->toBeFalse()
        ->and(Gate::forUser(null)->allows('delete', $event))->toBeFalse();
});

test('BR-E17: nobody can delete a published or cancelled event', function () {
    $published = Event::factory()->published()->for($this->organizer, 'organizer')->create();
    $cancelled = Event::factory()->cancelled()->for($this->organizer, 'organizer')->create();

    expect($this->organizer->can('delete', $published))->toBeFalse()
        ->and($this->organizer->can('delete', $cancelled))->toBeFalse();
});

test('BR-E5: nobody can edit a draft event that has started', function () {
    $event = Event::factory()->started()->for($this->organizer, 'organizer')->create();

    expect($this->organizer->can('update', $event))->toBeFalse();
});
