<?php

use App\Enums\EventStatus;
use App\Exceptions\Domain\CapacityBelowBookedSeats;
use App\Exceptions\Domain\EventHasStarted;
use App\Exceptions\Domain\InvalidStateTransition;
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

test('BR-E4: an event knows its organizer when the organizer ID is a string', function () {
    $organizer = User::factory()->create();
    $event = new Event;
    $event->organizer_id = (string) $organizer->id;

    expect($event->isOrganizedBy($organizer))->toBeTrue();
});

test('an event knows how many seats are booked', function () {
    $event = Event::factory()->create(['capacity' => 50, 'seats_available' => 38]);

    expect($event->seatsBooked())->toBe(12);
});

test('BR-E7: a larger capacity adds the same number of available seats', function () {
    $event = Event::factory()->make(['capacity' => 50, 'seats_available' => 38]);

    $event->changeCapacity(60);

    expect($event->capacity)->toBe(60)
        ->and($event->seats_available)->toBe(48);
});

test('BR-E6: the capacity can decrease to the number of booked seats', function () {
    $event = Event::factory()->make(['capacity' => 50, 'seats_available' => 38]);

    $event->changeCapacity(12);

    expect($event->capacity)->toBe(12)
        ->and($event->seats_available)->toBe(0);
});

test('BR-E6: the capacity cannot be less than the booked seats', function () {
    $event = Event::factory()->make(['capacity' => 50, 'seats_available' => 38]);

    expect(fn () => $event->changeCapacity(11))
        ->toThrow(CapacityBelowBookedSeats::class, 'The capacity cannot be less than the 12 booked seats.');

    expect($event->capacity)->toBe(50)
        ->and($event->seats_available)->toBe(38);
});

test('BR-E8: the available seats stay from 0 to the capacity', function () {
    $event = Event::factory()->create(['capacity' => 50, 'seats_available' => 38]);

    $event->changeCapacity(12);
    $event->save();

    expect($event->fresh()->seats_available)->toBe(0);
});

test('BR-E10: the status follows the state diagram', function (EventStatus $from, EventStatus $to, bool $allowed) {
    expect($from->canTransitionTo($to))->toBe($allowed);
})->with([
    'draft to published' => [EventStatus::Draft, EventStatus::Published, true],
    'draft to cancelled' => [EventStatus::Draft, EventStatus::Cancelled, true],
    'draft to draft' => [EventStatus::Draft, EventStatus::Draft, false],
    'published to cancelled' => [EventStatus::Published, EventStatus::Cancelled, true],
    'published to draft' => [EventStatus::Published, EventStatus::Draft, false],
    'published to published' => [EventStatus::Published, EventStatus::Published, false],
    'cancelled to draft' => [EventStatus::Cancelled, EventStatus::Draft, false],
    'cancelled to published' => [EventStatus::Cancelled, EventStatus::Published, false],
    'cancelled to cancelled' => [EventStatus::Cancelled, EventStatus::Cancelled, false],
]);

test('BR-E10: a draft that has not started can be published', function () {
    $this->freezeSecond();
    $event = Event::factory()->make(['starts_at' => now()->addDay()]);

    expect($event->canBePublished())->toBeTrue();

    $event->publish();

    expect($event->status)->toBe(EventStatus::Published)
        ->and($event->published_at->equalTo(now()))->toBeTrue();
});

test('BR-E10: a published event cannot be published again', function () {
    $event = Event::factory()->published()->make();

    expect($event->canBePublished())->toBeFalse()
        ->and(fn () => $event->publish())
        ->toThrow(InvalidStateTransition::class, 'Only a draft event can be published. This event is published.');
});

test('BR-E10: a cancelled event cannot be published', function () {
    $event = Event::factory()->cancelled()->make();

    expect($event->canBePublished())->toBeFalse()
        ->and(fn () => $event->publish())
        ->toThrow(InvalidStateTransition::class, 'Only a draft event can be published. This event is cancelled.');
});

test('BR-E10: a draft that has started cannot be published', function () {
    $event = Event::factory()->started()->make();

    expect($event->canBePublished())->toBeFalse()
        ->and(fn () => $event->publish())
        ->toThrow(EventHasStarted::class, 'The event has started, so it cannot be published.');

    expect($event->status)->toBe(EventStatus::Draft)
        ->and($event->published_at)->toBeNull();
});

test('BR-E17: a draft can be deleted', function () {
    $event = Event::factory()->make();

    expect($event->canBeDeleted())->toBeTrue();

    $event->ensureCanBeDeleted();
});

test('BR-E17: a published event cannot be deleted', function () {
    $event = Event::factory()->published()->make();

    expect($event->canBeDeleted())->toBeFalse()
        ->and(fn () => $event->ensureCanBeDeleted())
        ->toThrow(InvalidStateTransition::class, 'Only a draft event can be deleted. This event is published.');
});

test('BR-E17: a cancelled event cannot be deleted', function () {
    $event = Event::factory()->cancelled()->make();

    expect($event->canBeDeleted())->toBeFalse()
        ->and(fn () => $event->ensureCanBeDeleted())
        ->toThrow(InvalidStateTransition::class, 'Only a draft event can be deleted. This event is cancelled.');
});
