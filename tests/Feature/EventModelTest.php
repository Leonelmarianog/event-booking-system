<?php

use App\Enums\EventStatus;
use App\Models\Event;
use App\Models\User;

test('a new event from the factory is a future draft with all seats available', function () {
    $event = Event::factory()->create(['capacity' => 25])->fresh();

    expect($event->status)->toBe(EventStatus::Draft)
        ->and($event->isDraft())->toBeTrue()
        ->and($event->hasStarted())->toBeFalse()
        ->and($event->seats_available)->toBe(25)
        ->and($event->published_at)->toBeNull();
});

test('the factory states set the status', function () {
    expect(Event::factory()->published()->create()->fresh()->isPublished())->toBeTrue()
        ->and(Event::factory()->cancelled()->create()->fresh()->isCancelled())->toBeTrue();
});

test('BR-E5: an event that starts now has started', function () {
    $this->freezeTime();

    $event = Event::factory()->create(['starts_at' => now()]);

    expect($event->hasStarted())->toBeTrue();
});

test('BR-E5: an event that starts in one second has not started', function () {
    $this->freezeTime();

    $event = Event::factory()->create(['starts_at' => now()->addSecond()]);

    expect($event->hasStarted())->toBeFalse();
});

test('BR-E4: an event knows its organizer', function () {
    $organizer = User::factory()->create();
    $event = Event::factory()->for($organizer, 'organizer')->create();

    expect($event->isOrganizedBy($organizer))->toBeTrue()
        ->and($event->isOrganizedBy(User::factory()->create()))->toBeFalse();
});
