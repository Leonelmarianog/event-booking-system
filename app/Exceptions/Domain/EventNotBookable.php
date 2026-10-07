<?php

namespace App\Exceptions\Domain;

/**
 * The event cannot be booked by this user (BR-B2, BR-B3).
 */
class EventNotBookable extends DomainException
{
    /**
     * BR-B2: only a published event can be booked.
     */
    public static function notPublished(): self
    {
        return new self(__('Only a published event can be booked.'));
    }

    /**
     * BR-B2: only an event that has not started can be booked.
     */
    public static function hasStarted(): self
    {
        return new self(__('The event has started, so it cannot be booked.'));
    }

    /**
     * BR-B3: the organizer cannot book their own event.
     */
    public static function ownEvent(): self
    {
        return new self(__('You cannot book your own event.'));
    }
}
