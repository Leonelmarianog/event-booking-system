<?php

namespace App\Actions\GetOrganizerEvents;

use App\Models\Event;
use App\Models\User;

class GetOrganizerEvents
{
    /**
     * Get the events of the organizer, split into upcoming events (earliest first) and
     * past events (most recent first).
     *
     * @return array{upcoming: array<int, array{id: int, title: string, starts_at: string, status: string, capacity: int, seats_booked: int, can_update: bool}>, past: array<int, array{id: int, title: string, starts_at: string, status: string, capacity: int, seats_booked: int, can_update: bool}>}
     */
    public function handle(User $organizer): array
    {
        $events = Event::query()
            ->whereBelongsTo($organizer, 'organizer')
            ->orderBy('starts_at')
            ->orderBy('id')
            ->get(['id', 'organizer_id', 'title', 'starts_at', 'status', 'capacity', 'seats_available']);

        [$past, $upcoming] = $events->partition(fn (Event $event): bool => $event->hasStarted());

        return [
            'upcoming' => $upcoming->map(fn (Event $event): array => $this->row($event, $organizer))->values()->all(),
            'past' => $past->reverse()->map(fn (Event $event): array => $this->row($event, $organizer))->values()->all(),
        ];
    }

    /**
     * Get the data of one row of the table.
     *
     * @return array{id: int, title: string, starts_at: string, status: string, capacity: int, seats_booked: int, can_update: bool}
     */
    private function row(Event $event, User $organizer): array
    {
        return [
            'id' => $event->id,
            'title' => $event->title,
            'starts_at' => $event->starts_at->toIso8601String(),
            'status' => $event->status->value,
            'capacity' => $event->capacity,
            'seats_booked' => $event->seatsBooked(),
            'can_update' => $organizer->can('update', $event),
        ];
    }
}
