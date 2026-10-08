<?php

namespace App\Exceptions\Domain;

/**
 * The account cannot be deleted while the user takes part in an event that has not
 * started.
 */
class AccountCannotBeDeleted extends DomainException
{
    /**
     * BR-U1: the user organizes a published event that has not started.
     */
    public static function organizesUpcomingEvent(): self
    {
        return new self(__('You organize a published event that has not started. Cancel the event before you delete your account.'));
    }

    /**
     * BR-U2: the user has a confirmed booking for an event that has not started.
     */
    public static function holdsUpcomingBooking(): self
    {
        return new self(__('You have a booking for an event that has not started. Cancel the booking before you delete your account.'));
    }
}
