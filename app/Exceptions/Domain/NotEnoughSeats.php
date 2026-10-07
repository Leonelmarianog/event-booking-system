<?php

namespace App\Exceptions\Domain;

/**
 * BR-B2 and BR-B6: the quantity is more than the available seats.
 */
class NotEnoughSeats extends DomainException
{
    public function __construct(int $seatsAvailable)
    {
        parent::__construct($seatsAvailable === 0
            ? __('The event is sold out.')
            : trans_choice('{1} Only 1 seat is left.|[2,*] Only :count seats are left.', $seatsAvailable));
    }

    /**
     * The error belongs to the quantity field.
     */
    public function field(): ?string
    {
        return 'quantity';
    }
}
