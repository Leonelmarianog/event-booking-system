<?php

namespace App\Actions\CreateEvent;

use App\Enums\EventStatus;
use App\Models\Event;
use App\Models\User;
use Carbon\CarbonImmutable;

class CreateEvent
{
    /**
     * Create a draft event for the organizer (BR-E1). All seats are available.
     *
     * @param  array{title: string, description: string, venue: string, starts_at: CarbonImmutable, capacity: int}  $attributes
     */
    public function handle(User $organizer, array $attributes): Event
    {
        $event = new Event;
        $event->organizer_id = $organizer->id;
        $event->title = $attributes['title'];
        $event->description = $attributes['description'];
        $event->venue = $attributes['venue'];
        $event->starts_at = $attributes['starts_at'];
        $event->capacity = $attributes['capacity'];
        $event->seats_available = $attributes['capacity'];
        $event->status = EventStatus::Draft;
        $event->save();

        return $event;
    }
}
