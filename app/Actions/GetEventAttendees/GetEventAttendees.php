<?php

namespace App\Actions\GetEventAttendees;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Event;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class GetEventAttendees
{
    /**
     * Get the event and one page of its confirmed bookings, first booked first (BR-A2).
     * The page number comes from the `page` query parameter.
     *
     * @return array{
     *     event: array{id: int, title: string, starts_at: string, capacity: int, seats_booked: int},
     *     attendees: LengthAwarePaginator<int, array{reference: string, name: string, email: string, quantity: int, booked_at: string}>,
     * }
     */
    public function handle(Event $event): array
    {
        $attendees = $event->bookings()
            ->where('status', BookingStatus::Confirmed)
            ->with('attendee:id,name,email')
            ->orderBy('created_at')
            ->orderBy('id')
            ->paginate(50)
            ->withQueryString()
            ->through(fn (Booking $booking): array => [
                'reference' => $booking->reference,
                'name' => $booking->attendee->name,
                'email' => $booking->attendee->email,
                'quantity' => $booking->quantity,
                'booked_at' => $booking->created_at->toIso8601String(),
            ]);

        return [
            'event' => [
                'id' => $event->id,
                'title' => $event->title,
                'starts_at' => $event->starts_at->toIso8601String(),
                'capacity' => $event->capacity,
                'seats_booked' => $event->seatsBooked(),
            ],
            'attendees' => $attendees,
        ];
    }
}
