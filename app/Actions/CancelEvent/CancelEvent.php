<?php

namespace App\Actions\CancelEvent;

use App\Enums\BookingStatus;
use App\Exceptions\Domain\EventHasStarted;
use App\Exceptions\Domain\InvalidStateTransition;
use App\Models\Booking;
use App\Models\Event;
use App\Notifications\EventCancelled;
use Illuminate\Support\Facades\DB;

class CancelEvent
{
    /**
     * Cancel the event and all its confirmed bookings (BR-E12, BR-E13). The seats of the
     * bookings go back to the event (BR-B11). The Action locks the event row first and
     * the bookings second, the same order as ReserveSeats and CancelBooking. It returns
     * the number of cancelled bookings. Each attendee of a cancelled booking gets an
     * `EventCancelled` email after the commit (BR-N3, BR-N6).
     *
     * @throws InvalidStateTransition
     * @throws EventHasStarted
     */
    public function handle(Event $event): int
    {
        return DB::transaction(function () use ($event): int {
            $event = Event::query()->lockForUpdate()->findOrFail($event->id);
            $event->cancel();

            $bookings = $event->bookings()
                ->where('status', BookingStatus::Confirmed)
                ->with('attendee')
                ->lockForUpdate()
                ->get();

            $bookings->each(function (Booking $booking) use ($event): void {
                $booking->setRelation('event', $event);
                $booking->cancel();
                $booking->save();
            });

            $event->save();

            $bookings->each(
                fn (Booking $booking) => $booking->attendee->notify(new EventCancelled($booking)),
            );

            return $bookings->count();
        });
    }
}
