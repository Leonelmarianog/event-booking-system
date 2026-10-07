<?php

namespace App\Actions\GetUpcomingEvents;

use App\Models\Event;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class GetUpcomingEvents
{
    /**
     * Get one page of the upcoming published events, earliest first (BR-E14). The page
     * number comes from the `page` query parameter.
     *
     * @return LengthAwarePaginator<int, array{id: int, title: string, starts_at: string, venue: string, seats_available: int}>
     */
    public function handle(): LengthAwarePaginator
    {
        return Event::query()
            ->published()
            ->upcoming()
            ->orderBy('starts_at')
            ->orderBy('id')
            ->select(['id', 'title', 'starts_at', 'venue', 'seats_available'])
            ->paginate(12)
            ->withQueryString()
            ->through(fn (Event $event): array => [
                'id' => $event->id,
                'title' => $event->title,
                'starts_at' => $event->starts_at->toIso8601String(),
                'venue' => $event->venue,
                'seats_available' => $event->seats_available,
            ]);
    }
}
