<?php

use App\Models\Booking;
use App\Models\Event;
use App\Models\User;
use App\Notifications\BookingCancelled;
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

    $this->actingAs($this->organizer)
        ->from(route('events.show', $this->event))
        ->post(route('events.cancellation.store', $this->event))
        ->assertInertiaFlash('toast', [
            'type' => 'error',
            'message' => 'Only a draft or published event can be cancelled. This event is cancelled.',
        ]);
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
