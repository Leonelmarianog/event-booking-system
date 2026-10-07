<?php

use App\Models\Booking;
use App\Models\Event;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

beforeEach(function () {
    $this->organizer = User::factory()->create();
    $this->attendee = User::factory()->create();
    $this->otherUser = User::factory()->create();
    $this->admin = User::factory()->admin()->create();

    $event = Event::factory()->published()->for($this->organizer, 'organizer')->create();
    $this->booking = Booking::factory()->for($event)->for($this->attendee, 'attendee')->create();
});

// View: BR-B13

test('BR-B13: the attendee and the organizer can see a booking', function () {
    expect($this->attendee->can('view', $this->booking))->toBeTrue()
        ->and($this->organizer->can('view', $this->booking))->toBeTrue();
});

test('BR-B13: other users, admins and visitors cannot see a booking', function () {
    expect($this->otherUser->can('view', $this->booking))->toBeFalse()
        ->and($this->admin->can('view', $this->booking))->toBeFalse()
        ->and(Gate::forUser(null)->allows('view', $this->booking))->toBeFalse();
});

test('BR-B13: a user who cannot see a booking gets a 404', function () {
    expect(Gate::forUser($this->otherUser)->inspect('view', $this->booking)->status())->toBe(404);
});

// Cancel: BR-B9

test('BR-B9: only the attendee can cancel a booking', function () {
    expect($this->attendee->can('cancel', $this->booking))->toBeTrue()
        ->and($this->organizer->can('cancel', $this->booking))->toBeFalse()
        ->and($this->otherUser->can('cancel', $this->booking))->toBeFalse()
        ->and($this->admin->can('cancel', $this->booking))->toBeFalse()
        ->and(Gate::forUser(null)->allows('cancel', $this->booking))->toBeFalse();
});

test('BR-B9: the organizer gets a 403 for the booking of an attendee', function () {
    $response = Gate::forUser($this->organizer)->inspect('cancel', $this->booking);

    expect($response->denied())->toBeTrue()
        ->and($response->status())->toBeNull();
});

test('BR-B9: a user who cannot see the booking gets a 404', function () {
    expect(Gate::forUser($this->otherUser)->inspect('cancel', $this->booking)->status())->toBe(404);
});
