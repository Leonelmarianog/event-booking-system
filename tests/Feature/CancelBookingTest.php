<?php

use App\Actions\CancelBooking\CancelBooking;
use App\Models\Booking;
use App\Models\Event;
use App\Models\User;
use App\Notifications\BookingCancelled;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event as EventFacade;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    $this->freezeSecond();

    $this->organizer = User::factory()->create();
    $this->attendee = User::factory()->create();

    $this->event = Event::factory()->published()->for($this->organizer, 'organizer')
        ->create(['capacity' => 10, 'seats_available' => 7]);
    $this->booking = Booking::factory()->for($this->event)->for($this->attendee, 'attendee')
        ->create(['quantity' => 3]);
});

test('BR-B11: the attendee cancels a booking and the seats go back to the event', function () {
    $this->actingAs($this->attendee)
        ->from(route('bookings.index'))
        ->post(route('bookings.cancellation.store', $this->booking))
        ->assertRedirect(route('bookings.index'))
        ->assertInertiaFlash('toast', [
            'type' => 'success',
            'message' => "You cancelled your booking. Reference: {$this->booking->reference}.",
        ]);

    $booking = $this->booking->fresh();

    expect($booking->isCancelled())->toBeTrue()
        ->and($booking->cancelled_at->equalTo(now()))->toBeTrue()
        ->and($this->event->fresh()->seats_available)->toBe(10);
});

test('the attendee goes back to the event page after a cancel from there', function () {
    $this->actingAs($this->attendee)
        ->from(route('events.show', $this->event))
        ->post(route('bookings.cancellation.store', $this->booking))
        ->assertRedirect(route('events.show', $this->event));
});

test('the route uses the booking reference', function () {
    expect(route('bookings.cancellation.store', $this->booking))
        ->toEndWith("/bookings/{$this->booking->reference}/cancellation");
});

test('a visitor is sent to the login page', function () {
    $this->post(route('bookings.cancellation.store', $this->booking))
        ->assertRedirect(route('login'));

    expect($this->booking->fresh()->isConfirmed())->toBeTrue();
});

test('BR-B9: the organizer of the event gets a 403', function () {
    $this->actingAs($this->organizer)
        ->post(route('bookings.cancellation.store', $this->booking))
        ->assertForbidden();

    expect($this->booking->fresh()->isConfirmed())->toBeTrue();
});

test('BR-B9: another user gets a 404', function () {
    $this->actingAs(User::factory()->create())
        ->post(route('bookings.cancellation.store', $this->booking))
        ->assertNotFound();

    expect($this->booking->fresh()->isConfirmed())->toBeTrue();
});

test('an unknown reference gives a 404', function () {
    $this->actingAs($this->attendee)
        ->post('/bookings/01jzzzzzzzzzzzzzzzzzzzzzzz/cancellation')
        ->assertNotFound();
});

test('BR-B10: a cancelled booking cannot be cancelled again, and the seats stay the same', function () {
    $this->actingAs($this->attendee)->post(route('bookings.cancellation.store', $this->booking));
    $cancelledAt = $this->booking->fresh()->cancelled_at;
    $this->travel(1)->minutes();

    $this->actingAs($this->attendee)
        ->from(route('bookings.index'))
        ->post(route('bookings.cancellation.store', $this->booking))
        ->assertRedirect(route('bookings.index'))
        ->assertInertiaFlash('toast', [
            'type' => 'error',
            'message' => 'Only a confirmed booking can be cancelled. This booking is cancelled.',
        ]);

    expect($this->booking->fresh()->cancelled_at->equalTo($cancelledAt))->toBeTrue()
        ->and($this->event->fresh()->seats_available)->toBe(10);
});

test('BR-B10: a booking of a started event cannot be cancelled', function () {
    $this->event->forceFill(['starts_at' => now()->subHour()])->save();

    $this->actingAs($this->attendee)
        ->from(route('bookings.index'))
        ->post(route('bookings.cancellation.store', $this->booking))
        ->assertRedirect(route('bookings.index'))
        ->assertInertiaFlash('toast', [
            'type' => 'error',
            'message' => 'The event has started, so the booking cannot be cancelled.',
        ]);

    expect($this->booking->fresh()->isConfirmed())->toBeTrue()
        ->and($this->event->fresh()->seats_available)->toBe(7);
});

test('BR-B12: after a cancel, the attendee can book the same event again', function () {
    $this->actingAs($this->attendee)->post(route('bookings.cancellation.store', $this->booking));

    $this->actingAs($this->attendee)
        ->post(route('events.bookings.store', $this->event), ['quantity' => 2])
        ->assertRedirect(route('events.show', $this->event))
        ->assertInertiaFlash('toast.type', 'success');

    expect(Booking::where('user_id', $this->attendee->id)->pluck('status')->map->value->all())
        ->toEqualCanonicalizing(['cancelled', 'confirmed'])
        ->and($this->event->fresh()->seats_available)->toBe(8);
});

test('the cancel uses the bookings rate limit', function () {
    foreach (range(1, 10) as $attempt) {
        $this->actingAs($this->attendee)->post(route('bookings.cancellation.store', $this->booking));
    }

    $this->actingAs($this->attendee)
        ->post(route('bookings.cancellation.store', $this->booking))
        ->assertTooManyRequests();
});

test('BR-N2: the attendee gets a booking cancelled email', function () {
    Notification::fake();

    $this->actingAs($this->attendee)
        ->post(route('bookings.cancellation.store', $this->booking));

    Notification::assertSentTo(
        $this->attendee,
        BookingCancelled::class,
        fn (BookingCancelled $notification) => $notification->booking->is($this->booking),
    );
    Notification::assertNotSentTo($this->organizer, BookingCancelled::class);
    Notification::assertCount(1);
});

test('BR-N2: no email when the cancel fails a rule', function () {
    Notification::fake();

    $this->actingAs($this->attendee)
        ->post(route('bookings.cancellation.store', $this->booking));
    $this->actingAs($this->attendee)
        ->post(route('bookings.cancellation.store', $this->booking))
        ->assertInertiaFlash('toast.message', 'Only a confirmed booking can be cancelled. This booking is cancelled.');

    Notification::assertSentTimes(BookingCancelled::class, 1);
});

test('BR-N6: the cancel email is sent only after the transaction commits', function () {
    EventFacade::fake([NotificationSent::class]);

    DB::transaction(function () {
        app(CancelBooking::class)->handle($this->booking);

        EventFacade::assertNotDispatched(NotificationSent::class);
    });

    EventFacade::assertDispatchedTimes(NotificationSent::class, 1);
    EventFacade::assertDispatched(
        NotificationSent::class,
        fn (NotificationSent $sent) => $sent->notification instanceof BookingCancelled
            && $sent->notifiable->is($this->attendee),
    );
});

test('BR-N6: no cancel email when the transaction rolls back', function () {
    EventFacade::fake([NotificationSent::class]);

    try {
        DB::transaction(function () {
            app(CancelBooking::class)->handle($this->booking);

            throw new RuntimeException('A later step fails.');
        });
    } catch (RuntimeException) {
    }

    EventFacade::assertNotDispatched(NotificationSent::class);
    expect($this->booking->fresh()->isConfirmed())->toBeTrue();
});
