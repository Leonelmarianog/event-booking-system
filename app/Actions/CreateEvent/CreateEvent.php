<?php

namespace App\Actions\CreateEvent;

use App\Enums\EventStatus;
use App\Exceptions\Domain\AccountDeleted;
use App\Models\Event;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class CreateEvent
{
    /**
     * Create a draft event for the organizer (BR-E1). All seats are available. The Action
     * locks the user row first, so it waits for a running account delete and then
     * refuses (BR-U5).
     *
     * @param  array{title: string, description: string, venue: string, starts_at: CarbonImmutable, capacity: int}  $attributes
     *
     * @throws AccountDeleted
     */
    public function handle(User $organizer, array $attributes): Event
    {
        return DB::transaction(function () use ($organizer, $attributes): Event {
            $organizer = User::query()->lockForUpdate()->findOrFail($organizer->id);
            $organizer->ensureNotAnonymized();

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
        });
    }
}
