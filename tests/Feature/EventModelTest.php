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

test('BR-B11: releasing seats increases the available seats', function () {
    $event = Event::factory()->published()->create(['capacity' => 10, 'seats_available' => 4]);

    $event->releaseSeats(3);

    expect($event->seats_available)->toBe(7);
});

test('BR-B11: releasing seats never goes above the capacity', function () {
    $event = Event::factory()->published()->create(['capacity' => 10, 'seats_available' => 9]);

    $event->releaseSeats(4);

    expect($event->seats_available)->toBe(10);
});

test('BR-E12: a draft or published event can be cancelled', function (string $state) {
    $this->freezeSecond();
    $event = $state === 'draft' ? Event::factory()->create() : Event::factory()->published()->create();

    $event->cancel();
    $event->save();

    $event = $event->fresh();

    expect($event->isCancelled())->toBeTrue()
        ->and($event->cancelled_at->equalTo(now()))->toBeTrue();
})->with(['draft', 'published']);

test('BR-E12: a cancelled event cannot be cancelled again', function () {
    $event = Event::factory()->cancelled()->create();

    expect(fn () => $event->cancel())
        ->toThrow(InvalidStateTransition::class, 'Only a draft or published event can be cancelled. This event is cancelled.');
});

test('BR-E12: a started event cannot be cancelled', function () {
    $event = Event::factory()->published()->started()->create();

    expect(fn () => $event->cancel())
        ->toThrow(EventHasStarted::class, 'The event has started, so it cannot be cancelled.');

    expect($event->isPublished())->toBeTrue()
        ->and($event->cancelled_at)->toBeNull();
});

test('BR-E12: only a draft or published event that has not started can be cancelled', function () {
    expect(Event::factory()->create()->canBeCancelled())->toBeTrue()
        ->and(Event::factory()->published()->create()->canBeCancelled())->toBeTrue()
        ->and(Event::factory()->cancelled()->create()->canBeCancelled())->toBeFalse()
        ->and(Event::factory()->published()->started()->create()->canBeCancelled())->toBeFalse();
});

test('the cancelled factory state sets the cancel time', function () {
    expect(Event::factory()->cancelled()->create()->fresh()->cancelled_at)->not->toBeNull();
});

test('BR-N4: a published event that starts in the next 24 hours is due for a reminder', function () {
    $this->freezeSecond();

    $inOneHour = Event::factory()->published()->create(['starts_at' => now()->addHour()]);
    $inExactly24Hours = Event::factory()->published()->create(['starts_at' => now()->addDay()]);
    $inMoreThan24Hours = Event::factory()->published()->create(['starts_at' => now()->addDay()->addSecond()]);
    $started = Event::factory()->published()->create(['starts_at' => now()->subMinute()]);
    $draft = Event::factory()->create(['starts_at' => now()->addHour()]);
    $cancelled = Event::factory()->cancelled()->create(['starts_at' => now()->addHour()]);
    $alreadySent = Event::factory()->published()->reminderSent()->create(['starts_at' => now()->addHour()]);

    expect(Event::query()->dueForReminder()->pluck('id')->sort()->values()->all())
        ->toBe(collect([$inOneHour->id, $inExactly24Hours->id])->sort()->values()->all())
        ->and($inOneHour->needsReminder())->toBeTrue()
        ->and($inExactly24Hours->needsReminder())->toBeTrue()
        ->and($inMoreThan24Hours->needsReminder())->toBeFalse()
        ->and($started->needsReminder())->toBeFalse()
        ->and($draft->needsReminder())->toBeFalse()
        ->and($cancelled->needsReminder())->toBeFalse()
        ->and($alreadySent->needsReminder())->toBeFalse();
});

test('BR-N5: marking the reminder as sent stores the time and ends the need', function () {
    $this->freezeSecond();
    $event = Event::factory()->published()->create(['starts_at' => now()->addHour()]);

    $event->markReminderSent();

    expect($event->reminder_sent_at->equalTo(now()))->toBeTrue()
        ->and($event->needsReminder())->toBeFalse()
        ->and($event->isDirty('reminder_sent_at'))->toBeTrue();
});

test('BR-N5: the reminder_sent_at column stores the time', function () {
    $event = Event::factory()->reminderSent()->create();

    expect($event->fresh()->reminder_sent_at)->not->toBeNull()
        ->and(Event::factory()->create()->fresh()->reminder_sent_at)->toBeNull();
});
