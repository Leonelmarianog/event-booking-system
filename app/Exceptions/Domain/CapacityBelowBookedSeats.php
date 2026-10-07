<?php

namespace App\Exceptions\Domain;

/**
 * BR-E6: the new capacity is less than the booked seats.
 */
class CapacityBelowBookedSeats extends DomainException
{
    public function __construct(int $seatsBooked)
    {
        parent::__construct(__('The capacity cannot be less than the :count booked seats.', [
            'count' => $seatsBooked,
        ]));
    }

    /**
     * The error belongs to the capacity field.
     */
    public function field(): ?string
    {
        return 'capacity';
    }
}
