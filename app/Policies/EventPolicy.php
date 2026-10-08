<?php

namespace App\Policies;

use App\Models\Event;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class EventPolicy
{
    /**
     * BR-E14, BR-E15, BR-E16 and BR-A4. A person who cannot see the event gets a 404, so
     * that hidden events stay unknown.
     */
    public function view(?User $user, Event $event): Response
    {
        if ($event->isPublished()) {
            return Response::allow();
        }

        if ($user === null) {
            return Response::denyAsNotFound();
        }

        if ($user->is_admin || $event->isOrganizedBy($user)) {
            return Response::allow();
        }

        if ($event->isCancelled() && $event->hasBookingBy($user)) {
            return Response::allow();
        }

        return Response::denyAsNotFound();
    }

    /**
     * BR-E4, BR-E5 and BR-A3. A person who cannot see the event gets a 404, so that
     * hidden events stay unknown.
     */
    public function update(User $user, Event $event): Response
    {
        if ($this->view($user, $event)->denied()) {
            return Response::denyAsNotFound();
        }

        if ($event->isOrganizedBy($user) && ! $event->isCancelled() && ! $event->hasStarted()) {
            return Response::allow();
        }

        return Response::deny();
    }

    /**
     * BR-E9 and BR-A3. The model checks the state of the event (BR-E10). A person who
     * cannot see the event gets a 404, so that hidden events stay unknown.
     */
    public function publish(User $user, Event $event): Response
    {
        if ($this->view($user, $event)->denied()) {
            return Response::denyAsNotFound();
        }

        return $event->isOrganizedBy($user) ? Response::allow() : Response::deny();
    }

    /**
     * BR-E11 and BR-A1. The model checks the state of the event (BR-E12). A person who
     * cannot see the event gets a 404, so that hidden events stay unknown.
     */
    public function cancel(User $user, Event $event): Response
    {
        if ($this->view($user, $event)->denied()) {
            return Response::denyAsNotFound();
        }

        return $user->is_admin || $event->isOrganizedBy($user) ? Response::allow() : Response::deny();
    }

    /**
     * BR-A2. A person who cannot see the event gets a 404, so that hidden events stay
     * unknown.
     */
    public function viewAttendees(User $user, Event $event): Response
    {
        if ($this->view($user, $event)->denied()) {
            return Response::denyAsNotFound();
        }

        return $user->is_admin || $event->isOrganizedBy($user) ? Response::allow() : Response::deny();
    }

    /**
     * BR-E17. A person who cannot see the event gets a 404, so that hidden events stay
     * unknown. The model checks the state again before the delete.
     */
    public function delete(User $user, Event $event): Response
    {
        if ($this->view($user, $event)->denied()) {
            return Response::denyAsNotFound();
        }

        return $event->isOrganizedBy($user) && $event->canBeDeleted()
            ? Response::allow()
            : Response::deny();
    }
}
