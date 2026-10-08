<?php

namespace App\Actions\CancelBooking;

use App\Exceptions\Domain\EventHasStarted;
use App\Exceptions\Domain\InvalidStateTransition;
use App\Models\Booking;
use App\Models\Event;
use App\Notifications\BookingCancelled;
use Illuminate\Support\Facades\DB;

class CancelBooking
{
    /**
     * Cancel the booking and give its seats back to the event (BR-B10, BR-B11). The Action
     * locks the event row first and the booking row second, the same order as
     * ReserveSeats, so a second request for the same booking waits and then sees the
     * cancelled booking. The attendee gets a `BookingCancelled` email after the commit
     * (BR-N2, BR-N6).
     *
     * @throws InvalidStateTransition
     * @throws EventHasStarted
     */
    public function handle(Booking $booking): Booking
    {
        return DB::transaction(function () use ($booking): Booking {
            $event = Event::query()->lockForUpdate()->findOrFail($booking->event_id);
            $booking = Booking::query()->lockForUpdate()->findOrFail($booking->id);
            $booking->setRelation('event', $event);

            $booking->cancel();
            $event->save();
            $booking->save();

            $booking->attendee->notify(new BookingCancelled($booking));

            return $booking;
        });
    }
}
