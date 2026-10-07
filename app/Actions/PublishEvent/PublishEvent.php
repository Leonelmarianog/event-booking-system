<?php

namespace App\Actions\PublishEvent;

use App\Exceptions\Domain\EventHasStarted;
use App\Exceptions\Domain\InvalidStateTransition;
use App\Models\Event;

class PublishEvent
{
    /**
     * Publish the event (BR-E10). After this, everyone can see it (BR-E14).
     *
     * @throws InvalidStateTransition
     * @throws EventHasStarted
     */
    public function handle(Event $event): Event
    {
        $event->publish();
        $event->save();

        return $event;
    }
}
