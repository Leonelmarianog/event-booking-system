<?php

namespace App\Actions\GetEvent;

use App\Models\Event;
use App\Models\User;

class GetEvent
{
    /**
     * Get the data for the page of one event. The booking box depends on the viewer
     * (BR-B1 to BR-B4).
     *
     * @return array{
     *     event: array{id: int, title: string, description: string, venue: string, starts_at: string, capacity: int, seats_available: int, status: string, organizer_name: string},
     *     booking: array{state: 'booked', reference: string, quantity: int, can_cancel: bool}|array{state: 'available', max_quantity: int}|array{state: 'login'}|array{state: 'sold_out'}|null,
     * }
     */
    public function handle(Event $event, ?User $viewer = null): array
    {
        $event->loadMissing('organizer:id,name');

        return [
            'event' => [
                'id' => $event->id,
                'title' => $event->title,
                'description' => $event->description,
                'venue' => $event->venue,
                'starts_at' => $event->starts_at->toIso8601String(),
                'capacity' => $event->capacity,
                'seats_available' => $event->seats_available,
                'status' => $event->status->value,
                'organizer_name' => $event->organizer->name,
            ],
            'booking' => $this->bookingBox($event, $viewer),
        ];
    }

    /**
     * What the booking box shows to the viewer.
     *
     * @return array{state: 'booked', reference: string, quantity: int, can_cancel: bool}|array{state: 'available', max_quantity: int}|array{state: 'login'}|array{state: 'sold_out'}|null
     */
    private function bookingBox(Event $event, ?User $viewer): ?array
    {
        $booking = $viewer === null ? null : $event->confirmedBookingBy($viewer);

        if ($booking !== null) {
            $booking->setRelation('event', $event);

            return [
                'state' => 'booked',
                'reference' => $booking->reference,
                'quantity' => $booking->quantity,
                'can_cancel' => $booking->canBeCancelled(),
            ];
        }

        if ($viewer !== null && $event->canBeBookedBy($viewer)) {
            return ['state' => 'available', 'max_quantity' => min(4, $event->seats_available)];
        }

        if ($viewer === null && $event->isBookable()) {
            return ['state' => 'login'];
        }

        if ($event->isSoldOut()) {
            return ['state' => 'sold_out'];
        }

        return null;
    }
}
