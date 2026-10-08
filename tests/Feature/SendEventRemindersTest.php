<?php

use App\Actions\SendEventReminders\SendEventReminders;
use App\Models\Booking;
use App\Models\Event;
use App\Models\User;
use App\Notifications\EventReminder;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event as EventFacade;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    $this->freezeSecond();

    $this->organizer = User::factory()->create();
    $this->event = Event::factory()->published()->for($this->organizer, 'organizer')
        ->create(['starts_at' => now()->addHours(5), 'capacity' => 10, 'seats_available' => 5]);

    $this->first = Booking::factory()->for($this->event)->create(['quantity' => 2]);
    $this->second = Booking::factory()->for($this->event)->create(['quantity' => 3]);
    $this->cancelledBooking = Booking::factory()->cancelled()->for($this->event)->create();
});

test('BR-N4: attendees with a confirmed booking get a reminder', function () {
    Notification::fake();

    $count = app(SendEventReminders::class)->handle();

    expect($count)->toBe(1);
    Notification::assertSentTo(
        $this->first->attendee,
        EventReminder::class,
        fn (EventReminder $notification) => $notification->booking->is($this->first),
    );
    Notification::assertSentTo(
        $this->second->attendee,
        EventReminder::class,
        fn (EventReminder $notification) => $notification->booking->is($this->second),
    );
    Notification::assertNotSentTo($this->cancelledBooking->attendee, EventReminder::class);
    Notification::assertNotSentTo($this->organizer, EventReminder::class);
    Notification::assertCount(2);
});

test('BR-N5: the event stores the send time of the reminder', function () {
    Notification::fake();

    app(SendEventReminders::class)->handle();

    expect($this->event->fresh()->reminder_sent_at->equalTo(now()))->toBeTrue();
});

test('BR-N5: a second run sends no second reminder', function () {
    Notification::fake();

    app(SendEventReminders::class)->handle();
    $count = app(SendEventReminders::class)->handle();

    expect($count)->toBe(0);
    Notification::assertSentTimes(EventReminder::class, 2);
});

test('BR-N4: events that are not due get no reminder', function () {
    Notification::fake();
    $this->event->forceFill(['starts_at' => now()->addDay()->addSecond()])->save();

    foreach ([
        Event::factory()->create(['starts_at' => now()->addHour()]),
        Event::factory()->cancelled()->create(['starts_at' => now()->addHour()]),
        Event::factory()->published()->create(['starts_at' => now()->subMinute()]),
        Event::factory()->published()->reminderSent()->create(['starts_at' => now()->addHour()]),
    ] as $notDue) {
        Booking::factory()->for($notDue)->create();
    }

    expect(app(SendEventReminders::class)->handle())->toBe(0);
    Notification::assertNothingSent();
    expect($this->event->fresh()->reminder_sent_at)->toBeNull();
});

test('BR-N5: a due event with no confirmed bookings is marked as sent', function () {
    Notification::fake();
    $empty = Event::factory()->published()->create(['starts_at' => now()->addHour()]);

    app(SendEventReminders::class)->handle();

    expect($empty->fresh()->reminder_sent_at)->not->toBeNull();
    Notification::assertNotSentTo($empty->organizer, EventReminder::class);
});

test('an event that changed after the query gets no reminder', function () {
    Notification::fake();
    $this->event->forceFill(['status' => 'cancelled', 'cancelled_at' => now()])->save();

    expect(app(SendEventReminders::class)->remindEvent($this->event->id))->toBeFalse();
    Notification::assertNothingSent();
    expect($this->event->fresh()->reminder_sent_at)->toBeNull();
});

test('an error on one event does not stop the others', function () {
    Notification::fake();
    Exceptions::fake();
    $failing = Event::factory()->published()->create(['starts_at' => now()->addHour()]);

    $action = Mockery::mock(SendEventReminders::class)->makePartial();
    $action->shouldReceive('remindEvent')->with($failing->id)->andThrow(new LogicException('The event fails.'));
    $action->shouldReceive('remindEvent')->with($this->event->id)->passthru();

    expect($action->handle())->toBe(1)
        ->and($this->event->fresh()->reminder_sent_at)->not->toBeNull()
        ->and($failing->fresh()->reminder_sent_at)->toBeNull();
    Notification::assertCount(2);
    Exceptions::assertReported(LogicException::class);
});

test('BR-N6: the reminders are sent only after the transaction commits', function () {
    EventFacade::fake([NotificationSent::class]);

    DB::transaction(function () {
        app(SendEventReminders::class)->remindEvent($this->event->id);

        EventFacade::assertNotDispatched(NotificationSent::class);
    });

    EventFacade::assertDispatchedTimes(NotificationSent::class, 2);
});

test('BR-N6: no reminder and no mark when the transaction rolls back', function () {
    EventFacade::fake([NotificationSent::class]);

    try {
        DB::transaction(function () {
            app(SendEventReminders::class)->remindEvent($this->event->id);

            throw new LogicException('A later step fails.');
        });
    } catch (LogicException $exception) {
        expect($exception->getMessage())->toBe('A later step fails.');
    }

    EventFacade::assertNotDispatched(NotificationSent::class);
    expect($this->event->fresh()->reminder_sent_at)->toBeNull();
});
