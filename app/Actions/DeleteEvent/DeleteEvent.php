<?php

namespace App\Actions\DeleteEvent;

use App\Exceptions\Domain\InvalidStateTransition;
use App\Models\Event;

class DeleteEvent
{
    /**
     * Delete a draft event (BR-E17). A draft has no bookings, so no other rows depend
     * on it.
     *
     * @throws InvalidStateTransition
     */
    public function handle(Event $event): void
    {
        $event->ensureCanBeDeleted();
        $event->delete();
    }
}
