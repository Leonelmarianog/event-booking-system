<?php

namespace App\Policies;

use App\Models\Event;
use App\Models\User;

class EventPolicy
{
    /**
     * BR-E14, BR-E15, BR-E16 and BR-A4. Attendees of a cancelled event come in M4.
     */
    public function view(?User $user, Event $event): bool
    {
        if ($event->isPublished()) {
            return true;
        }

        if ($user === null) {
            return false;
        }

        return $user->is_admin || $event->isOrganizedBy($user);
    }

    /**
     * BR-E4, BR-E5 and BR-A3.
     */
    public function update(User $user, Event $event): bool
    {
        return $event->isOrganizedBy($user)
            && ! $event->isCancelled()
            && ! $event->hasStarted();
    }

    /**
     * BR-E9 and BR-A3. The model checks the state of the event (BR-E10).
     */
    public function publish(User $user, Event $event): bool
    {
        return $event->isOrganizedBy($user);
    }

    /**
     * BR-E11 and BR-A1. The model checks the state of the event (BR-E12).
     */
    public function cancel(User $user, Event $event): bool
    {
        return $user->is_admin || $event->isOrganizedBy($user);
    }

    /**
     * BR-E17.
     */
    public function delete(User $user, Event $event): bool
    {
        return $event->isOrganizedBy($user) && $event->isDraft();
    }
}
