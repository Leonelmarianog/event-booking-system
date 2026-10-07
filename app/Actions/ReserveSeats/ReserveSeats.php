<?php

namespace App\Actions\ReserveSeats;

use App\Exceptions\Domain\AlreadyBooked;
use App\Exceptions\Domain\EventNotBookable;
use App\Exceptions\Domain\NotEnoughSeats;
use App\Models\Booking;
use App\Models\Event;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class ReserveSeats
{
    /**
     * Book seats of the event for the attendee. The row lock makes parallel requests
     * wait, so two users never get the same last seat (BR-B14).
     *
     * @throws EventNotBookable
     * @throws AlreadyBooked
     * @throws NotEnoughSeats
     */
    public function handle(Event $event, User $attendee, int $quantity): Booking
    {
        return DB::transaction(function () use ($event, $attendee, $quantity): Booking {
            $event = Event::query()->lockForUpdate()->findOrFail($event->id);

            $booking = $event->reserve($attendee, $quantity);
            $event->save();
            $booking->save();

            return $booking;
        });
    }
}
