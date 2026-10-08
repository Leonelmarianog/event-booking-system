<?php

use App\Actions\CancelEvent\CancelEvent;
use App\Models\Booking;
use App\Models\Event;
use App\Models\User;
use App\Notifications\BookingCancelled;
use App\Notifications\EventCancelled;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event as EventFacade;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->freezeSecond();

    $this->organizer = User::factory()->create();
    $this->event = Event::factory()->published()->for($this->organizer, 'organizer')
        ->create(['capacity' => 10, 'seats_available' => 5]);

    $this->first = Booking::factory()->for($this->event)->create(['quantity' => 2]);
    $this->second = Booking::factory()->for($this->event)->create(['quantity' => 3]);
    $this->earlierCancel = Booking::factory()->cancelled()->for($this->event)
        ->create(['quantity' => 4, 'cancelled_at' => now()->subDay()]);
});

test('BR-E13: the organizer cancels the event and all confirmed bookings are cancelled', function () {
    $this->actingAs($this->organizer)
        ->post(route('events.cancellation.store', $this->event))
        ->assertRedirect(route('events.show', $this->event))
        ->assertInertiaFlash('toast', [
            'type' => 'success',
            'message' => 'Event cancelled. 2 bookings were cancelled.',
        ]);

    $event = $this->event->fresh();

    expect($event->isCancelled())->toBeTrue()
        ->and($event->cancelled_at->equalTo(now()))->toBeTrue()
        ->and($this->first->fresh()->isCancelled())->toBeTrue()
        ->and($this->first->fresh()->cancelled_at->equalTo(now()))->toBeTrue()
        ->and($this->second->fresh()->isCancelled())->toBeTrue();
});

test('BR-B11: the seats of the cancelled bookings go back to the event', function () {
    $this->actingAs($this->organizer)->post(route('events.cancellation.store', $this->event));

    expect($this->event->fresh()->seats_available)->toBe(10);
});

test('a booking that was already cancelled keeps its cancel time', function () {
    $this->actingAs($this->organizer)->post(route('events.cancellation.store', $this->event));

    expect($this->earlierCancel->fresh()->cancelled_at->equalTo(now()->subDay()))->toBeTrue();
});

test('the toast says "booking" for one booking and nothing for no bookings', function () {
    $one = Event::factory()->published()->for($this->organizer, 'organizer')->create();
    Booking::factory()->for($one)->create();
    $none = Event::factory()->for($this->organizer, 'organizer')->create();

    $this->actingAs($this->organizer)
        ->post(route('events.cancellation.store', $one))
        ->assertInertiaFlash('toast.message', 'Event cancelled. 1 booking was cancelled.');

    $this->actingAs($this->organizer)
        ->post(route('events.cancellation.store', $none))
        ->assertInertiaFlash('toast.message', 'Event cancelled.');

    expect($none->fresh()->isCancelled())->toBeTrue();
});

test('BR-A1: an admin can cancel the event of another user', function () {
    $this->actingAs(User::factory()->admin()->create())
        ->post(route('events.cancellation.store', $this->event))
        ->assertRedirect(route('events.show', $this->event));

    expect($this->event->fresh()->isCancelled())->toBeTrue();
});

test('a visitor is sent to the login page', function () {
    $this->post(route('events.cancellation.store', $this->event))
        ->assertRedirect(route('login'));

    expect($this->event->fresh()->isPublished())->toBeTrue();
});

test('BR-E11: another user gets a 403', function () {
    $this->actingAs(User::factory()->create())
        ->post(route('events.cancellation.store', $this->event))
        ->assertForbidden();

    expect($this->event->fresh()->isPublished())->toBeTrue();
});

test('BR-E12: a started event cannot be cancelled', function () {
    $this->event->forceFill(['starts_at' => now()->subHour()])->save();

    $this->actingAs($this->organizer)
        ->from(route('events.show', $this->event))
        ->post(route('events.cancellation.store', $this->event))
        ->assertRedirect(route('events.show', $this->event))
        ->assertInertiaFlash('toast', [
            'type' => 'error',
            'message' => 'The event has started, so it cannot be cancelled.',
        ]);

    expect($this->event->fresh()->isPublished())->toBeTrue()
        ->and($this->first->fresh()->isConfirmed())->toBeTrue()
        ->and($this->event->fresh()->seats_available)->toBe(5);
});

test('BR-E12: a cancelled event cannot be cancelled again', function () {
    $this->actingAs($this->organizer)->post(route('events.cancellation.store', $this->event));
    Notification::fake();

    $this->actingAs($this->organizer)
        ->from(route('events.show', $this->event))
        ->post(route('events.cancellation.store', $this->event))
        ->assertInertiaFlash('toast', [
            'type' => 'error',
            'message' => 'Only a draft or published event can be cancelled. This event is cancelled.',
        ]);

    Notification::assertNothingSent();
});

