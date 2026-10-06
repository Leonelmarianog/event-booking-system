<?php

namespace App\Actions\GetEvent;

use App\Models\Event;

class GetEvent
{
    /**
     * Get the data for the page of one event.
     *
     * @return array{id: int, title: string, description: string, venue: string, starts_at: string, capacity: int, seats_available: int, status: string, organizer_name: string}
     */
    public function handle(Event $event): array
    {
        $event->loadMissing('organizer:id,name');

        return [
            'id' => $event->id,
            'title' => $event->title,
            'description' => $event->description,
            'venue' => $event->venue,
            'starts_at' => $event->starts_at->toIso8601String(),
            'capacity' => $event->capacity,
            'seats_available' => $event->seats_available,
            'status' => $event->status->value,
            'organizer_name' => $event->organizer->name,
        ];
    }
}
