<?php

use App\Models\Booking;
use App\Models\Event;
use App\Notifications\EventReminder;
use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Notification;

test('BR-N4: the command sends the reminders and prints the number of events', function () {
    Notification::fake();
    $event = Event::factory()->published()->create(['starts_at' => now()->addHours(3)]);
    $booking = Booking::factory()->for($event)->create();

    $this->artisan('events:send-reminders')
        ->expectsOutput('Reminders sent for 1 event.')
        ->assertSuccessful();

    Notification::assertSentTo($booking->attendee, EventReminder::class);
});

test('the command prints "events" for more or less than one event', function () {
    $this->artisan('events:send-reminders')
        ->expectsOutput('Reminders sent for 0 events.')
        ->assertSuccessful();
});

test('BR-N4: the command runs daily at 08:00 UTC without overlapping', function () {
    $scheduled = collect(app(Schedule::class)->events())
        ->first(fn (ScheduledEvent $event) => str_contains((string) $event->command, 'events:send-reminders'));

    expect($scheduled)->not->toBeNull()
        ->and($scheduled->expression)->toBe('0 8 * * *')
        ->and($scheduled->timezone)->toBe('UTC')
        ->and($scheduled->withoutOverlapping)->toBeTrue();
});
