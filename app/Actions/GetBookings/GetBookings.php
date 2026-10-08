<?php

namespace App\Actions\GetBookings;

use App\Models\Booking;
use App\Models\User;

class GetBookings
{
    /**
     * Get the bookings of the user, split into upcoming bookings (earliest event first)
     * and past bookings (most recent event first).
     *
     * @return array{upcoming: array<int, array{reference: string, quantity: int, status: string, can_cancel: bool, event_cancelled: bool, event: array{id: int, title: string, venue: string, starts_at: string}}>, past: array<int, array{reference: string, quantity: int, status: string, can_cancel: bool, event_cancelled: bool, event: array{id: int, title: string, venue: string, starts_at: string}}>}
     */
    public function handle(User $user): array
    {
        $bookings = Booking::query()
            ->select('bookings.*')
            ->join('events', 'events.id', '=', 'bookings.event_id')
            ->whereBelongsTo($user, 'attendee')
            ->orderBy('events.starts_at')
            ->orderBy('bookings.id')
            ->with('event:id,title,venue,starts_at,status')
            ->get();

        [$past, $upcoming] = $bookings->partition(fn (Booking $booking): bool => $booking->event->hasStarted());

        return [
            'upcoming' => $upcoming->map(fn (Booking $booking): array => $this->row($booking))->values()->all(),
            'past' => $past->reverse()->map(fn (Booking $booking): array => $this->row($booking))->values()->all(),
        ];
    }

    /**
     * Get the data of one row of the table.
     *
     * @return array{reference: string, quantity: int, status: string, can_cancel: bool, event_cancelled: bool, event: array{id: int, title: string, venue: string, starts_at: string}}
     */
    private function row(Booking $booking): array
    {
        return [
            'reference' => $booking->reference,
            'quantity' => $booking->quantity,
            'status' => $booking->status->value,
            'can_cancel' => $booking->canBeCancelled(),
            'event_cancelled' => $booking->event->isCancelled(),
            'event' => [
                'id' => $booking->event->id,
                'title' => $booking->event->title,
                'venue' => $booking->event->venue,
                'starts_at' => $booking->event->starts_at->toIso8601String(),
            ],
        ];
    }
}
