<?php

namespace App\Actions\UpdateEvent;

use App\Exceptions\Domain\CapacityBelowBookedSeats;
use App\Models\Event;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class UpdateEvent
{
    /**
     * Update the event. The row lock keeps the capacity correct when bookings change the
     * available seats at the same time.
     *
     * @param  array{title: string, description: string, venue: string, starts_at: CarbonImmutable, capacity: int}  $attributes
     *
     * @throws CapacityBelowBookedSeats
     */
    public function handle(Event $event, array $attributes): Event
    {
        return DB::transaction(function () use ($event, $attributes): Event {
            $event = Event::query()->lockForUpdate()->findOrFail($event->id);

            $event->title = $attributes['title'];
            $event->description = $attributes['description'];
            $event->venue = $attributes['venue'];
            $event->starts_at = $attributes['starts_at'];
            $event->changeCapacity($attributes['capacity']);
            $event->save();

            return $event;
        });
    }
}