test('BR-B2: booking after the cancel fails', function () {
    $this->actingAs($this->organizer)->post(route('events.cancellation.store', $this->event));
    $attendee = $this->first->attendee;

    $this->actingAs($attendee)
        ->from(route('events.show', $this->event))
        ->post(route('events.bookings.store', $this->event), ['quantity' => 1])
        ->assertRedirect(route('events.show', $this->event))
        ->assertInertiaFlash('toast.type', 'error');

    expect(Booking::where('event_id', $this->event->id)->where('status', 'confirmed')->count())->toBe(0);
});

test('the cancel uses the event-writes rate limit', function () {
    foreach (range(1, 20) as $attempt) {
        $this->actingAs($this->organizer)->post(route('events.cancellation.store', $this->event));
    }

    $this->actingAs($this->organizer)
        ->post(route('events.cancellation.store', $this->event))
        ->assertTooManyRequests();
});

test('BR-E11: another user gets a 404 for a draft', function () {
    $draft = Event::factory()->for($this->organizer, 'organizer')->create();

    $this->actingAs(User::factory()->create())
        ->post(route('events.cancellation.store', $draft))
        ->assertNotFound();

    expect($draft->fresh()->isDraft())->toBeTrue();
});

test('BR-E13: after the cancel, the attendee sees "Event cancelled" on My bookings', function () {
    $this->actingAs($this->organizer)->post(route('events.cancellation.store', $this->event));

    $this->actingAs($this->first->attendee)
        ->get(route('bookings.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('upcoming.0.status', 'cancelled')
            ->where('upcoming.0.event_cancelled', true)
        );
});

test('a cancelled event sends no booking cancelled email', function () {
    Notification::fake();

    $this->actingAs($this->organizer)
        ->post(route('events.cancellation.store', $this->event));

    expect($this->first->fresh()->isCancelled())->toBeTrue()
        ->and($this->second->fresh()->isCancelled())->toBeTrue();
    Notification::assertNotSentTo($this->first->attendee, BookingCancelled::class);
    Notification::assertNotSentTo($this->second->attendee, BookingCancelled::class);
});

test('BR-N3: each attendee with a confirmed booking gets an event cancelled email', function () {
    Notification::fake();

    $this->actingAs($this->organizer)
        ->post(route('events.cancellation.store', $this->event));

    Notification::assertSentTo(
        $this->first->attendee,
        EventCancelled::class,
        fn (EventCancelled $notification) => $notification->booking->is($this->first),
    );
    Notification::assertSentTo(
        $this->second->attendee,
        EventCancelled::class,
        fn (EventCancelled $notification) => $notification->booking->is($this->second),
    );
    Notification::assertNotSentTo($this->earlierCancel->attendee, EventCancelled::class);
    Notification::assertNotSentTo($this->organizer, EventCancelled::class);
    Notification::assertCount(2);
});

test('BR-N3: an event with no confirmed bookings sends no email', function () {
    Notification::fake();
    $draft = Event::factory()->for($this->organizer, 'organizer')->create();

    $this->actingAs($this->organizer)
        ->post(route('events.cancellation.store', $draft))
        ->assertInertiaFlash('toast.message', 'Event cancelled.');

    Notification::assertNothingSent();
});

test('BR-N3: no email when the event has started', function () {
    Notification::fake();
    $this->event->forceFill(['starts_at' => now()->subHour()])->save();

    $this->actingAs($this->organizer)
        ->post(route('events.cancellation.store', $this->event))
        ->assertInertiaFlash('toast.message', 'The event has started, so it cannot be cancelled.');

    Notification::assertNothingSent();
});

test('BR-N6: the event cancelled emails are sent only after the transaction commits', function () {
    EventFacade::fake([NotificationSent::class]);

    DB::transaction(function () {
        app(CancelEvent::class)->handle($this->event);

        EventFacade::assertNotDispatched(NotificationSent::class);
    });

    EventFacade::assertDispatchedTimes(NotificationSent::class, 2);
    EventFacade::assertDispatched(
        NotificationSent::class,
        fn (NotificationSent $sent) => $sent->notification instanceof EventCancelled
            && $sent->notifiable->is($this->first->attendee),
    );
});

test('BR-N6: no event cancelled email when the transaction rolls back', function () {
    EventFacade::fake([NotificationSent::class]);

    try {
        DB::transaction(function () {
            app(CancelEvent::class)->handle($this->event);

            throw new LogicException('A later step fails.');
        });
    } catch (LogicException $exception) {
        expect($exception->getMessage())->toBe('A later step fails.');
    }

    EventFacade::assertNotDispatched(NotificationSent::class);
    expect($this->event->fresh()->isPublished())->toBeTrue();
});
