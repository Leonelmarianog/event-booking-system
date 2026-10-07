<?php

namespace App\Exceptions\Domain;

/**
 * BR-B4: the user already has a confirmed booking for the event.
 */
class AlreadyBooked extends DomainException
{
    public function __construct()
    {
        parent::__construct(__('You already have a booking for this event.'));
    }
}
