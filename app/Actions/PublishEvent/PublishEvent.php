<?php

namespace App\Actions\PublishEvent;

use App\Exceptions\Domain\AccountDeleted;
use App\Exceptions\Domain\EventHasStarted;
use App\Exceptions\Domain\InvalidStateTransition;
use App\Models\Event;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class PublishEvent
{
    /**
     * Publish the event (BR-E10). After this, everyone can see it (BR-E14). The Action
     * locks the organizer's user row first, so it waits for a running account delete and
     * then refuses (BR-U5). Then it locks the event row.
     *
     * @throws AccountDeleted
     * @throws InvalidStateTransition
     * @throws EventHasStarted
     */
    public function handle(Event $event): Event
    {
        return DB::transaction(function () use ($event): Event {
            User::query()->lockForUpdate()->findOrFail($event->organizer_id)->ensureNotAnonymized();

            $event = Event::query()->lockForUpdate()->findOrFail($event->id);
            $event->publish();
            $event->save();

            return $event;
        });
    }
}
