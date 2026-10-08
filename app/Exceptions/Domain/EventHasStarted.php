<?php

namespace App\Exceptions\Domain;

/**
 * The event has started, so the change is not allowed.
 */
class EventHasStarted extends DomainException
{
    /**
     * BR-E10: only an event that has not started can be published.
     */
    public static function cannotPublish(): self
    {
        return new self(__('The event has started, so it cannot be published.'));
    }

    /**
     * BR-E12: only an event that has not started can be cancelled.
     */
    public static function cannotCancel(): self
    {
        return new self(__('The event has started, so it cannot be cancelled.'));
    }

    /**
     * BR-B10: a booking can be cancelled only before the event starts.
     */
    public static function cannotCancelBooking(): self
    {
        return new self(__('The event has started, so the booking cannot be cancelled.'));
    }
}
