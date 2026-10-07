<?php

use App\Enums\EventStatus;
use App\Exceptions\Domain\CapacityBelowBookedSeats;
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
