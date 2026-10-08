<?php

namespace App\Actions\ReserveSeats;

use App\Exceptions\Domain\AccountDeleted;
use App\Exceptions\Domain\AlreadyBooked;
use App\Exceptions\Domain\EventNotBookable;
use App\Exceptions\Domain\NotEnoughSeats;
use App\Models\Booking;
use App\Models\Event;
use App\Models\User;
use App\Notifications\BookingConfirmed;
use Illuminate\Support\Facades\DB;

class ReserveSeats
{
    /**
     * Book seats of the event for the attendee. The row lock makes parallel requests
     * wait, so two users never get the same last seat (BR-B14). The attendee gets a
     * `BookingConfirmed` email after the commit (BR-N1, BR-N6). The Action locks the user
     * row first, so it waits for a running account delete and then refuses (BR-U5).
     *
     * @throws AccountDeleted
     * @throws EventNotBookable
     * @throws AlreadyBooked
     * @throws NotEnoughSeats
     */
    public function handle(Event $event, User $attendee, int $quantity): Booking
    {
        return DB::transaction(function () use ($event, $attendee, $quantity): Booking {
            $attendee = User::query()->lockForUpdate()->findOrFail($attendee->id);
            $attendee->ensureNotAnonymized();

            $event = Event::query()->lockForUpdate()->findOrFail($event->id);

            $booking = $event->reserve($attendee, $quantity);
            $event->save();
            $booking->save();

            $attendee->notify(new BookingConfirmed($booking));

            return $booking;
        });
    }
}
